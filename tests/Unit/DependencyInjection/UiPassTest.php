<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\ApiTokenBundle\DependencyInjection\Compiler\UiPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Build-time checks of the S3 pages.
 */
final class UiPassTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, bool>, list<string>, string}>
     */
    public static function refusals(): iterable
    {
        $all = ['twig', 'form.factory', 'router', 'security.token_storage', 'security.authorization_checker', 'security.csrf.token_manager', 'security.user_password_hasher'];
        yield 'no twig' => [['web' => true, 'admin' => false, 'allow_unauthenticated' => false, 'reauth_gate' => false], array_values(array_diff($all, ['twig'])), 'TwigBundle'];
        yield 'web without security' => [['web' => true, 'admin' => false, 'allow_unauthenticated' => false, 'reauth_gate' => false], ['twig', 'form.factory', 'router'], 'needs SecurityBundle'];
        yield 'web without csrf' => [['web' => true, 'admin' => false, 'allow_unauthenticated' => false, 'reauth_gate' => false], array_values(array_diff($all, ['security.csrf.token_manager'])), 'CSRF protection'];
        yield 'password gate without hasher' => [['web' => true, 'admin' => false, 'allow_unauthenticated' => false, 'reauth_gate' => true], array_values(array_diff($all, ['security.user_password_hasher'])), 'password hasher'];
        yield 'admin without security' => [['web' => false, 'admin' => true, 'allow_unauthenticated' => false, 'reauth_gate' => false], ['twig', 'form.factory', 'router'], 'admin_ui needs SecurityBundle'];
    }

    /**
     * @param array<string, bool> $ui
     * @param list<string> $services
     */
    #[DataProvider('refusals')]
    public function testMissingPiecesRefuseToBuild(array $ui, array $services, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        (new UiPass())->process($this->container($ui, $services));
    }

    public function testCompleteHostsAndTheDemoModeBuild(): void
    {
        (new UiPass())->process($this->container(['web' => true, 'admin' => true, 'allow_unauthenticated' => false, 'reauth_gate' => true], ['twig', 'form.factory', 'router', 'security.token_storage', 'security.authorization_checker', 'security.csrf.token_manager', 'security.user_password_hasher']));
        (new UiPass())->process($this->container(['web' => false, 'admin' => true, 'allow_unauthenticated' => true, 'reauth_gate' => false], ['twig', 'form.factory', 'router']));
        (new UiPass())->process($this->container(['web' => false, 'admin' => false, 'allow_unauthenticated' => false, 'reauth_gate' => false], []));
        $disabled = new ContainerBuilder();
        $disabled->setParameter('nowo_api_token.enabled', false);
        (new UiPass())->process($disabled);
        $this->addToAssertionCount(4);
    }

    /**
     * @param array<string, bool> $ui
     * @param list<string> $services
     */
    private function container(array $ui, array $services): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_api_token.enabled', true);
        $container->setParameter('nowo_api_token.ui', $ui);
        foreach ($services as $id) {
            $container->register($id, stdClass::class);
        }

        return $container;
    }
}
