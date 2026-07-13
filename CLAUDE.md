# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

A fault-tolerant **product synchronization system**: it periodically pulls product catalogues from third-party supplier APIs (DummyJSON, FakeStore), detects changes via **hash-based delta sync**, runs the work asynchronously on queues with **retry + exponential backoff + a dead letter queue**, and exposes status through a REST API and an Inertia/React dashboard. The reference design lives in `SPEC.md` (full technical spec, in Turkish) and `PLAN.md` (decisions); the sync code is cross-referenced back to SPEC section numbers (e.g. "SPEC §6.3", "E18") — treat those as the source of truth for intended behaviour.

The scaffolding is Laravel's **React starter kit**: Inertia.js v3 for server-driven SPA routing, Fortify for authentication, Tailwind v4, Sanctum for API tokens, and Wayfinder for type-safe route/action generation in TypeScript. Laravel Boost is installed for AI-assisted development (MCP tools, docs search).

## Commands

Setup / Docker:
- `composer run setup` — one-shot local bootstrap (install, `.env`, key, migrate, npm build).
- `docker compose up` — full containerized stack: `app` (php-fpm), `nginx`, dedicated `queue` worker, `scheduler`, `mysql`, `redis`. Use this to exercise the real async path (separate worker + scheduler), which `composer run dev` does not fully reproduce.

Sync engine:
- `php artisan sync:dispatch` — queue a sync for every enabled provider (what the scheduler runs every `SYNC_INTERVAL_MINUTES`). Scope with `--provider=dummyjson|fakestore`.

Backend (PHP):
- `composer run dev` — run the full dev stack concurrently (`php artisan serve`, queue listener, `pail` log tailer, `vite`). This is the standard way to run the app locally.
- `php artisan test --compact` — run the full PHPUnit suite.
- `php artisan test --compact tests/Feature/SomeTest.php` — run a single test file.
- `php artisan test --compact --filter=testName` — run a single test by name.
- `vendor/bin/pint --dirty --format agent` — format only changed PHP files (run before finalizing any PHP change).
- `composer run types:check` — static analysis via Larastan/PHPStan.
- `composer run ci:check` — full CI gate: JS lint, JS format check, TS types, then PHP tests (includes Pint check + Larastan).

Frontend (JS/TS), via npm:
- `npm run dev` — Vite dev server only (use `composer run dev` instead for full-stack work).
- `npm run build` / `npm run build:ssr` — production build.
- `npm run lint` / `npm run lint:check` — ESLint, with/without autofix.
- `npm run format` / `npm run format:check` — Prettier, with/without autofix.
- `npm run types:check` — `tsc --noEmit`.

Tests are PHPUnit (not Pest) — see `tests/Unit` and `tests/Feature`; the suite runs against an in-memory SQLite DB (`phpunit.xml`).

## Architecture

### Sync engine (`app/Sync/**`, `app/Jobs/`)

This is the core of the project; the Laravel starter-kit pieces below are just the shell around it.

- **Pipeline**: `SyncDispatcher` (creates a pending `SyncLog`, refuses a second active run per provider) → `SyncProviderJob` (`ShouldBeUnique`, `onQueue('sync')`, retried with exponential backoff, dead-lettered after the last attempt) → `SyncOrchestrator` (acquires a per-provider Redis lock, drives one run end to end, records the outcome on the `SyncLog`) → `DeltaSyncService` (applies the provider's product stream to the local catalogue) → a `ProductProvider` adapter.
- **Delta sync**: `ProductHasher` hashes each normalized product; only records whose hash changed are updated. New records are inserted, previously soft-deleted ones are restored, and products the provider no longer returns are soft-deleted — **except** a mass-deletion guard (`sync.delete_guard_ratio`, SPEC E18) cancels the deletion pass and marks the run partial if it would remove too much of the catalogue (protects against a provider outage). Idempotency comes from the `(provider_type, external_id)` unique key inside a transaction.
- **Resilience services** (`app/Sync/Services/`): `CircuitBreaker` trips after N consecutive request failures and skips the provider during a cooldown; `RateLimiter` spaces outbound requests to stay under `max_requests_per_second`; `SyncHttpClient` retries request-level 429/5xx with backoff; `DeadLetterService` parks jobs that exhaust their tries (retriable via the API); `AlertManager` emits debounced structured-log alerts (failure streaks, DLQ size, queue backlog).
- **Providers**: implement `app/Sync/Contracts/ProductProvider` (which streams `ProductData` DTOs lazily so the full catalogue is never held in memory) and register the class in `config/sync.php` under `providers`. `AbstractHttpProvider` gives you the HTTP/paging plumbing; `ProviderRegistry` resolves enabled providers. Adding a provider = one class + one config entry, no engine changes.
- **Config is the control surface**: nearly all engine behaviour (interval, RPS, tries/backoff, breaker thresholds, delete guard, missing-stock policy, alert thresholds, API throttles) is env-driven through `config/sync.php` — read it before changing tuning, and prefer adding config over hardcoding.
- **State models**: `SyncLog` (one row per run: status/trigger/metrics), `Product` (the synced catalogue, soft-deletes), `DeadLetterJob`, `SyncAlert`. Enums in `app/Enums/` (`SyncStatus`, `TriggerType`, `ProviderType`, `DeadLetterStatus`, `AlertType`) are the canonical vocabulary.

### REST API (`routes/api.php`, `app/Http/Controllers/Api/`)

- Sanctum-authenticated (`auth:sanctum`) except the public `GET /api/health` probe. Read endpoints (`sync/status`, `sync/history`, `sync/failed-jobs`, `products`) use the default throttle; state-changing `POST sync/trigger` and `POST sync/retry/{jobId}` use a stricter throttle because they enqueue real work. Both throttle rates come from `config('sync.throttle.*')`.
- Responses go through `app/Http/Resources/*` and `app/Support/ApiResponse`; request validation (including allow-listed sort/filter fields) lives in `app/Http/Requests/Api/*`. The sync dashboard page is `resources/js/pages/sync/dashboard.tsx`.

### Starter-kit shell

- **Routing**: Inertia pages are wired directly in `routes/*.php` via `Route::inertia(...)` for static pages, or standard controller routes for pages needing server logic. `routes/web.php` is the entrypoint and pulls in `routes/settings.php`. There is no `routes/api.php` yet.
- **Auth**: Handled by Fortify (`app/Providers/FortifyServiceProvider.php`), not a hand-rolled auth controller — Fortify actions live under Laravel's own package, and views are overridden via Inertia pages in `resources/js/pages/auth/`. Passkey support is present (`@laravel/passkeys`, `.well-known/passkey-endpoints` route in `routes/settings.php`).
- **Frontend pages**: `resources/js/pages/**` — one file per Inertia route, rendered via `Inertia::render()`/`Route::inertia()`. Layouts live in `resources/js/layouts/{app,auth,settings}`. Shared UI primitives (shadcn-style) are in `resources/js/components/ui`.
- **Wayfinder**: `resources/js/actions/**` and `resources/js/routes/**` are generated from PHP controllers/routes (via the `wayfinder` Vite plugin and `laravel/wayfinder` composer package) — do not hand-edit these; regenerate by re-running the dev build. Import controller actions from `@/actions/...` and named routes from `@/routes/...` instead of constructing URLs by hand.
- **Middleware**: Global web middleware (appearance handling, Inertia request handling, preload link headers) is registered centrally in `bootstrap/app.php` rather than `app/Http/Kernel.php` (Laravel 13's streamlined bootstrap).
- **Boost/AI tooling**: `.cursor/skills/` and the Laravel Boost MCP server provide domain-specific guidance (Laravel best practices, Inertia+React patterns, Fortify, Wayfinder, Tailwind) — these are activated as skills rather than duplicated here.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/mcp (MCP) - v0
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- laravel/wayfinder (WAYFINDER) - v0
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
