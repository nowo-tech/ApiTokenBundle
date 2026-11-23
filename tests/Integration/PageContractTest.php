<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Integration;

use DateTimeImmutable;
use Nowo\ApiTokenBundle\Contract\ApiTokenView;
use Nowo\ApiTokenBundle\Contract\Grants;
use Nowo\ApiTokenBundle\Contract\IssuedToken;
use Nowo\ApiTokenBundle\Contract\LookupId;
use Nowo\ApiTokenBundle\Contract\ModuleDefinition;
use Nowo\ApiTokenBundle\Contract\OwnerRef;
use Nowo\ApiTokenBundle\Contract\Reach;
use Nowo\ApiTokenBundle\Contract\ReachChoice;
use Nowo\ApiTokenBundle\Contract\TokenId;
use Nowo\ApiTokenBundle\Presentation\Form\CsrfActionType;
use Nowo\ApiTokenBundle\Presentation\Form\NewTokenType;
use Nowo\ApiTokenBundle\Presentation\Form\OperatorFilterType;
use Nowo\ApiTokenBundle\Presentation\Form\RevokeAllType;
use Nowo\ApiTokenBundle\Presentation\View\AccountPages;
use Nowo\ApiTokenBundle\Presentation\View\OperatorPages;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;

use function dirname;
use function http_build_query;
use function str_repeat;
use function substr;

/**
 * UiKit page contract (REQ-UI-003, contract §8.1 "A PageContractTestCase subclass tests the page"): every page template
 * of the bundle renders on the scaffolds with no raw keys, no framework classes outside theme slots and its action
 * forms intact.
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        $forms   = self::forms();
        $url     = static fn (string $route, array $params = []): string => '/' . $route . ($params === [] ? '' : '?' . http_build_query($params));
        $account = new AccountPages($url, 'nwt_', ['tasks', 'wiki', 'mcp']);
        $now     = new DateTimeImmutable('2026-11-12 10:00:00');
        $tokens  = [
            self::token('01935f7e-0000-7000-8000-000000000001', 'CI <deploy>', new Grants(['tasks' => 'read']), Reach::everything(), $now->modify('+80 days'), $now->modify('-2 hours')),
            self::token('01935f7e-0000-7000-8000-000000000002', 'Agent', new Grants(['tasks' => 'full', 'mcp' => 'on'], ['mcp' => ['destructive']]), Reach::limitedTo(['p1', 'p2']), $now->modify('+3 days'), null),
        ];
        $revoke = $forms->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 'nowo_api_token_revoke'])->createView();
        $issued = new IssuedToken($tokens[0], 'nwt_abcdefghijkl_' . str_repeat('Q', 43) . 'AAAAAA');

        yield 'account list' => new PageFixture('@NowoApiTokenBundle/account/list.html.twig', ['page' => $account->list($tokens, $revoke, $now), 'issued' => null, 'secret' => null, 'created_template' => '@NowoApiTokenBundle/account/_created.html.twig', 'list_href' => '/list']);
        yield 'account list (empty)' => new PageFixture('@NowoApiTokenBundle/account/list.html.twig', ['page' => $account->list([], $revoke, $now), 'issued' => null, 'secret' => null, 'created_template' => '@NowoApiTokenBundle/account/_created.html.twig', 'list_href' => '/list']);
        yield 'account list (created)' => new PageFixture('@NowoApiTokenBundle/account/list.html.twig', ['page' => $account->list($tokens, $revoke, $now), 'issued' => $issued, 'secret' => $issued->secret(), 'created_template' => '@NowoApiTokenBundle/account/_created.html.twig', 'list_href' => '/list']);

        $modules = [new ModuleDefinition('tasks', ['my_tasks', 'read', 'tasks', 'full']), new ModuleDefinition('mcp', ['on'], ['destructive'])];
        $new     = $forms->create(NewTokenType::class, null, ['modules' => $modules, 'reach_choices' => [new ReachChoice('p1', 'Project one', 'Projects')], 'expiry_choices' => [7, 30, 90], 'expiry_default' => 30, 'reauth' => true]);
        yield 'new token' => new PageFixture('@NowoApiTokenBundle/account/new.html.twig', ['page' => $account->newToken($new->createView())]);
        $all = $forms->create(RevokeAllType::class, null, ['reauth' => true]);
        yield 'revoke all' => new PageFixture('@NowoApiTokenBundle/account/revoke_all.html.twig', ['page' => $account->revokeAll($all->createView(), 2)]);

        $operator = new OperatorPages($url, $account);
        $filters  = $forms->createNamed('', OperatorFilterType::class)->createView();
        yield 'operator list' => new PageFixture('@NowoApiTokenBundle/operator/list.html.twig', ['page' => $operator->list($tokens, $filters, false, $revoke, $now), 'next_href' => '/next']);
        yield 'operator list (filtered, empty)' => new PageFixture('@NowoApiTokenBundle/operator/list.html.twig', ['page' => $operator->list([], $filters, true, $revoke, $now), 'next_href' => null]);
    }

    protected static function templatePaths(): array
    {
        $views = dirname(__DIR__, 2) . '/src/Resources/views';

        return ['NowoApiTokenBundle' => $views, '!NowoApiTokenBundle' => $views];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 2) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 2) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }

    private static function forms(): FormFactoryInterface
    {
        $csrf = new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'csrf-' . $tokenId);
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return true;
            }
        };

        return Forms::createFormFactoryBuilder()
            ->addExtension(new CsrfExtension($csrf))
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }

    private static function token(string $id, string $name, Grants $grants, Reach $reach, DateTimeImmutable $expires, ?DateTimeImmutable $lastUsed): ApiTokenView
    {
        return new ApiTokenView(new TokenId($id), new LookupId('abcdefghijk' . (substr($id, -1) === '1' ? 'a' : 'b')), new OwnerRef('user', 'alice'), $name, $grants, $reach, new DateTimeImmutable('2026-11-01'), $expires, $lastUsed, 'account');
    }
}
