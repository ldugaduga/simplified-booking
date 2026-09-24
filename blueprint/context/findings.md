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

### F-04 [P2] unverified - Per-IP limits depend on proxy configuration that is not set

**File:** app/Http/Requests/StoreBookingRequest.php:47
**Found:** 2026-09-24 by /audit independent (scope: current; lens: security)
**Why it matters:** The booking limiter keys on `$this->ip()` and `/slots` uses `throttle:60,1` (routes/web.php:10), which also keys on IP. bootstrap/app.php configures no trusted proxies. Behind a reverse proxy or load balancer (for example a Render or Vercel style deployment), every visitor may share the proxy address, so 5 bookings per 10 minutes and 60 slot fetches per minute would apply site-wide. Trusting all proxies would instead let clients spoof `X-Forwarded-For` and bypass the limit. Deployment is not configured yet, so this is a lead, not a confirmed defect.
**Suggested fix:** When the deployment target is chosen (`/release`), configure `$middleware->trustProxies(at: ...)` for that platform's proxy range only, and confirm `request()->ip()` returns the client address.
**Resolution:**
