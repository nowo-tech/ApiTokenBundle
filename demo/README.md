# API Token Bundle demos

## Symfony 8 (FrankenPHP worker mode + MySQL)

```bash
cd demo/symfony8
make up      # prints "Demo started at: http://localhost:8221"
make setup   # composer install + database
```

- Default `FRANKENPHP_MODE=worker` (kernel kept alive, not reset between requests); `classic` in `.env` + recreate.
- The port is bound to 127.0.0.1. The database is a passwordless MySQL on the private Compose network (demo only).
- `make demo-smoke` from the repository root boots the demo and expects HTTP 200 (REQ-TEST-011).

See [DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).
