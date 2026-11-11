# Demo with FrankenPHP (worker mode)

## Table of contents

- [Run it](#run-it)
- [Services](#services)
- [Worker mode](#worker-mode)
- [Bundle registration](#bundle-registration)
- [Troubleshooting](#troubleshooting)

## Run it

```bash
cd demo/symfony8
make up       # http://localhost:8221 (bound to 127.0.0.1)
make setup    # composer install + database
make down
```

From the repository root, `make demo-smoke` boots the demo, expects HTTP 200 on `/` and stops it (REQ-TEST-011; CI
runs it on every push and pull request).

## Services

| Service | Image | Notes |
| --- | --- | --- |
| `php` | `dunglas/frankenphp:1-php8.5-alpine` | Symfony 8.1; the bundle mounted from the repository root through a Composer path repository |
| `db` | `mysql:8.0` (no host port, REQ-DEMO-006) | Passwordless root on the private Compose network (demo only, nothing secret in the repository) |

## Worker mode

`FRANKENPHP_MODE=worker` (default) boots the kernel once and keeps it alive between requests, without a kernel reset.
Set `FRANKENPHP_MODE=classic` in `.env` and recreate the container for one process per request.
`APP_SECRET` is generated into `.env` by the entrypoint on the first start.

## Bundle registration

```php
// demo/symfony8/config/bundles.php
Nowo\ApiTokenBundle\NowoApiTokenBundle::class => ['all' => true],
```

`config/packages/nowo_api_token.yaml` keeps `enabled: false` in S0. The home page shows the bundle parameters and the MySQL
version.

## Troubleshooting

- `Demo not ready (HTTP 000)` right after `make up`: the first `composer install` runs inside the container; wait and
  run `make -C demo/symfony8 up` again.
- Composer DNS errors on WSL: the Compose file pins public DNS servers for the `php` service.
