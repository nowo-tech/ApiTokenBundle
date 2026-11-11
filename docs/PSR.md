# PSR compliance

Package: `nowo-tech/api-token-bundle` (`symfony-bundle`)

| PSR | Status | Notes |
| --- | --- | --- |
| PSR-1 / PSR-12 Coding style | **Adopted** | PHP-CS-Fixer (`@PSR12` + `@Symfony`), `make cs-check` in CI |
| PSR-4 Autoloading | **Adopted** | `Nowo\ApiTokenBundle\` → `src/` |
| PSR-3 Logger | **Planned** | Services of the next slices take an optional `Psr\Log\LoggerInterface` and log ids and kinds only, never secrets or personal data |
| PSR-6 / PSR-16 Cache | **N/A** | Rate limits use Symfony's `cache.rate_limiter` pool (contract) |
| PSR-14 Event dispatcher | **Planned** | Events dispatched after commit, ids only |
| PSR-20 Clock | **Planned** | Time comes from an injected `Psr\Clock\ClockInterface`; tests use a fixed clock |

S0 has no runtime service, so only the coding-style and autoloading rows apply today.
