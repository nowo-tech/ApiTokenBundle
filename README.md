# API Token Bundle

[![CI](https://github.com/nowo-tech/ApiTokenBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/ApiTokenBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/api-token-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/api-token-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

**Personal API tokens for Symfony: hashed lookup tokens with per-module grants, a tenant reach, mandatory expiry, revocation, rate limits and audit events, one Bearer authenticator for every API and a UiKit account and operator UI.**

> 🚧 **Status: S0 scaffold, no release yet.** The package is built slice by slice from its design contract
> ([docs/CONTRACT.md](docs/CONTRACT.md), 221-A). S0 ships the bundle class, the strict configuration tree and every
> quality gate; the features below land with the slices in [docs/ROADMAP.md](docs/ROADMAP.md).

> 📋 **Compatible with Symfony 7.4, 8.0 and 8.1** · PHP 8.2+ (Symfony 8.x requires PHP 8.4+)

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is built for **FrankenPHP worker mode with the kernel kept alive and not reset between requests**: no
request state in services, a worker test (one kernel, 2,000 requests) from the first slice.

**Standard Symfony by default, DDD / hexagonal opt-in:** zero configuration in a standard project; public ports are
interfaces and value objects, bundle-owned tables are mapped in XML and referenced by id, the bundle never flushes your
EntityManager.

## Features (planned, see the roadmap)

- **Personal tokens** issued to an owner (referenced by id): name, per-module grants (level + flags), a tenant *reach*,
  mandatory expiry (max 365 days), revoke, revoke all, erasure. Hash at rest (SHA-256), shown once, non-secret lookup
  id, CRC32 checksum for typo checks and secret scanning (`nwt_<lookup>_<secret><checksum>`).
- **One Bearer authenticator** for Symfony firewalls (stateless), constant-time verification, owner status re-checked
  on every request, principal stamped on the request only.
- **Module registry**: grants as data (`{tasks: read, mcp: on + destructive}`), modules from config and bridges; a token
  only narrows the person's real permissions, never widens them.
- **Security controls**: one rate-limit bucket per token across every API, write sub-budget, failed tokens per IP,
  re-authentication before issue (password, or MFA through AuthKit), framing and `no-store` headers, audit events after
  commit (ids only).
- **UI** on UiKit + FormKit: account page (list, *New token* dialog shown once, revoke, revoke all) and operator page.
- **Public API** `GET` / `DELETE <prefix>/tokens/v1/self` with OpenAPI; optional leak-report endpoint (off by default).
- **Bridges** (inside this bundle, active when installed): TaskBoard, Wiki, McpKit principals and rate limiters,
  HttpLog credential stamp, UserKit eraser / export / sessions listener, AuditKit, AuthKit re-auth.

## Installation

```bash
composer require nowo-tech/api-token-bundle
```

See [docs/INSTALLATION.md](docs/INSTALLATION.md). Nothing is registered while `nowo_api_token.enabled` is false.

## Configuration

```yaml
# config/packages/nowo_api_token.yaml
nowo_api_token:
    enabled: false
```

Every key of the contract is already accepted (strict tree): [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md) (incl. using it from a DDD / hexagonal application)
- [Roadmap](docs/ROADMAP.md) (slices and open questions)
- [Contract](docs/CONTRACT.md)
- [Security](docs/SECURITY.md)
- [FrankenPHP worker audit](docs/FRANKENPHP-WORKER-AUDIT.md)
- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)
- [Upgrading](docs/UPGRADING.md) · [Changelog](docs/CHANGELOG.md) · [Release](docs/RELEASE.md)
- [Architecture](docs/ARCHITECTURE.md) · [PSR](docs/PSR.md) · [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md) · [Spec Kit](docs/SPEC-KIT.md)
- [Contributing](docs/CONTRIBUTING.md) · [GitHub CI](docs/GITHUB_CI.md) · [Engram](docs/ENGRAM.md)

## Requirements

- PHP >= 8.2, < 8.6
- Symfony 7.4, 8.0 or 8.1

## Demo

`demo/symfony8`: Symfony 8.1, FrankenPHP worker mode, MySQL, on http://localhost:8221 (`make -C demo up`).

## Tests and coverage

```bash
make test               # PHPUnit in Docker
make test-coverage-100  # fails below 100 % of src/
make release-check      # every gate, demo included
```

- PHP: 100 % of `src/` (gate in CI).

## License

MIT. See [LICENSE](LICENSE).

## Author

Created by [Héctor Franco Aceituno](https://github.com/HecFranco) at [Nowo.tech](https://nowo.tech).
