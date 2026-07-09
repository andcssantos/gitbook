# TASK-002 Identity Player Baseline Report

## Scope

Implemented the first Identity/Player backend baseline for Evolvaxe. This task does not implement UI, registration screens, starter inventory, gameplay, worlds, expeditions or marketplace.

## Deliverables

- `src/Game/Identity/Repositories/AccountRepository.php`
- `src/Game/Identity/Services/AuthService.php`
- `src/Game/Player/Repositories/PlayerRepository.php`
- `src/Game/Player/Services/PlayerResolver.php`
- `src/Controllers/App/Api/AuthController.php`
- `src/routes/app/api/RoutesAuth.php`
- `database/seeds/002_identity_local_seed.php`
- `tests/Game/Identity/AuthServiceTest.php`

## Endpoints

### POST `/api/auth/login`

Middleware:

- `csrf`
- `rateLimit:10,60`
- `validate:email=required|email|max:160,password=required|string|min:8|max:255`
- `audit:auth.login`

Behavior:

- Accepts email and password.
- Looks up active account by email.
- Verifies password with `password_verify()`.
- Resolves the first active player for the account.
- Calls `Auth::login()`, which rotates the PHP session.
- Returns public account/player identity only.

### POST `/api/auth/logout`

Middleware:

- `auth`
- `csrf`
- `audit:auth.logout`

Behavior:

- Clears authenticated session identity through `Auth::logout()`.

### GET `/api/auth/me`

Middleware:

- `auth`

Behavior:

- Returns the authenticated public account/player identity.

## Local Test Seed

Seeder `002_identity_local_seed.php` creates or updates:

- Account email: `local@evolvaxe.test`
- Password: `evolvaxe-local`
- Player name: `LocalHero`

This seed is intended for local development only.

## Security Decisions

- Passwords are never stored reversibly.
- Password verification uses PHP password APIs and supports Argon2id hashes.
- Session identity may contain internal numeric IDs because it is server-side only.
- API responses expose `public_id`, not internal IDs.
- Controllers are thin and delegate auth/player loading to services and repositories.
- Repositories use bound SQL parameters.
- Login is rate limited and audited.

## Tests Added

- Valid login returns public identity and stores server session identity.
- Invalid password is rejected.
- `PlayerResolver` loads the authenticated active player.
- Auth API routes contain the expected middleware stack.

## Postponed

- Registration endpoint.
- Password reset.
- Email verification.
- Multi-player selection UI.
- Starter inventory creation.
- Player settings/progression tables.
- Remember-me token/device management.
