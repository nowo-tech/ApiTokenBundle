<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\ApiTokenBundle\Contract\ApiTokenIssuerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenPolicyInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenQueryInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenRateLimiterInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenRevokerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenSettingsProviderInterface;
use Nowo\ApiTokenBundle\Contract\Exception\ApiTokenIssueRefusedException;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssuedToken;
use Nowo\ApiTokenBundle\Contract\IssueRequest;
use Nowo\ApiTokenBundle\Contract\ModuleDefinition;
use Nowo\ApiTokenBundle\Contract\ModuleRegistry;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\ReachProviderInterface;
use Nowo\ApiTokenBundle\Contract\RevocationReason;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Infrastructure\Security\ReauthSession;
use Nowo\ApiTokenBundle\Presentation\Form\CsrfActionType;
use Nowo\ApiTokenBundle\Presentation\Form\NewTokenType;
use Nowo\ApiTokenBundle\Presentation\Form\RevokeAllType;
use Nowo\ApiTokenBundle\Presentation\View\AccountPages;
use Nowo\UiKitBundle\Dialog\RemoteDialogResponder;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function array_filter;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * Account → Security → API tokens (contract §8.1, §6.10). Every action needs `IS_AUTHENTICATED_FULLY`; issuing and
 * *Revoke all* also need a fresh re-auth (bound to the session). The secret appears exactly once, in the body of the
 * POST that created it: never in a flash, the session, a log or a redirect; the form's CSRF token is renewed after the
 * issue, so a reload cannot issue a second token. The framing and `no-store` headers come from the response listener.
 */
final readonly class AccountTokensController
{
    /** CSRF id of the row action. */
    public const CSRF_REVOKE = 'nowo_api_token_revoke';

    private const DOMAIN = AccountPages::DOMAIN;

    private ClockInterface $clock;

    /**
     * @param list<int> $expiryChoices `expiry.choices`
     * @param int $expiryDefault `expiry.default_days`
     * @param array{list: string|null, created: string|null} $templates `web_ui.templates`
     */
    public function __construct(
        private ApiTokenIssuerInterface $issuer,
        private ApiTokenRevokerInterface $revoker,
        private ApiTokenQueryInterface $query,
        private ModuleRegistry $registry,
        private ApiTokenPolicyInterface $policy,
        private ApiTokenSettingsProviderInterface $settings,
        private ReachProviderInterface $reachProvider,
        private OwnerResolverInterface $owners,
        private ApiTokenRateLimiterInterface $limiter,
        private ReauthSession $reauth,
        private FormFactoryInterface $forms,
        private Environment $twig,
        private UrlGeneratorInterface $router,
        private TokenStorageInterface $tokenStorage,
        private AuthorizationCheckerInterface $authorization,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private RemoteDialogResponder $dialog,
        private string $tokenPrefix,
        private bool $allowEverything = true,
        private array $expiryChoices = [7, 30, 90, 365],
        private int $expiryDefault = 90,
        private ?string $layout = null,
        private array $templates = ['list' => null, 'created' => null],
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * The person's tokens.
     */
    #[Route('/', name: 'nowo_api_token_account', methods: ['GET'])]
    public function index(): Response
    {
        [, $owner] = $this->person();

        return $this->listPage($owner, null);
    }

    /**
     * *New token*: the form (page or dialog fragment), then the secret once.
     */
    #[Route('/new', name: 'nowo_api_token_account_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        [$user, $owner] = $this->person();
        $form           = $this->newForm($request, $user, $owner);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->passReauth($request, $user, $form)) {
            $issued = $this->issue($form, $owner);
            if ($issued instanceof IssuedToken) {
                // A reload of this POST answer must not issue a second token (the secret is never kept to show again).
                $this->csrf->refreshToken('nowo_api_token_new');

                return $this->dialog->isDialog($request)
                    ? $this->dialog->fragment($this->render('@NowoApiTokenBundle/account/_created.html.twig', ['issued' => $issued, 'secret' => $issued->secret(), 'list_href' => $this->url('nowo_api_token_account')]))
                    : $this->listPage($owner, $issued);
            }
        }
        $template = $this->dialog->template('@NowoApiTokenBundle/account/new.html.twig', '@NowoApiTokenBundle/account/_new_fragment.html.twig', $request);
        $response = $this->render($template, ['page' => $this->pages()->newToken($form->createView())], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);

        return $this->dialog->isDialog($request) ? $this->dialog->fragment($response) : $response;
    }

    /**
     * Revokes one of the person's tokens (POST + CSRF). Another person's token id answers 404.
     */
    #[Route('/{id}/revoke', name: 'nowo_api_token_account_revoke', requirements: ['id' => self::ID], methods: ['POST'])]
    public function revoke(Request $request, string $id): RedirectResponse
    {
        [, $owner] = $this->person();
        $this->assertCsrf($request);
        if (!$this->revoker->revoke($owner, new TokenId($id), RevocationReason::Owner)) {
            throw new NotFoundHttpException('API token not found.');
        }
        $this->flash($request, 'success', 'flash.revoked');

        return new RedirectResponse($this->url('nowo_api_token_account'), Response::HTTP_SEE_OTHER);
    }

    /**
     * *Revoke all*: re-auth + confirmation tick (page or dialog fragment).
     */
    #[Route('/revoke-all', name: 'nowo_api_token_account_revoke_all', methods: ['GET', 'POST'])]
    public function revokeAll(Request $request): Response
    {
        [$user, $owner] = $this->person();
        $form           = $this->forms->create(RevokeAllType::class, null, $this->reauthOptions($request, $user));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->passReauth($request, $user, $form)) {
            $count = $this->revoker->revokeAll($owner, RevocationReason::OwnerAll);
            $this->reauth->forget($request);
            $this->flash($request, 'success', 'flash.revoked_all', ['%count%' => $count]);

            return $this->dialog->isDialog($request)
                ? RemoteDialogResponder::dialogRedirect($this->url('nowo_api_token_account'))
                : new RedirectResponse($this->url('nowo_api_token_account'), Response::HTTP_SEE_OTHER);
        }
        $template = $this->dialog->template('@NowoApiTokenBundle/account/revoke_all.html.twig', '@NowoApiTokenBundle/account/_revoke_all_fragment.html.twig', $request);
        $response = $this->render($template, ['page' => $this->pages()->revokeAll($form->createView(), count($this->query->ofOwner($owner)))], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);

        return $this->dialog->isDialog($request) ? $this->dialog->fragment($response) : $response;
    }

    /** Lowercase UUID of a token. */
    public const ID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * The signed-in person and their owner reference. Remembered-me sessions are refused (contract §6.11).
     *
     * @return array{object, OwnerRef}
     */
    private function person(): array
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface || !$this->authorization->isGranted('IS_AUTHENTICATED_FULLY')) {
            throw new AccessDeniedException('Sign in again to manage your API tokens.');
        }

        return [$user, $this->owners->ownerOf($user)];
    }

    private function listPage(OwnerRef $owner, ?IssuedToken $issued): Response
    {
        $revoke = $this->forms->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => self::CSRF_REVOKE, 'action' => '#'])->createView();

        return $this->render($this->templates['list'] ?? '@NowoApiTokenBundle/account/list.html.twig', [
            'page'             => $this->pages()->list($this->query->ofOwner($owner), $revoke, $this->now()),
            'issued'           => $issued,
            'secret'           => $issued?->secret(),
            'created_template' => $this->templates['created'] ?? '@NowoApiTokenBundle/account/_created.html.twig',
            'list_href'        => $this->url('nowo_api_token_account'),
        ]);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function newForm(Request $request, object $user, OwnerRef $owner): FormInterface
    {
        $allowed = $this->policy->modulesFor($owner);
        $enabled = $this->settings->modulesEnabled();
        $modules = array_values(array_filter(
            $this->registry->all(),
            static fn (ModuleDefinition $module): bool => in_array($module->key, $allowed, true) && ($enabled[$module->key] ?? true),
        ));
        $max     = $this->policy->maxTtlDays($owner);
        $choices = array_values(array_filter($this->expiryChoices, static fn (int $days): bool => $days <= $max));
        if ($choices === []) {
            $choices = [$max];
        }

        return $this->forms->create(NewTokenType::class, null, [
            'modules'          => $modules,
            'reach_choices'    => $this->reachProvider->choicesFor($owner),
            'allow_everything' => $this->allowEverything,
            'expiry_choices'   => $choices,
            'expiry_default'   => in_array($this->expiryDefault, $choices, true) ? $this->expiryDefault : $choices[count($choices) - 1],
        ] + $this->reauthOptions($request, $user));
    }

    /**
     * @return array{reauth: bool, reauth_form: string|null}
     */
    private function reauthOptions(Request $request, object $user): array
    {
        return ['reauth' => !$this->reauth->isFresh($request, $user), 'reauth_form' => $this->reauth->form()];
    }

    /**
     * Fresh already, or the submitted step passes (counted against the session's attempts).
     *
     * @param FormInterface<mixed> $form
     */
    private function passReauth(Request $request, object $user, FormInterface $form): bool
    {
        if ($this->reauth->isFresh($request, $user)) {
            return true;
        }
        // Not fresh: the form of this request was built with the step (one of the two fields).
        $field            = $form->has('reauth') ? 'reauth' : 'reauth_password';
        $value            = $form->get($field)->getData();
        $data             = $field === 'reauth' ? (is_array($value) ? $value : []) : ['password' => $value];
        [$state, $passed] = $this->reauth->attempt($request, $user, $data);
        if (!$state->accepted) {
            $form->get($field)->addError(new FormError($this->translator->trans('error.reauth_limited', ['%seconds%' => $state->retryAfterSeconds($this->now())], self::DOMAIN)));

            return false;
        }
        if (!$passed) {
            $form->get($field)->addError(new FormError($this->translator->trans('error.reauth_failed', [], self::DOMAIN)));
        }

        return $passed;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function issue(FormInterface $form, OwnerRef $owner): ?IssuedToken
    {
        $issue = $this->limiter->consumeIssue($owner);
        if (!$issue->accepted) {
            $form->addError(new FormError($this->translator->trans('error.issue_limited', [], self::DOMAIN)));

            return null;
        }
        /** @var array<string, mixed> $data */
        $data   = $form->getData() ?? [];
        $levels = [];
        $flags  = [];
        foreach ($this->registry->all() as $module) {
            $level = $data['module_' . $module->key] ?? null;
            if (is_string($level) && $level !== '') {
                $levels[$module->key] = $level;
            }
            foreach ($module->flags as $flag) {
                if (($data['flag_' . $module->key . '_' . $flag] ?? false) === true) {
                    $flags[$module->key][] = $flag;
                }
            }
        }
        $days = $data['expires_in'] ?? null;
        try {
            $reach = ($data['reach'] ?? null) === NewTokenType::REACH_EVERYTHING
                ? Reach::everything()
                : Reach::limitedTo(array_values(array_filter(is_array($data['places'] ?? null) ? $data['places'] : [], 'is_string')));

            return $this->issuer->issue(new IssueRequest($owner, is_string($data['name'] ?? null) ? $data['name'] : '', new Grants($levels, $flags), $reach, is_int($days) ? $days : 0, 'account'));
        } catch (ApiTokenIssueRefusedException $exception) {
            $form->addError(new FormError($this->translator->trans('error.refused.' . $exception->reason, [], self::DOMAIN)));
        } catch (InvalidApiTokenArgumentException) {
            $form->addError(new FormError($this->translator->trans('error.invalid', [], self::DOMAIN)));
        }

        return null;
    }

    private function assertCsrf(Request $request): void
    {
        $form = $this->forms->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => self::CSRF_REVOKE]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /**
     * @param array<string, int|string> $params
     */
    private function flash(Request $request, string $type, string $key, array $params = []): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof SessionInterface && method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add($type, $this->translator->trans($key, $params, self::DOMAIN));
        }
    }

    private function pages(): AccountPages
    {
        return new AccountPages(fn (string $route, array $params = []): string => $this->url($route, $params), $this->tokenPrefix, $this->registry->keys(), $this->layout);
    }

    /**
     * @param array<string, string> $params
     */
    private function url(string $route, array $params = []): string
    {
        return $this->router->generate($route, $params);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context, int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render($template, $context), $status);
    }

    private function now(): DateTimeImmutable
    {
        // @igor-ignore - PSR clock read; DateTimeImmutable returns a new value, nothing is mutated.
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }
}
