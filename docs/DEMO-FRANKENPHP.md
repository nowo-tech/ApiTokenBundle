# Demo with FrankenPHP (worker mode)

## Table of contents

- [Run it in development](#run-it-in-development)
- [Run it in production mode](#run-it-in-production-mode)
- [Services](#services)
- [PHP version policy](#php-version-policy)
- [Worker and classic mode](#worker-and-classic-mode)
- [Development tools](#development-tools)
- [Bundle registration](#bundle-registration)
- [Makefile targets](#makefile-targets)
- [Timeouts](#timeouts)
- [Troubleshooting](#troubleshooting)

## Run it in development

```bash
cd demo/symfony8
make up       # http://localhost:8221 (bound to 127.0.0.1)
make setup    # composer install + database
make down
```

From the repository root, `make demo-smoke` boots the demo, expects HTTP 200 on `/` and stops it (REQ-TEST-011; CI
runs it on every push and pull request).

The development stack runs `APP_ENV=dev`, `APP_DEBUG=1`, the Web Profiler, the Debug bundle, Nowo Twig Inspector and
Nowo Hot Reload (Mercure + `hot_reload` in both Caddyfiles; see [Development tools](#development-tools)).

## Run it in production mode

The image is the same; the environment decides:

1. Copy `.env.example` to `.env` and set `APP_ENV=prod`, `APP_DEBUG=0`, `FRANKENPHP_MODE=worker` and a real
   `DATABASE_URL`.
2. Install without dev packages and warm the cache:

   ```bash
   docker compose run --rm --no-deps --entrypoint composer php install --no-dev --optimize-autoloader --no-interaction
   docker compose run --rm --no-deps --entrypoint php php bin/console cache:warmup --env=prod
   ```

3. Start it: `docker compose up -d`. The entrypoint keeps `docker/frankenphp/Caddyfile` (worker mode). The Hot
   Reload, Twig Inspector, Web Profiler and Debug bundles are registered for `dev` / `test` only, so they are not
   loaded in `prod`; Hot Reload also refuses production (`allow_production: false`).
4. Drop the `Caddyfile.dev` volume from `docker-compose.yml` and put the server behind your TLS proxy, or set
   `SERVER_NAME` to your domain so Caddy obtains a certificate.

## Services

| Service | Image | Notes |
| --- | --- | --- |
| `php` | `dunglas/frankenphp:1-php8.5-alpine` | Symfony 8.1; the bundle mounted from the repository root through a Composer path repository |
| `db` | `mysql:8.0` (no host port, REQ-DEMO-006) | Passwordless root on the private Compose network (demo only, nothing secret in the repository) |

## PHP version policy

Symfony 8 demos run on the **newest PHP minor published in the official FrankenPHP images** that the demo's
`require.php` (`>=8.4,<8.6`) allows: today PHP **8.5** (`dunglas/frankenphp:1-php8.5-alpine`). When FrankenPHP ships
a newer PHP and the constraint allows it, the image moves in a follow-up change (REQ-DEMO-010). The demo pins Symfony
`8.1.*` (`extra.symfony.require`, REQ-DEMO-012).

## Worker and classic mode

`FRANKENPHP_MODE` lives in `.env` (template `.env.example`, default `worker`) and Compose passes it to the container;
it is not baked into the image.

- `worker` (default): `docker/frankenphp/Caddyfile`, `php_server { hot_reload; worker { file /app/public/index.php;
  watch } }`. The kernel boots once and is kept alive between requests, without a kernel reset.
- `classic`: the entrypoint copies `docker/frankenphp/Caddyfile.dev` (plain `php_server { hot_reload }`, one process
  per request, no-cache headers).

Switch by editing `.env` and recreating the container (`docker compose up -d`); a plain `restart` does not reload the
environment and no rebuild is needed. `APP_SECRET` is generated into `.env` by the entrypoint on the first start.

## Development tools

| Tool | Package (`require-dev`) | Registered for |
| --- | --- | --- |
| Web Profiler (toolbar, `/_profiler`) | `symfony/web-profiler-bundle` `8.1.*` | `dev`, `test` |
| Debug (`dump()` server) | `symfony/debug-bundle` `8.1.*` | `dev` |
| Nowo Twig Inspector | `nowo-tech/twig-inspector-bundle` | `dev`, `test` |
| Nowo Hot Reload (`config/packages/dev/nowo_hot_reload.yaml`) | `nowo-tech/hot-reload-bundle` | `dev`, `test` |

`config/packages/dev/twig.yaml` turns the Twig cache off and `docker/php-dev.ini` sets `opcache.revalidate_freq=0`, so
changes show on refresh.

## Bundle registration

```php
// demo/symfony8/config/bundles.php
Nowo\ApiTokenBundle\NowoApiTokenBundle::class => ['all' => true],
```

`config/packages/nowo_api_token.yaml` keeps `enabled: false` until the S3 slice ships the account page the demo
exercises (contract §11 "Demo"). The home page shows the bundle parameters and the MySQL version.

## Makefile targets

`make -C demo/symfony8 help` lists them: `up`, `down`, `restart`, `build`, `ensure-up`, `install`, `setup`,
`update-bundle`, `update-deps` (one-off container, REQ-MAKE-008), `test` (container and Twig lint), `test-coverage`
(the same lint: the demo has no PHPUnit suite), `verify` (HTTP 200 on `/`), `db-schema`, `cache-clear`, `shell`,
`logs`.

## Timeouts

The demo runs no blocking work (no document conversion, external HTTP or subprocess), so the REQ-RUNTIME-001 timeout
hierarchy does not apply; FrankenPHP and PHP defaults are kept.

## Troubleshooting

- `Demo not ready (HTTP 000)` right after `make up`: the first `composer install` runs inside the container; wait and
  run `make -C demo/symfony8 up` again.
- Composer DNS errors on WSL: the Compose file pins public DNS servers for the `php` service.
- Caddy refuses to start with `hot_reload`: the directive needs the Mercure module and `order mercure after encode` in
  the global options (both Caddyfiles have it); a custom Caddyfile must keep them.
- Changes not visible in worker mode: `watch` restarts the workers on file changes; if a change is still not picked
  up, switch to `FRANKENPHP_MODE=classic` and recreate the container.
- No toolbar: check `APP_ENV=dev` and `APP_DEBUG=1` in `.env`, then recreate the container.
