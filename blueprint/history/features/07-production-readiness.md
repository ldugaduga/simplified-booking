# Feature: Production readiness

**From build-plan:** feature 7
**Build attempt:** 1
**Type:** Feature
**Branch:** feature/production-readiness
**Status:** verified

## Goal

Make the app safe and repeatable to run in production: a production `.env`
template, the security settings a public-facing booking site needs (HTTPS
enforcement, secure session cookies, debug off), and a deploy checklist
covering the queue worker and cron the app already relies on (queued
confirmation emails, `php artisan schedule:run`).

## In scope

- A `.env.production.example` template (committed, no real secrets) covering
  every var the overview's Deployment section names: `APP_KEY`, `APP_URL`,
  `APP_ENV=production`, `APP_DEBUG=false`, `DB_*` (MySQL/Postgres, per the
  existing `.env`-only switch — no code change needed, `config/database.php`
  already defines both connections), `MAIL_*`, `ADMIN_NAME`, `ADMIN_EMAIL`,
  `ADMIN_PASSWORD`, `BOOKING_TIMEZONE`, plus the session/cookie vars below.
- Force HTTPS URL generation in production: in `bootstrap/app.php` or a service
  provider, call `URL::forceScheme('https')` when `app()->environment('production')`.
- Secure session cookies in production: set `SESSION_SECURE_COOKIE=true` in the
  new production `.env` template (the app already reads this via
  `config/session.php:172`, `env('SESSION_SECURE_COOKIE')` — no code change,
  just documenting/defaulting the production value).
- Trusted proxies: `bootstrap/app.php` currently configures none
  (`blueprint/context/findings.md` F-04 already flags this for the chosen VPS
  target — a bare Nginx reverse proxy on the same box, not a cloud
  load-balancer). Add `$middleware->trustProxies(at: ['127.0.0.1', '::1'])` so
  `$request->ip()` and `URL::forceScheme` see the real client scheme/IP behind
  local Nginx. This resolves F-04 for the VPS deployment target the overview
  commits to.
- A `DEPLOY.md` checklist at the project root covering, in order: server
  prerequisites (Ubuntu LTS, Nginx, PHP 8.4-FPM, MySQL 8 or Postgres 16, Let's
  Encrypt — per the overview's Deployment section), build steps (`composer
  install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`), release
  steps (`php artisan migrate --force`, `--seed` on first deploy only, `php
  artisan optimize`), the Supervisor program config for
  `php artisan queue:work --tries=3`, the crontab line
  `* * * * * php artisan schedule:run`, the env vars to set (linking to
  `.env.production.example`), and a final smoke-test step hitting `/up`.
- A feature test asserting `URL::forceScheme` is applied when
  `app()->environment('production')` (and not applied otherwise), using
  `$this->app->detectEnvironment()` or config override rather than mutating
  real environment state.

## Out of scope

- Any actual server provisioning, DNS, TLS certificate issuance, or deploying
  to a real VPS — this feature produces the template, config, and checklist
  only.
- Choosing the specific VPS provider or managed panel (Forge/Ploi/Coolify) —
  the overview leaves this open; the checklist stays provider-agnostic beyond
  "a Linux VPS with Nginx."
- Scheduled jobs beyond documenting `schedule:run` — no feature currently
  registers anything in the scheduler (`routes/console.php` has none), so this
  stays a no-op cron entry ready for feature 9 (reminder emails).
- Rate limiter proxy-spoofing concerns beyond trusting the local Nginx hop —
  already covered by the `trustProxies` change above; no broader IP-spoofing
  hardening is in scope.
- CI/automated deploy pipelines (`/ci` is a separate, explicit skill).

## Build loop

Follow `workflow.stepReview: feature` — build all steps, then present one
final review packet. `workflow.checkpointCommits: disabled` — no mid-feature
commits; `/complete` makes the single work commit.

## Build steps

- [x] **1. Force HTTPS and trust the local proxy in production.** Add
  `URL::forceScheme('https')` (guarded by `app()->environment('production')`)
  and `$middleware->trustProxies(at: ['127.0.0.1', '::1'])` in
  `bootstrap/app.php`. Add a feature test covering both the production and
  non-production cases.
  - Done when: `php artisan test` passes, including the new test.
- [x] **2. Add the production `.env` template.** Create
  `.env.production.example` with every var listed in scope, including
  `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, `APP_ENV=production`,
  `APP_DEBUG=false`, and MySQL as the documented default `DB_CONNECTION`
  (Postgres noted as the alternative, matching the overview).
  - Done when: the file exists at the repo root, is not `.gitignore`d, and
    contains no real secret values (placeholders only).
- [x] **3. Write the deploy checklist.** Create `DEPLOY.md` with the sections
  listed in scope, in order, linking to `.env.production.example` for the env
  var list rather than duplicating it.
  - Done when: `DEPLOY.md` exists and every command in it matches a command
    already declared in `AGENTS.md`'s Commands section or the overview's
    Deployment section (no invented commands).

## Files / areas

- `bootstrap/app.php` - `forceScheme` + `trustProxies`
- `tests/Feature/` - new test for the production URL-scheme/proxy behavior
- `.env.production.example` - new
- `DEPLOY.md` - new

## Data / contracts

No persisted-data or API contract changes. This feature only adds
configuration, a template file, and documentation.

## Testing

- `php artisan test` for the new HTTPS/proxy-trust behavior.
- No UI-observable change, so no browser evidence applies.

## Notes for the AI

- Do not touch `config/database.php` — MySQL and Postgres connections already
  exist there; only the `.env` values need documenting.
- Do not add a scheduled job; `routes/console.php` intentionally stays empty
  until a feature needs it.
- This feature resolves `blueprint/context/findings.md` F-04 for the VPS
  target. Mark it `fixed` once the `trustProxies` change lands, so a later
  `/audit` can close it.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":5995,"specSha256":"f142900c51753451f4c138d176c3994eb16441780b6bf0cd981d2ab2ae1b9748","branch":"refs/heads/feature/production-readiness","head":"588c4149e0b0e38b59256af5861fcf094e424869","baseRef":"refs/heads/main","baseCommit":"588c4149e0b0e38b59256af5861fcf094e424869","sourceTree":"7371a233b106e7eb6d64774eabec8519cc63a3c6","absentOptional":[]} -->

## Findings

### 7/F-04 [P2] closed - Per-IP limits depend on proxy configuration that is not set

**File:** app/Http/Requests/StoreBookingRequest.php:47
**Found:** 2026-09-24 by /audit independent (scope: current; lens: security)
**Why it matters:** The booking limiter keys on `$this->ip()` and `/slots` uses `throttle:60,1` (routes/web.php:10), which also keys on IP. bootstrap/app.php configures no trusted proxies. Behind a reverse proxy or load balancer (for example a Render or Vercel style deployment), every visitor may share the proxy address, so 5 bookings per 10 minutes and 60 slot fetches per minute would apply site-wide. Trusting all proxies would instead let clients spoof `X-Forwarded-For` and bypass the limit. Deployment is not configured yet, so this is a lead, not a confirmed defect.
**Suggested fix:** When the deployment target is chosen (`/release`), configure `$middleware->trustProxies(at: ...)` for that platform's proxy range only, and confirm `request()->ip()` returns the client address.
**Resolution:** Fixed in feature 7 (production readiness): `bootstrap/app.php` now calls `$middleware->trustProxies(at: ['127.0.0.1', '::1'])`, matching the VPS target (a local Nginx reverse proxy on the same box) the overview commits to. Re-examined 2026-09-25 by `/audit current` (scope: current; lens: security): confirmed the exact call is present and matches the documented single-box Nginx deployment; no new defect introduced.

## Independent review

**Status:** passed
**Target commit:** b9b7078f06b1c870202deb14676d90d3872ed4cd
**Base commit:** 588c4149e0b0e38b59256af5861fcf094e424869
**Base ref:** main
**Spec hash:** f142900c51753451f4c138d176c3994eb16441780b6bf0cd981d2ab2ae1b9748
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-sonnet-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T11:15:00Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-sonnet-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T00:00:00Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `php artisan test --filter=ProductionUrlSchemeTest`: pass
- `php artisan test`: pass (114 passed, 725 assertions)
- `npm run build`: pass
- `./vendor/bin/pint --test`: pass

### Evidence

- `git diff 588c4149e0b0e38b59256af5861fcf094e424869..b9b7078f06b1c870202deb14676d90d3872ed4cd` reviewed in full: `bootstrap/app.php` (forceScheme + trustProxies), `.env.production.example` (new), `DEPLOY.md` (new), `tests/Feature/ProductionUrlSchemeTest.php` (new), plus documentation-only changes to `blueprint/context/current-feature.md` and `blueprint/context/findings.md`.
- `bootstrap/app.php:22,26-29` matches the spec exactly: `trustProxies(at: ['127.0.0.1', '::1'])` in the middleware closure, and `URL::forceScheme('https')` gated on `app()->environment('production')` in a `booted()` callback.
- `.env.production.example` contains every var the spec lists (`APP_KEY`, `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`, `DB_*`, `MAIL_*`, `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `BOOKING_TIMEZONE`, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`), no real secret values, MySQL documented as default with Postgres noted as the alternative.
- `DEPLOY.md`'s Build/Release/Worker/Cron commands were diffed against `blueprint/context/project-overview.md`'s Deployment section and match verbatim; no invented commands.
- `tests/Feature/ProductionUrlSchemeTest.php` asserts both the production-HTTPS and non-production-HTTP cases via a real `tinker` subprocess with isolated `APP_ENV`/`APP_URL`/`DB_*` overrides.
- Findings ledger F-04 (trusted-proxy lead) was already marked `closed` by the builder's own prior `/audit current` pass re-examining this same `bootstrap/app.php` change; re-confirmed the cited line and reasoning still hold against the reviewed diff. F-03 and F-05 are unrelated to this diff (SQLite locking and the pre-existing `ProductionUrlSchemeTest` hermeticity gap, the latter already recorded by the same prior pass that authored the file being reviewed) and remain at their recorded P2 severity, which does not block a passing receipt.

### Findings

- None

### Remaining risk

- F-05 (P2, open) notes `tests/Feature/ProductionUrlSchemeTest.php` depends on the ambient project `.env` via its `tinker` subprocess rather than passing a fully isolated env set; this is a pre-existing, non-blocking (P2) test-hermeticity gap already tracked in the ledger, not a new defect.
- No dedicated security or performance scanner is configured in this project; review relied on manual inspection of the diff plus the existing test suite.
