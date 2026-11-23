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
use Nowo\ApiTokenBundle\Presentation\View\AccountPages;
use Nowo\ApiTokenBundle\Presentation\View\OperatorPages;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Form\FormFactoryInterface;
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
use function trim;

/**
 * The operator page (contract §8.2, `REQ-UI-002`): every token, keyset pages, filters by owner key and lookup id, and
 * *Revoke* (audited with the operator as actor). Every action goes through {@see ApiTokenAdminGuard}. Lists show the
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
     * Every token, newest first.
     */
    #[Route('/', name: 'nowo_api_token_operator', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->guard->operator();
        $filters = $this->forms->createNamed('', OperatorFilterType::class);
        $filters->handleRequest($request);
        $owner  = $filters->isSubmitted() ? $this->ownerFilter($filters->get('owner')->getData()) : null;
        $lookup = $filters->isSubmitted() ? $this->lookupFilter($filters->get('lookup')->getData()) : null;
        $after  = $request->query->get('after');
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
        $filtered = $owner !== null || $lookup !== null;
        $revoke   = $this->forms->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => AccountTokensController::CSRF_REVOKE, 'action' => '#'])->createView();
        $keep     = [];
        foreach (['owner', 'lookup'] as $key) {
            $value = $filters->has($key) ? $filters->get($key)->getData() : null;
            if (is_string($value) && trim($value) !== '') {
                $keep[$key] = trim($value);
            }
        }

        return new Response($this->twig->render('@NowoApiTokenBundle/operator/list.html.twig', [
            'page'      => $this->pages()->list($tokens, $filters->createView(), $filtered, $revoke, $this->now()),
            'next_href' => $next === null ? null : $this->router->generate('nowo_api_token_operator', $keep + ['after' => $next]),
        ]));
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
    private function ownerFilter(mixed $value): OwnerRef|false|null
    {
        if (!is_string($value) || trim($value) === '') {
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
     * A lookup id, the visible handle (`<prefix><lookup id>`) or a whole token (only its lookup id is read; the value is
     * never stored or logged). '' → no filter (null); anything else → matches nothing (false).
     */
    private function lookupFilter(mixed $value): LookupId|false|null
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        if (preg_match('/^(?:' . preg_quote($this->tokenPrefix, '/') . ')?([a-z2-7]{12})(?:_.*)?$/Ds', trim($value), $match) !== 1) {
            return false;
        }

        return new LookupId($match[1]);
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
