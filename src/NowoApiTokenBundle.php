<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle;

use Nowo\ApiTokenBundle\DependencyInjection\Compiler\RequireDoctrinePass;
use Nowo\ApiTokenBundle\DependencyInjection\NowoApiTokenExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Personal API tokens for Symfony: hashed lookup tokens with per-module grants, a tenant reach, mandatory expiry, revocation, rate limits and audit events, one Bearer authenticator for every API and a UiKit account and operator UI.
 *
 * S1 core: token store, issuer, revoker, query and authenticator services, Doctrine mapping and commands, all registered
 * only while `nowo_api_token.enabled` is true. The firewall, rate limits, UI, bridges and HTTP API follow in S2 to S5
 * (see docs/ROADMAP.md).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoApiTokenBundle extends Bundle
{
    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoApiTokenBundle';

    /**
     * Registers the compiler passes.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new RequireDoctrinePass());
    }

    /**
     * The extension alias is `nowo_api_token` (not the default derived from the class name).
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoApiTokenExtension();
        }

        return $this->extension;
    }
}
