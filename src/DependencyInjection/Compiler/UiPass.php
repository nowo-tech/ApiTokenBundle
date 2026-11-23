<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ApiTokenBundle\DependencyInjection\NowoApiTokenExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_array;
use function sprintf;

/**
 * Build-time checks of the S3 pages (contract §6.10, `REQ-UI-002`), only while the bundle and a UI area are enabled:
 *
 * - Twig, the form factory and the router exist (TwigBundle, FrameworkBundle `form`);
 * - the account pages need SecurityBundle and CSRF protection (`framework.csrf_protection`);
 * - the operator page needs SecurityBundle unless `admin_ui.security.allow_unauthenticated` (demo only);
 * - the default password re-auth gate needs a password hasher (`security.password_hashers`), else set `reauth.gate`
 *   (SSO hosts) or `reauth.enabled: false`.
 */
final class UiPass implements CompilerPassInterface
{
    /**
     * Runs the checks.
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->getParameter(NowoApiTokenExtension::ALIAS . '.enabled') !== true || !$container->hasParameter(NowoApiTokenExtension::ALIAS . '.ui')) {
            return;
        }
        $ui = $container->getParameter(NowoApiTokenExtension::ALIAS . '.ui');
        if (!is_array($ui) || ($ui['web'] !== true && $ui['admin'] !== true)) {
            return;
        }
        foreach (['twig' => 'TwigBundle', 'form.factory' => 'FrameworkBundle "form"', 'router' => 'FrameworkBundle "router"'] as $id => $what) {
            $this->need($container, $id, sprintf('nowo_api_token: web_ui / admin_ui need %s (service "%s").', $what, $id));
        }
        $security = $container->has('security.token_storage') && $container->has('security.authorization_checker');
        if ($ui['web'] === true) {
            if (!$security) {
                throw new LogicException('nowo_api_token.web_ui needs SecurityBundle: the account pages are for signed-in people (IS_AUTHENTICATED_FULLY).');
            }
            $this->need($container, 'security.csrf.token_manager', 'nowo_api_token.web_ui needs CSRF protection: enable framework.csrf_protection (every POST of the account pages is CSRF-protected).');
            if ($ui['reauth_gate'] === true) {
                $this->need($container, 'security.user_password_hasher', 'nowo_api_token.reauth: the default password gate needs a password hasher (security.password_hashers). Set reauth.gate to your step-up service (SSO hosts), or reauth.enabled: false.');
            }
        }
        if ($ui['admin'] === true && $ui['allow_unauthenticated'] !== true && !$security) {
            throw new LogicException('nowo_api_token.admin_ui needs SecurityBundle (or admin_ui.security.allow_unauthenticated: true, demo only).');
        }
    }

    private function need(ContainerBuilder $container, string $id, string $message): void
    {
        if (!$container->has($id)) {
            throw new LogicException($message);
        }
    }
}
