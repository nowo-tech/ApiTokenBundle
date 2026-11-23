<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\ApiTokenBundle\Contract\ApiTokenQueryInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenRevokerInterface;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\ApiTokenBundle\Contract\Event\ApiTokenAccessCheckEvent;
use Nowo\ApiTokenBundle\Contract\Exception\InvalidApiTokenArgumentException;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\ModuleRegistry;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\OwnerResolverInterface;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Infrastructure\Security\ApiTokenAdminGuard;
use Nowo\ApiTokenBundle\Presentation\Form\CsrfActionType;
use Nowo\ApiTokenBundle\Presentation\Form\OperatorFilterType;
use Nowo\ApiTokenBundle\Presentation\Form\OperatorLookupType;
use Nowo\ApiTokenBundle\Presentation\View\AccountPages;
use Nowo\ApiTokenBundle\Presentation\View\OperatorPages;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

use function explode;
use function is_string;
use function mb_substr;
use function preg_match;
use function preg_quote;
use function str_contains;
use function strlen;
use function trim;

/**
 * The operator page (contract §8.2, `REQ-UI-002`): every token, keyset pages, filters by owner key and lookup id, a
 * POST search that turns a pasted leaked token into its lookup id (the secret never reaches a URL, a log or the page),
 * and *Revoke* (audited with the operator as actor). Every action goes through {@see ApiTokenAdminGuard}. Lists show the
 * owner key, name, handle, module names, dates; never the grants detail beyond module levels, never the reach entries.
 */
final readonly class OperatorTokensController
{
    /** Rows per page. */
    public const PAGE_SIZE = 50;

    private ClockInterface $clock;

    /**
     * Wires the controller.
     */
    public function __construct(
        private ApiTokenQueryInterface $query,
        private ApiTokenRevokerInterface $revoker,
        private ApiTokenAdminGuard $guard,
        private OwnerResolverInterface $owners,
        private ModuleRegistry $registry,
        private FormFactoryInterface $forms,
        private Environment $twig,
        private UrlGeneratorInterface $router,
        private string $tokenPrefix,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Every token, newest first. The `lookup` filter takes a lookup id or a handle only: a longer value (a pasted
     * token) matches nothing and is not printed back into the field (audit ZAB-S3-M1).
     */
    #[Route('/', name: 'nowo_api_token_operator', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->guard->operator();

        return $this->listResponse($request, $this->searchForm(), Response::HTTP_OK);
    }

    /**
     * *Find a leaked token* (POST + CSRF): reads the lookup id of the pasted token, handle or lookup id and redirects to
     * `?lookup=<lookup id>`, so the secret never reaches a URL. A refused value answers the list again with the error,
     * never printing the value back.
     */
    #[Route('/lookup', name: 'nowo_api_token_operator_lookup', methods: ['POST'])]
    public function lookup(Request $request): Response
    {
        $this->guard->operator();
        $search = $this->searchForm();
        $search->handleRequest($request);
        if ($search->isSubmitted() && $search->isValid() && is_string($value = $search->get('token')->getData()) && preg_match(OperatorLookupType::pattern($this->tokenPrefix), $value, $match) === 1) {
            return new RedirectResponse($this->router->generate('nowo_api_token_operator', ['lookup' => $match[1]]), Response::HTTP_SEE_OTHER);
        }

        return $this->listResponse(new Request(), $search, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Revokes any token (POST + CSRF, per-token access event, audited with the operator as actor).
     */
    #[Route('/{id}/revoke', name: 'nowo_api_token_operator_revoke', requirements: ['id' => AccountTokensController::ID], methods: ['POST'])]
    public function revoke(Request $request, string $id): RedirectResponse
    {
        $operator = $this->guard->operator();
        $form     = $this->forms->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => AccountTokensController::CSRF_REVOKE]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $token = $this->query->find(new TokenId($id)) ?? throw new NotFoundHttpException('API token not found.');
        $this->guard->assertCan($operator, $token, ApiTokenAccessCheckEvent::ACTION_REVOKE);
        $this->revoker->revokeAsOperator($token->id, $this->actor($operator));

        return new RedirectResponse($this->router->generate('nowo_api_token_operator'), Response::HTTP_SEE_OTHER);
    }

    /**
     * `type:id` → owner; '' → no filter (null); anything else → matches nothing (false).
     */
    private function ownerFilter(string $value): OwnerRef|false|null
    {
        if (trim($value) === '') {
            return null;
        }
        if (!str_contains($value, ':')) {
            return false;
        }
        [$type, $id] = explode(':', trim($value), 2);
        try {
            return new OwnerRef($type, $id);
        } catch (InvalidApiTokenArgumentException) {
            return false;
        }
    }

    /**
     * A lookup id or a handle (`<prefix><lookup id>`). '' → no filter (null); anything else → matches nothing (false).
     */
    private function lookupFilter(string $value): LookupId|false|null
    {
        if (trim($value) === '') {
            return null;
        }
        if (preg_match('/^(?:' . preg_quote($this->tokenPrefix, '/') . ')?([a-z2-7]{12})$/D', trim($value), $match) !== 1) {
            return false;
        }

        return new LookupId($match[1]);
    }

    /**
     * The list with the GET filters of `$request` (owner key, lookup id or handle, keyset page) and the search form.
     *
     * @param FormInterface<mixed> $search
     */
    private function listResponse(Request $request, FormInterface $search, int $status): Response
    {
        $query  = $request->query->all();
        $values = [];
        foreach (['owner', 'lookup'] as $key) {
            $values[$key] = is_string($query[$key] ?? null) ? $query[$key] : '';
        }
        // A value longer than a handle is a pasted token (or garbage): it matches nothing and is never echoed.
        $pasted = strlen(trim($values['lookup'])) > OperatorFilterType::lookupMaxLength($this->tokenPrefix);
        if ($pasted) {
            $values['lookup'] = '';
        }
        $filters   = $this->forms->createNamed('', OperatorFilterType::class, null, ['token_prefix' => $this->tokenPrefix]);
        $submitted = isset($query['owner']) || isset($query['lookup']);
        if ($submitted) {
            $filters->submit($values);
        }
        $owner  = $submitted ? $this->ownerFilter($values['owner']) : null;
        $lookup = $pasted ? false : ($submitted ? $this->lookupFilter($values['lookup']) : null);
        $after  = $query['after'] ?? null;
        $next   = null;
        if ($lookup !== null) {
            $found  = $lookup === false ? null : $this->query->findByLookupId($lookup);
            $tokens = $found instanceof ApiTokenView && ($owner === null || ($owner !== false && $found->owner->equals($owner))) ? [$found] : [];
        } elseif ($owner === false) {
            $tokens = [];
        } else {
            try {
                $page = $this->query->page(is_string($after) && preg_match('/^' . AccountTokensController::ID . '$/D', $after) === 1 ? $after : null, self::PAGE_SIZE, $owner);
            } catch (InvalidApiTokenArgumentException) {
                throw new NotFoundHttpException('Unknown page.');
            }
            $tokens = $page->items;
            $next   = $page->nextAfter;
        }
        $revoke = $this->forms->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => AccountTokensController::CSRF_REVOKE, 'action' => '#'])->createView();
        $keep   = [];
        foreach ($values as $key => $value) {
            if (trim($value) !== '') {
                $keep[$key] = trim($value);
            }
        }

        return new Response($this->twig->render('@NowoApiTokenBundle/operator/list.html.twig', [
            'page'      => $this->pages()->list($tokens, $filters->createView(), $owner !== null || $lookup !== null, $revoke, $this->now()),
            'search'    => $search->createView(),
            'next_href' => $next === null ? null : $this->router->generate('nowo_api_token_operator', $keep + ['after' => $next]),
        ]), $status);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function searchForm(): FormInterface
    {
        return $this->forms->create(OperatorLookupType::class, null, ['token_prefix' => $this->tokenPrefix, 'action' => $this->router->generate('nowo_api_token_operator_lookup')]);
    }

    /**
     * The operator as an owner reference (the revoke event's actor): their owner key, else `operator:<user identifier>`;
     * a demo visitor without a user is `operator:anonymous`.
     */
    private function actor(object $operator): OwnerRef
    {
        try {
            return $this->owners->ownerOf($operator);
        } catch (InvalidApiTokenArgumentException) {
            // An operator who is not a token owner type (a separate admin user class).
        }
        $id = $operator instanceof UserInterface ? mb_substr($operator->getUserIdentifier(), 0, 128) : '';

        return new OwnerRef('operator', preg_match('/^[^\x00-\x1F\x7F]+$/u', $id) === 1 ? $id : 'anonymous');
    }

    private function pages(): OperatorPages
    {
        $url = fn (string $route, array $params = []): string => $this->router->generate($route, $params);

        return new OperatorPages($url, new AccountPages($url, $this->tokenPrefix, $this->registry->keys()));
    }

    private function now(): DateTimeImmutable
    {
        // @igor-ignore - PSR clock read; DateTimeImmutable returns a new value, nothing is mutated.
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }
}
