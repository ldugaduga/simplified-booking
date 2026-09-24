# Fix: Strict ISO instants and booking page test

**Type:** Fix
**Status:** verified
**Branch:** fix/strict-iso-instants-and-booking-page-test
**Fixes:** F-01, F-02

## The problem

1. **F-01 (P3):** `GET /slots` (`start`, `end`) in `app/Http/Controllers/BookingController.php` and `POST /book` (`start_at`) in `app/Http/Requests/StoreBookingRequest.php` validate with Laravel's `date` rule. That also accepts values with no offset (`2030-01-07`, `2030-01-07 10:00`, `January 7 2030`), which are then read as UTC. The feature 4 contract requires ISO-8601 instants with an offset or `Z`, and a 422 otherwise.
2. **F-02 (P3):** nothing asserts that `/` renders the `booking/Book` Inertia component with exactly its three props (`businessName`, `slotMinutes`, `maxDaysAhead`). A renamed prop or a leaked extra prop would go unnoticed; existing tests only check for a 200.

## The fix

- Replace `date` with `date_format:Y-m-d\TH:i:sp,Y-m-d\TH:i:s.vp,Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP` on `start`, `end` and `start_at`. Laravel's `date_format` round-trips the value, and only `p` prints `Z` while only `P` prints `+00:00`, so both are needed. The `.v` variants cover the browser's `toISOString()` milliseconds (`…T02:30:00.000Z`), which `Book.vue` sends to `/slots`. (Corrected during `/implement`: the originally planned two formats rejected `…Z`.) This was checked locally:
  - accepted: `2026-10-14T02:30:00Z`, `2026-10-14T02:30:00.000Z`, `2026-10-14T10:30:00+08:00`
  - rejected: `2026-10-14`, `2026-10-14 02:30:00`
- Keep `after:start` on `end` and every other rule unchanged. Keep the existing `CarbonImmutable::parse(...)->utc()` handling, which reads all accepted formats correctly.
- Must not break:
  - the client's `/slots` requests (millisecond `Z` strings)
  - the booking payload (`start_at` is a server-issued `…Z` string)
  - the 422 JSON shape for `/slots`
  - the `start_at` error key for `/book`
  - the rate-limit counting, which happens before validation
- Add one test asserting the `/` Inertia component and its exact props.
- No new dependency, config or abstraction.

## Build steps

- [x] **1. Strict instant validation.** Update the three rules. In `tests/Feature/Booking/SlotsEndpointTest.php`, add these cases:
  - a millisecond `Z` window is accepted
  - an offset-less `start` (`2030-01-07T00:00:00`) returns 422 on `start`
  - a date-only `end` (`2030-01-08`) returns 422 on `end`

  In `tests/Feature/Booking/BookAppointmentTest.php`, add an offset-less `start_at` (`2030-01-07 02:00:00`) that is rejected with an error on `start_at`, and assert nothing is stored.
  - Done when: the new cases pass and all existing booking tests still pass (`php artisan test --filter=Booking`).
- [x] **2. Booking page render test.** Add `tests/Feature/Booking/BookingPageTest.php`, asserting that `GET /` renders `booking/Book` with `businessName` = `config('app.name')`, `slotMinutes` and `maxDaysAhead` from `Setting::current()`, and no page-specific props beyond those three: the page's prop keys minus the shared props from `HandleInertiaRequests` (`name`, `quote`, `auth`, `ziggy`, `errors`) must equal exactly `businessName`, `slotMinutes`, `maxDaysAhead`.
  - Done when: the test passes, and temporarily renaming a prop in `BookingController@show` makes it fail (checked locally, then reverted).

## Verify

- `php artisan test` passes (65 existing + new cases), and `npm run build` passes.
- Manual: open http://localhost:8000. The calendar still loads times. That proves the client's millisecond `/slots` requests are still accepted.
- `curl -s -H 'Accept: application/json' 'http://localhost:8000/slots?start=2030-01-07&end=2030-01-08'` returns 422 with errors on `start` and `end`.
- `/implement` marks F-01 and F-02 `fixed` when their steps land. A later `/audit` pass moves them to `closed`.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":3822,"specSha256":"ffba206c5581aa90b6ed1aa2956afd3a27da9db0c54551db7eebb7933850befd","branch":"refs/heads/fix/strict-iso-instants-and-booking-page-test","head":"aaa57b292eeecfe926d2eabe7368704f6b884609","baseRef":"refs/heads/main","baseCommit":"aaa57b292eeecfe926d2eabe7368704f6b884609","sourceTree":"9390e450b5cbb9246a6a56936eb4f943c88f1561","absentOptional":[]} -->
