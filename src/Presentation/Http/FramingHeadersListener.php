<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Presentation\Http;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

use function is_string;
use function str_starts_with;

/**
 * Framing and caching headers of the bundle's pages (contract §6.10, `REQ-AUTH-013`), on every response of a
 * `nowo_api_token_*` route: `X-Frame-Options: DENY`, `frame-ancestors 'none'` as an extra
 * `Content-Security-Policy` header (the host's policy is kept; browsers enforce both), `Cache-Control: no-store, private` and `Referrer-Policy: same-origin`.
 */
final readonly class FramingHeadersListener
{
    /** Route name prefix of the bundle's pages. */
    public const ROUTE_PREFIX = 'nowo_api_token_';

    /** The appended policy. */
    public const FRAME_ANCESTORS = "frame-ancestors 'none'";

    /** Runs late, after the host's own security headers. */
    public const PRIORITY = -256;

    /**
     * Adds the headers to the bundle's pages.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('X-Frame-Options', 'DENY');
        // A second policy header: browsers enforce every policy, so 'none' holds even when the host's own policy has a
        // laxer frame-ancestors, and the host's policy is never replaced.
        $headers->set('Content-Security-Policy', self::FRAME_ANCESTORS, false);
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Referrer-Policy', 'same-origin');
    }
}
