# Concurrency

How the bundle stays correct when the same tokens are written at the same time: two requests, two workers, a retried
call (REQ-RUNTIME-003). It works in
FrankenPHP worker mode with the kernel kept alive, and in standard and DDD hosts alike (see
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)).

## Table of contents

- [Write paths](#write-paths)
- [Conflict codes](#conflict-codes)
- [Host options](#host-options)
- [Tests](#tests)
- [Residual risks](#residual-risks)

## Write paths

The bundle writes only through DBAL on the connection of `doctrine.entity_manager` (`DbalApiTokenStore`). It never
flushes, clears or resets an EntityManager, the host's included. Every unit runs in
`ApiTokenStoreInterface::transactional()`, which uses transaction-doctrine's `DoctrineTransactionRunner::forConnection()`:
after a failure in a nested unit the nesting level is rolled back to where it started (savepoint-aware; after a MySQL
nested deadlock the connection is closed instead of reporting success). Inside a host transaction the unit joins it
(a savepoint), and its events wait for the host's commit (`AfterCommitQueue`).

| Path | Transaction | Control (rule 4) | Conflict answer |
| --- | --- | --- | --- |
| Issue (`ApiTokenIssuer::issue()`, `nowo:api-token:issue`) | One unit: lock row, count, name check, insert | Owner lock row `<prefix>api_token_owner_lock` (`UPDATE … SET version = version + 1`, created once under a savepoint + unique catch) taken first, so the live-token cap (`max_per_owner`) and the name check are re-read after any concurrent issue commits; unique `(owner_type, owner_id, name_key)` and `lookup_id` + catch (a lookup collision gets a fresh id, 3 tries) | `CONFLICT_001` |
| Revoke one (`revoke()`, `revokeAsOperator()`) | One unit: one `DELETE` | Owner re-checked in PHP (`===`); delete by id is idempotent | `CONFLICT_001` |
| Revoke all (`revokeAll()`) | One unit per batch of 500 | Batches by owner; a replay deletes what is left | `CONFLICT_001` before the first committed batch, `CONFLICT_002` after |
| Purge (`purgeExpired()`, `nowo:api-token:purge-expired`) | One unit per keyset batch (`messenger.batch_size`), time-boxed (`messenger.max_seconds`) | Keyset by id; deletes are idempotent | `CONFLICT_001` before the first committed batch, `CONFLICT_002` after; the command exits 1 and logs a warning with the code |
| *Last used* (`ApiTokenAuthenticator`) | Own autocommit `UPDATE`, skipped while any transaction is open | Atomic conditional `UPDATE … WHERE last_used_at IS NULL OR last_used_at < ?` (at most once a minute per token) | None: best effort (see residual risks) |

Rule 5 (exact keys): `owner_type` and `owner_id` (and `name_key`) use binary collations on MySQL / MariaDB in both
tables, so `ORG-1` never shares a row with `org-1`; owner-scoped reads are re-checked in PHP with `===`.

Side effects (`ApiTokenIssuedEvent`, `ApiTokenRevokedEvent`, revocation listeners) run after the real commit. No HTTP
call, mail or file I/O runs while the owner lock is held: hashing and validation happen before the transaction.

## Conflict codes

A deadlock, lock wait timeout or serialization failure (MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40P01 / 55P03 /
40001, SQLite "database is locked") is replayed up to `concurrency.attempts` times when the store opened the
transaction itself. It is never replayed inside a host transaction (the host's work was rolled back with it). Once the
replays are spent the service throws `Nowo\ApiTokenBundle\Contract\Exception\ApiTokenConflictException`:

| Code | Meaning | HTTP answer |
| --- | --- | --- |
| `CONFLICT_001` | Nothing of the call was committed: retrying is safe | `409` (or `conflict_response.status`), `Retry-After: <conflict_response.retry_after>`, `X-Conflict-Code: CONFLICT_001` |
| `CONFLICT_002` | Part of the call (an earlier batch), or something else earlier in the same request, was committed, or a savepoint was lost with an unknown outcome: read again, do not repeat | `409`, no `Retry-After`, `X-Conflict-Code: CONFLICT_002` |

- The exception extends transaction-contract's `TransientStorageFailure` and implements transaction-doctrine's
  `LockConflictExceptionInterface`. Its previous exception is a `ConcurrencyConflict` (attempts, kind, `committed`),
  whose previous is the storage error. `$exception->conflictCode` is the unit's own code; the message is constant and
  client-safe.
- The bundle has no routes of its own in S1. When an `ApiTokenConflictException` reaches the kernel from a host
  controller that called the bundle's services, `ApiTokenConflictListener` (`kernel.exception`, priority 0) replaces it
  with `ConflictResponsePolicy::httpExceptionFor()`: the host's error page renders it with the status and the headers
  above. The code comes from `ConflictCode::forRequest()`, so a commit made earlier in the same request (host or
  another bundle) turns `CONFLICT_001` into `CONFLICT_002` when the host enables transaction-doctrine's
  `commit_detection` (request watermark). transaction-doctrine's own responder, when registered, runs first
  (priority 64) and answers the same codes in its own JSON / HTML shape.
- The listener answers only for the bundle's own conflict. A host's deadlock elsewhere, and any failure already mapped
  to an `HttpException`, are left alone.
- **Not conflicts:** unique violations and lost connections. A unique violation on insert becomes
  `LookupIdTakenException` inside the issuer (fresh lookup id) or, for a same-name race, the `duplicate_name` refusal;
  it is never answered with `CONFLICT_*`. A lost connection keeps its DBAL exception (see residual risks).
- Clients key on `X-Conflict-Code` (cross-origin APIs list it in `Access-Control-Expose-Headers`), never on the
  message.

## Host options

```yaml
nowo_api_token:
    max_per_owner: 20                              # the cap the owner lock protects
    concurrency:
        attempts: 3                                # tries of a unit the store opened (1 = no replay)
        lock_timeout_ms: 5000                      # PostgreSQL SET LOCAL lock_timeout of a unit the store opened; 0 = server default
    conflict_response:
        status: 409                                # 409 (platform default) or 503
        retry_after: 1                             # seconds, CONFLICT_001 only
```

- MySQL / MariaDB: the lock wait is bounded by the server's `innodb_lock_wait_timeout` (default 50 s). The bundle does
  not change session variables (they would outlive the unit on the host's connection); lower it on the connection if
  50 s is too long for your API.
- PostgreSQL: inside a host transaction the bundle does not set `lock_timeout` (`SET LOCAL` would last for the host's
  whole transaction); the host's own setting applies.
- To have the bundle answer through transaction-doctrine's responder instead (JSON body with `code`), register
  `NowoTransactionDoctrineBundle`; enable its `commit_detection` for request-level `CONFLICT_002`.
- Hosts that call the services from a console command or a Messenger handler catch `ApiTokenConflictException` and
  read `$exception->conflictCode` (or `ConflictCode::forFailure()`); Messenger's retry is safe for `CONFLICT_001`.

## Tests

- `tests/Unit/Infrastructure/DbalStoreConcurrencyTest.php`: the lock row (first insert, concurrent creation, update),
  replay then success, replays spent (`CONFLICT_001`), no replay inside a host transaction, lost savepoint
  (`CONFLICT_002`), PostgreSQL `lock_timeout`.
- `tests/Unit/Infrastructure/ConflictMappingTest.php`: 409 / 503, `Retry-After` only for `CONFLICT_001`,
  `X-Conflict-Code` on both, request watermark, unique violations and host deadlocks left alone.
- `tests/Unit/Application/RevokerQueryTest.php`: `CONFLICT_002` after a committed batch of `revokeAll()` / purge.
- `tests/Integration/Database/{Standard,Ddd}HostConcurrencyTest.php`: two connections (a second PHP process,
  `tests/Fixtures/hold-lock.php`, holds real row locks), on SQLite and in the required `Databases` CI job on MySQL 8.4,
  MariaDB 11.4 and PostgreSQL 16:
  - the cap holds while a concurrent issuer holds the owner lock (the issue waits, then refuses `too_many`; exactly
    `max_per_owner` live rows);
  - a lock wait timeout on issue gives `CONFLICT_001` after the replays, writes nothing, and the connection stays
    usable; the retry after the release succeeds;
  - a lock wait timeout in the second purge batch gives `CONFLICT_002` with no `Retry-After`; the first batch stays
    deleted.

  Every test session bounds its lock waits (`innodb_lock_wait_timeout`, `lock_timeout`, SQLite `busy_timeout`).

## Residual risks

- **Joined host transaction with an old snapshot.** Inside a host transaction at MySQL / MariaDB `REPEATABLE READ`
  that already read the token table before calling `issue()`, the count after the lock reads that older snapshot, so a
  token committed meanwhile by another issuer may not be counted: the cap can be exceeded by the tokens committed in
  that window. Outside a host transaction (the normal case) the count runs after the lock and sees every committed
  token. PostgreSQL `READ COMMITTED` is not affected.
- **Trailing spaces on MySQL / MariaDB.** `utf8mb4_bin` is a PAD SPACE collation: `alice` and `alice ` share one lock
  row. That only serialises their issues more than needed; tokens and caps stay separate.
- **Lost connection at commit.** A unit the store opened is run once more on a fresh connection when the connection was
  lost (worker mode). If the loss hit the commit itself and the commit did land, the replay meets the natural unique
  keys: an issue answers the `duplicate_name` refusal (the first token exists; its secret was not shown), revocations
  and the purge find nothing left to delete. No duplicate row is possible.
- **Last used.** The *last used* write is best effort by contract (§6.2 step 9): a lock conflict or any other failure
  is logged and the authentication still succeeds. It is its own autocommit statement outside any transaction, so
  nothing else is rolled back; the next authenticated call retries it. This is the one documented exception to rule 8.
- **Revocation versus an in-flight request.** Authentication reads the token row once, at the start of the request
  (contract §6.2); nothing re-checks it later. A request that passed that read just before a revocation (or a
  `revokeAll()`, an owner erasure or the purge) committed runs to completion with the token's grants, and its *last
  used* update then matches 0 rows. The window is one request per worker that was already past authentication, never
  longer than that request: every request that starts after the commit is refused. Long-running work started from such
  a request (a queued message, a stream) must re-check what it needs itself. The per-token budget is per token, so an
  owner can spend up to `max_per_owner` × the per-token budget; this is the contract's design (audit ZAB-S2-I4).
- **Owner lock rows are never deleted.** One small row per owner that ever issued a token; deleting it on the last
  revocation would race with a concurrent issue.
