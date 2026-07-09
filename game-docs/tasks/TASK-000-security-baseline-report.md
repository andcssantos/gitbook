# TASK-000 Security Baseline Report

## Scope

Audited the current PHP framework against Evolvaxe security and server-authority requirements from:

- `game-docs/00-game-vision.md`
- `game-docs/01-database-model.md`
- `game-docs/18-cursor-development-rules.md`
- `game-docs/19-security-server-authority.md`
- `game-docs/tasks/TASK-000-security-baseline.md`

No gameplay implementation was audited because gameplay modules do not exist yet.

## System Classification

| System | Status | Finding |
|---|---:|---|
| Single entry point | READY | `public/index.php` loads `bootstrap/init.php`. `public/router.php` supports PHP built-in server routing. |
| Autoloading | READY | `composer.json` uses PSR-4 `App\\ => src/` and autoloads `src/Support/helpers.php`. |
| Configuration loading | READY WITH MINOR IMPROVEMENTS | `bootstrap/init.php` loads `.env` and `App\Utils\Config::load()`. Config is simple and clear; production secret validation can still be stricter. |
| Router | READY WITH MINOR IMPROVEMENTS | `App\Http\Route` supports verbs, groups, named routes, route cache. `App\Core\Core::dispatch()` executes middleware then controller. It is enough for MVP endpoints, but route params are passed as one array and route cache invalidation is manual. |
| Request | READY | `App\Http\Request::body()` parses JSON, form and raw bodies. Malformed JSON now returns an HTTP error and body size can be limited through `security.request.max_body_bytes`. `Request::ip()` respects trusted proxy only when enabled. |
| Response | READY | `App\Http\Response::json()` gives consistent JSON API responses. `cachedJson()` has ETag support. |
| Middleware pipeline | READY WITH MINOR IMPROVEMENTS | `App\Http\Middleware::handle()` supports aliases and params from `config/middleware.php`. Good for MVP, but middleware has no shared request context object and stops by `exit`, which reduces testability. |
| PDO database layer | READY | `App\Utils\DB\Connection::Conn()` uses PDO, exceptions, default fetch mode, utf8mb4 and native prepares with `PDO::ATTR_EMULATE_PREPARES=false`. |
| Query Builder | READY WITH MINOR IMPROVEMENTS | `App\Database\QueryBuilder` validates identifiers, binds values and now supports `forUpdate()` / `sharedLock()`. Joins and batch helpers can be added when modules need them. |
| Database transactions | READY | `App\Support\DB::transaction()` rolls back on `Throwable` and now uses savepoints for nested transaction calls. |
| Authentication | READY WITH MINOR IMPROVEMENTS | `App\Utils\Construct\Auth::check()` checks session auth, `Auth::login()` rotates the session ID, and `Auth::logout()` clears auth state. A full Identity module is still needed. |
| Sessions | READY | `App\Security\Session::start()` configures strict mode, cookie params, HttpOnly, SameSite and secure cookie behavior before `session_start()`. `Session::regenerate()` rotates session and CSRF token. |
| CSRF protection | READY | `App\Security\Csrf::validate()` accepts `X-CSRF-Token` for JSON and `_csrf_token` for forms. `App\Middlewares\CsrfMiddleware::handle()` blocks state-changing methods. Token rotation is available through `Session::regenerate()`. |
| Validation | READY WITH MINOR IMPROVEMENTS | `App\Validation\Validator::validate()` supports required/string/int/numeric/bool/email/array/min/max/in. `App\Middlewares\ValidateMiddleware::handle()` validates JSON body. Needs domain validators, allowed unknown-field policy, UUID/ULID/date rules and nested array rules. |
| JSON API responses | READY | `App\Core\Controller::success()`, `fail()`, `json()` and `App\Http\Response::json()` provide consistent responses. |
| Error handling | READY | `App\Core\ErrorHandler::handleException()` hides details when `app.debug=false`, maps `App\Http\HttpException` to JSON HTTP responses, and logs unexpected exceptions. |
| Rate limiting | READY WITH MINOR IMPROVEMENTS | `App\Utils\Security\RateLimiter::attempt()` now wraps counter mutation in a cache file lock. Gameplay cooldowns must still be validated in DB/domain state. |
| Idempotency | READY | `App\Security\Idempotency::handle()` supports DB-backed atomic reservation, unique scope/key, request hash validation, in-progress conflict, completed response replay and cache fallback. |
| Audit logging | READY | `App\Security\AuditLogger::log()` writes to DB `game_audit_logs` when configured and falls back to JSONL file logging. Secrets are redacted. |
| Migrations | READY WITH MINOR IMPROVEMENTS | `App\Database\MigrationManager` tracks migrations in DB and supports up/rollback/status/make with transaction wrapping when supported. |
| Seeders | READY WITH MINOR IMPROVEMENTS | `App\Console\Commands\SeedCommand` runs callable seed files with PDO inside a transaction. Environment guards can be added later. |
| Frontend API wrapper | READY WITH MINOR IMPROVEMENTS | `public/assets/framework/api.js` sends `X-CSRF-Token`, `Idempotency-Key`, JSON headers and same-origin credentials. Good client helper, but security cannot rely on it. |
| Server timer helper | READY WITH MINOR IMPROVEMENTS | `public/assets/framework/timer.js` can display server-synchronized countdowns. Correctly only visual; server must validate `ends_at`. |

## Security Requirement Notes

- Transaction rollback on `Throwable` exists in `App\Support\DB::transaction()`.
- Nested transaction behavior now uses savepoints.
- Row locking support exists through `App\Database\QueryBuilder::forUpdate()` and `sharedLock()`.
- SQL parameter binding is generally good in `App\Database\QueryBuilder` and `App\Core\Model::query()`.
- Unsafe dynamic SQL risk remains in `App\Core\Model::select(string $where, ...)`, because `$where` is passed directly.
- Mass assignment risk exists in `App\Core\Model::create()` and `update()` and `App\Database\QueryBuilder::insert()`/`update()` if controllers pass request data directly.
- Trusted proxy handling is opt-in through `App\Http\Request::ip()` and `config/app.php`. This is correct for local/XAMPP, but must remain disabled unless proxy headers are controlled.
- Timezone is set in `bootstrap/init.php` and `bootstrap/console.php` via `date_default_timezone_set()`. Gameplay should still use server DB timestamps or a central clock abstraction.
- Server clock usage exists via `time()` in JWT, idempotency, rate limit and the example action. Expedition/combat modules must use server time only.

## Future Operation Safety

| Operation | Current Safety | Reason |
|---|---:|---|
| 1. Starting a timed Expedition | FRAMEWORK READY, MODULE NEEDED | Requires Expedition schema/service. Framework now has auth, CSRF, idempotency DB, transaction and locks. |
| 2. Rejecting Expedition actions after server-side `ends_at` | FRAMEWORK READY, MODULE NEEDED | Domain service must query Expedition state and compare server time. |
| 3. Processing rapid resource hit requests | FRAMEWORK READY, MODULE NEEDED | Rate limiting is locked; real hit cooldown/resource health must be persisted and locked by service. |
| 4. Processing combat attack requests with server cooldown validation | FRAMEWORK READY, MODULE NEEDED | Combat service must lock combat state and validate cooldown server-side. |
| 5. Moving GridStack items between containers | FRAMEWORK READY, MODULE NEEDED | Inventory service must validate grid rows and use `forUpdate()`/version constraints. |
| 6. Preventing concurrent inventory placement conflicts | FRAMEWORK READY, MODULE NEEDED | Requires placement table constraints plus row locks inside transaction. |
| 7. Crafting with exact selected material instances | FRAMEWORK READY, MODULE NEEDED | Crafting service must lock selected material instance rows. |
| 8. Preventing duplicate craft requests | FRAMEWORK READY | DB-backed idempotency now supports duplicate replay and request hash conflict. |
| 9. Creating unique procedural item instances | FRAMEWORK READY, MODULE NEEDED | Item generation service and schema still need to be created. |
| 10. Minting currency with immutable ledger | FRAMEWORK READY, MODULE NEEDED | Economy schema/service must implement integer ledger entries under transaction. |
| 11. Creating Marketplace listings with item escrow | FRAMEWORK READY, MODULE NEEDED | Marketplace service must lock item/listing rows and escrow ownership. |
| 12. Processing simultaneous purchase attempts for same listing | FRAMEWORK READY, MODULE NEEDED | Listing and wallet rows can be locked; Marketplace service must implement settlement. |
| 13. Preventing wallet double-spend | FRAMEWORK READY, MODULE NEEDED | Wallet service must lock wallet rows and use immutable ledger. |
| 14. Recovering active Expedition after browser reload | FRAMEWORK READY, MODULE NEEDED | Expedition table/service must persist active state by player. |
| 15. Recording suspicious or invalid gameplay actions | FRAMEWORK READY | DB-backed audit logging is available through `App\Security\AuditLogger::log()`. |

## Answers From TASK-000

1. Single entry point: `public/index.php`, which requires `bootstrap/init.php`.
2. Routes: declared with `App\Http\Route`, loaded from `src/routes/app/_main.php`, dispatched by `App\Core\Core::dispatch()`.
3. Controllers: current base is `App\Core\Controller`; controllers should remain thin and call domain services.
4. Authentication: `App\Utils\Construct\Auth::check()` checks `$_SESSION['user']`; `GenJwt::validateJwt()` may hydrate the session.
5. CSRF: implemented by `App\Security\Csrf` and `App\Middlewares\CsrfMiddleware`.
6. JSON validation: `App\Http\Request::body()` parses JSON and `App\Middlewares\ValidateMiddleware` / `App\Validation\Validator` validate fields.
7. Application services should live in `src/Game/<Module>/Services` or `src/Services/Game/<Module>`.
8. Repositories should live in `src/Game/<Module>/Repositories` or `src/Repositories/Game/<Module>`.
9. Transactions should be created with `App\Support\DB::transaction()` around the whole domain mutation, not inside controllers.
10. Idempotency keys are stored in DB `idempotency_keys` with unique `(scope, key_hash)`, request hash, status, response payload and timestamps.
11. Audit logs are written to DB `game_audit_logs` for gameplay security events; file audit remains as fallback/system log.
12. Safe to keep: routing, response helpers, config loader, middleware aliases, CSRF header support, PDO connection, basic query builder, migration/seed CLI, frontend API wrapper.
13. Must improve before gameplay: no remaining framework-level blocker; first gameplay module must define schema, service, repositories, locks and tests.
14. Recommended backend structure:
    - `src/Game/<Module>/Controllers`
    - `src/Game/<Module>/Services`
    - `src/Game/<Module>/Repositories`
    - `src/Game/<Module>/Validators`
    - `src/Game/<Module>/DTO`
    - `src/Game/<Module>/Events`
    - `src/Game/<Module>/Tests`
15. Recommended frontend JS structure:
    - `public/assets/framework/api.js`
    - `public/assets/framework/state.js`
    - `public/assets/framework/timer.js`
    - `public/views/app/<area>/<module>/assets/module.json`
    - `public/views/app/<area>/<module>/assets/script/*.js`
    - `public/views/app/<area>/<module>/assets/css/*.css`

## Framework Readiness Score

88/100 for Evolvaxe framework infrastructure.

The framework is now ready to host the first authoritative gameplay module. It is not scored 100 because there are still no gameplay schemas, repositories, services or economic/concurrency tests proving a real module end to end.

## Blocking Issues Before Gameplay

- None remaining at framework-infrastructure level.
- Before the first gameplay endpoint, run the new migrations and implement that module with explicit schema, service, repositories, transaction boundaries, row locks and tests.
- Do not use `App\Core\Model::select($where)` for gameplay repositories because it permits raw dynamic SQL.

## Minor Improvements

- Add typed validation for required secrets such as `JWT_KEY`, `CACHE_KEY`, DB name and production debug mode.
- Add seed environment guards.
- Add typed production validation for required secrets such as `JWT_KEY`, `CACHE_KEY`, DB name and `APP_DEBUG=false`.
- Add Content Security Policy once frontend asset needs are stable.
- Keep trusted proxy disabled unless proxy headers are controlled by the deployment.

## Recommended First Gameplay Task

Implement the Identity/Player baseline module with accounts, players, secure login using `Auth::login()`, logout, player resolver, migrations, seed-safe test account flow, and tests proving session regeneration and authenticated JSON endpoint access.
