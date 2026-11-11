# PSR compliance

Package: `nowo-tech/api-token-bundle` (`symfony-bundle`)

| PSR | Status | Notes |
| --- | --- | --- |
| PSR-1 / PSR-12 Coding style | **Adopted** | PHP-CS-Fixer (`@PSR12` + `@Symfony`), `make cs-check` in CI |
| PSR-4 Autoloading | **Adopted** | `Nowo\ApiTokenBundle\` → `src/` |
| PSR-3 Logger | **Adopted** (S1) | `DbalApiTokenStore`, `AfterCommitQueue`, `ApiTokenAuthenticator`, `ApiTokenRevoker` and the purge command take an optional `Psr\Log\LoggerInterface` (`logger` service, ignored when absent) and log ids, failure kinds and exception classes only, never a secret, a hash or a presented value |
| PSR-6 / PSR-16 Cache | **N/A** | No cache in S1; the S2 rate limits use Symfony's `cache.rate_limiter` pool (contract §6.7) |
| PSR-14 Event dispatcher | **Adopted** (S1) | The issuer, revoker and authenticator dispatch `ApiTokenIssuedEvent`, `ApiTokenRevokedEvent` and `ApiTokenUsedEvent` through `Psr\EventDispatcher\EventDispatcherInterface`, after commit, ids and value objects only |
| PSR-20 Clock | **Adopted** (S1) | Expiry, *last used* and the purge cutoff read an injected `Psr\Clock\ClockInterface` (`clock` service, `NativeClock` fallback); tests use `MockClock` |
| PSR-7 / PSR-15 / PSR-17 / PSR-18 | **N/A** | No HTTP client and no PSR-7 messages: the S2 firewall and the S5 endpoints use Symfony HttpFoundation |
