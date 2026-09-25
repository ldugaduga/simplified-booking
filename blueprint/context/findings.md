# Findings

> **Generated file.** The findings ledger: review findings raised by `/audit`
> against the work in progress, each with a durable ID, severity (P0-P3), and
> status. `/implement` marks repaired findings `fixed`, a later `/audit` pass
> moves them to `closed`, and `/complete` refuses to merge while any P0 or P1
> finding is `open` or `fixed`, then archives resolved findings with the work
> and resets this file.

### F-03 [P2] unverified - Concurrent SQLite bookings may surface a lock error instead of the friendly slot-taken error

**File:** app/Http/Controllers/BookingController.php:78
**Found:** 2026-09-24 by /audit independent (scope: current; lens: security, performance)
**Why it matters:** The spec relies on the `slot_lock` unique index turning a same-slot race into `UniqueConstraintViolationException`. The app uses SQLite with `busy_timeout` and `journal_mode` unset (config/database.php) and Laravel's default deferred transactions. Two truly concurrent `DB::transaction` calls that both read before writing can make the second writer fail with `SQLITE_BUSY` ("database is locked", a generic `QueryException`) rather than a unique violation, producing a 500. The feature test (tests/Feature/Booking/BookAppointmentTest.php:85) simulates the race sequentially with a mocked `SlotService`, so it cannot observe this. Not reproduced here: the review was limited to existing commands.
**Suggested fix:** Validate with a real two-process concurrency test or manual check. If confirmed, set a non-zero SQLite `busy_timeout` (and consider `transaction_mode` IMMEDIATE / WAL) so the second writer waits and then hits the unique index, or also map the lock `QueryException` to the same `start_at` error.
**Resolution:**

### F-05 [P2] open - ProductionUrlSchemeTest is not hermetic; it depends on the ambient project `.env`

**File:** tests/Feature/ProductionUrlSchemeTest.php:11
**Found:** 2026-09-25 by /audit current (scope: current; lens: tests)
**Why it matters:** Unlike the rest of the suite, which runs in-process against the testing kernel with `RefreshDatabase`, this test spawns a real `php artisan tinker` subprocess and only overrides `APP_ENV`, `APP_URL`, `APP_KEY`, and `DB_*`. Every other setting (mail, session, queue, cache, business settings) is read from whatever real `.env` file happens to exist on disk at `base_path()`. A fresh clone has no `.env` until `cp .env.example .env` runs, so this test would fail there with an opaque subprocess/tinker error instead of a clear setup message, and it is not isolated from developer-machine or CI-runner environment drift the way the rest of the suite is.
**Suggested fix:** Either skip this test when `.env` is absent with an explicit message, or pass a minimal explicit env set (including `SESSION_DRIVER=array`, `CACHE_STORE=array`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, matching `phpunit.xml`) so the subprocess does not depend on the ambient file at all.
**Resolution:** Re-examined 2026-09-25 by `/audit independent current` (scope: current; lens: quality, security, performance, tests): confirmed still present and unrepaired; left `open` at P2 (does not block this receipt).
