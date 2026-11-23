<?php

declare(strict_types=1);

namespace Nowo\ApiTokenBundle\Contract;

/**
 * Host SPI (autoconfigured, tag `nowo_api_token.reach_filter`, chained by priority): narrows a token's reach on every
 * request, e.g. drops the organisations that forbid tokens today (contract §4.3, §6.5).
 *
 * A filter can only narrow. The bundle intersects each answer with the reach it was given, so an answer that adds
 * entries, or turns a limited reach into *everything*, never widens what the token reaches.
 */
interface ReachFilterInterface
{
    /**
     * The narrowed reach.
     *
     * @param ApiTokenPrincipal $principal the token being authenticated (its `reach` is the stored one)
     * @param Reach $reach the reach after the earlier filters of the chain
     */
    public function filter(ApiTokenPrincipal $principal, Reach $reach): Reach;
}
