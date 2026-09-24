# Feature: Admin appointments

**From build-plan:** feature 5
**Build attempt:** 1
**Branch:** feature/admin-appointments
**Status:** verified

## Goal

Turn the `/admin` placeholder into the admin's appointments dashboard. It lists upcoming and past appointments with tab, status, date, and search filters, plus three small counts. The admin can create an appointment for someone, reschedule one, or cancel one. The admin may book inside the minimum-notice window. Every other availability rule still applies: working hours, blocked dates, booking window, no overlaps, not in the past. Cancelling changes status and never deletes.

## Design reference

- `prototypes/admin-dashboard.html` - stat tiles, Upcoming/Past/All tabs, search + status + date filters, table grouped by day, muted cancelled rows, row actions, reschedule dialog, pagination footer
- Admin layout stays the existing shadcn sidebar (`AppLayout`); theme tokens already live in `resources/css/app.css`

## In scope

- `SlotService::availableSlots()` gains two optional arguments: ignore minimum notice, and exclude one appointment
- `GET /admin` (route name `dashboard`, unchanged) → `Admin\AppointmentController@index`: filtered, paginated list + counts; replaces the `Dashboard.vue` placeholder
- `GET /admin/slots?date=` → open slots for one admin-timezone day, ignoring minimum notice (optionally excluding the appointment being rescheduled)
- `POST /admin/appointments` (create), `PATCH /admin/appointments/{appointment}/reschedule`, `PATCH /admin/appointments/{appointment}/cancel`
- `admin/Appointments.vue`: table, filters, pagination, counts, empty states, and the New appointment, Reschedule and Cancel dialogs
- Sidebar and header nav label "Dashboard" → "Appointments"
- Feature tests for the slot options, listing/filtering, and the three mutations

## Out of scope

- All email, including the mockup's "Email <name> about the new time" checkbox (features 6 and 10)
- Visitor self-service via `manage_token` (feature 8), calendar view (feature 13), meeting types (feature 11)
- Hard delete of appointments; editing name, email or notes after creation
- Un-cancelling a cancelled appointment
- The mockup's "hours booked" and "% of bookings" sub-lines (the counts only show numbers)
- Any schema change

## Build loop

- `workflow.stepReview` is `feature`: build all steps, then present one review packet at the end.
- `workflow.checkpointCommits` is `disabled`: no commits during `/implement`. `/complete` creates the feature commit.
- Every step leaves `php artisan test` green and `npm run build` passing.

## Build steps

- [x] **1. SlotService options.** Change the signature to `availableSlots(CarbonImmutable $from, CarbonImmutable $to, bool $ignoreMinimumNotice = false, ?int $exceptAppointmentId = null)`.
  - When ignoring notice, the earliest allowed start is `now()` (never the past).
  - The excluded appointment does not block slots.

  Existing callers keep their current behaviour. Add the cases to `tests/Feature/SlotServiceTest.php`.
  - Done when: the new cases pass, and all existing `SlotServiceTest` and `Booking` tests pass unchanged.
- [x] **2. Listing endpoint.** Add `App\Http\Controllers\Admin\AppointmentController@index` on `GET /admin` (keep the name `dashboard`), rendering `admin/Appointments` with the props under Data / contracts.
  - Replace `resources/js/pages/Dashboard.vue` with a minimal `admin/Appointments.vue`.
  - Update `tests/Feature/DashboardTest.php` to assert the new component.
  - Add `tests/Feature/Admin/AppointmentListTest.php`.
  - Done when: the tests prove the tab ordering, the status, date and search filters, pagination (20 per page), the counts, guest redirect, and that `manage_token` and `slot_lock` never appear in props.
- [x] **3. Admin slots, create, reschedule, cancel.** Add the `slots`, `store`, `reschedule` and `cancel` actions, the `StoreAppointmentRequest` and `RescheduleAppointmentRequest` Form Requests, and routes.
  - Create and reschedule re-check the slot inside a transaction using `SlotService` with notice ignored; reschedule also excludes itself.
  - Both map a `slot_lock` unique violation to a `start_at` validation error.
  - Add `tests/Feature/Admin/AppointmentActionsTest.php`.
  - Done when: the cases under Testing pass.
- [x] **4. Appointments list UI.** Build `admin/Appointments.vue` to match `prototypes/admin-dashboard.html`:
  - count tiles; Upcoming/Past/All tabs
  - search, status and date filters driving the query string (Inertia `router.get` with `preserveState`, search debounced 300 ms)
  - the table grouped by local day, with cancelled rows muted and struck through
  - Previous/Next pagination
  - empty states: "No upcoming appointments yet." or "No appointments match these filters." with a Clear filters button

  Rename the nav item to "Appointments".
  - Done when: `npm run build` passes; screenshots show the list with data, each tab, an active filter, both empty states, and light plus narrow widths.
- [x] **5. Create, reschedule, cancel dialogs.** Add a "New appointment" button and dialog, per-row Reschedule and Cancel actions (confirmed rows only), and their dialogs.
  - Create and Reschedule have a date input, then a time select loaded from `GET /admin/slots`, with loading, "No open times on this day." and error states.
  - Cancel is a confirmation dialog that names the person and time.
  - Show server errors inline. On success, close the dialog and show the refreshed list.
  - Done when: `npm run build` passes, `php artisan test` is green, and screenshots show an admin-created appointment inside the notice window, a successful reschedule (old time freed, new time shown), a cancel (row muted), and the inline "no longer available" error.

- [x] **6. Review repairs (F-05, F-06, F-08).** From the independent review of checkpoint `bf4f03e`:
  - reschedule checks the whole kept duration (plus buffer) for overlaps with other active appointments, in the transaction
  - reschedule re-reads the appointment and checks `confirmed` inside the transaction
  - search escapes `%` and `_` with a portable `ESCAPE '!'`
  - Done when: the regression tests for F-05 and F-06 fail on `bf4f03e` and pass now, `php artisan test` is green, and F-05, F-06 and F-08 are marked `fixed` in the ledger.

## Files / areas

- `app/Services/SlotService.php` - two optional parameters
- `app/Http/Controllers/Admin/AppointmentController.php` - new: `index`, `slots`, `store`, `reschedule`, `cancel`
- `app/Http/Requests/Admin/StoreAppointmentRequest.php`, `RescheduleAppointmentRequest.php` - new
- `routes/web.php` - replace the `/admin` closure; add admin appointment routes
- `resources/js/pages/admin/Appointments.vue` - new; `resources/js/pages/Dashboard.vue` - deleted
- `resources/js/components/AppSidebar.vue`, `AppHeader.vue` - nav label
- `resources/js/types/index.ts` - `Appointment` row and paginator types
- Tests: `tests/Feature/SlotServiceTest.php` (extend), `tests/Feature/DashboardTest.php` (update), `tests/Feature/Admin/AppointmentListTest.php`, `tests/Feature/Admin/AppointmentActionsTest.php` (new)
- Reuse: `BookingController::INSTANT` for `start_at`, `Appointment` scopes and hooks, `ui/dialog` components
- Added during `/implement`:
  - `resources/js/components/AppointmentSlotPicker.vue`: the shared date + time picker used by the create and reschedule dialogs
  - `app/Models/Setting.php` + `tests/Feature/SettingTest.php`: `Setting::current()` now reloads a freshly created row so column defaults (for example `timezone`) are present. This was a latent bug from feature 2 that surfaced as a 500 on `/admin` with an empty settings table.

## Data / contracts

### Routes (inside the existing `auth` + `verified`, prefix `/admin` group)

| Method | Path | Name | Action |
|---|---|---|---|
| GET | `/admin` | `dashboard` | `index` → Inertia `admin/Appointments` |
| GET | `/admin/slots` | `admin.slots` | `slots` → JSON |
| POST | `/admin/appointments` | `admin.appointments.store` | `store` |
| PATCH | `/admin/appointments/{appointment}/reschedule` | `admin.appointments.reschedule` | `reschedule` |
| PATCH | `/admin/appointments/{appointment}/cancel` | `admin.appointments.cancel` | `cancel` |

Only the authenticated admin can reach these routes; there is a single admin account and no ownership to scope. Mutations redirect `back()` with errors keyed by field.

### `index` query parameters (all optional; invalid values fall back to defaults)

- `tab`:
  - `upcoming` (default): `end_at >= now`, ascending by `start_at`
  - `past`: `end_at < now`, descending
  - `all`: descending
- `status`: `confirmed` | `cancelled`; omitted means both
- `date`: `YYYY-MM-DD`, one day in `settings.timezone` (converted to a UTC range)
- `q`: case-insensitive substring match on `name` or `email`, trimmed, max 100 characters
- `page`: Laravel pagination, 20 per page, with the query string kept

### `admin/Appointments` props

- `appointments`: Laravel paginator JSON. Each row is `{ id, start_at, end_at (UTC ISO Z), status, name, email, notes, created_by_admin: bool }`. Never `manage_token` or `slot_lock`.
- `filters`: `{ tab, status, date, q }` (the normalized values in use)
- `counts`: `{ today, thisWeek, cancelledLast30Days }`, all computed in `settings.timezone`:
  - `today`: confirmed with `start_at` on today's local date
  - `thisWeek`: confirmed with `start_at` in the current Monday-Sunday local week
  - `cancelledLast30Days`: cancelled with `updated_at` in the last 30 days
- `timezone`: `settings.timezone` (the UI formats every time in this zone and labels it)
- `slotMinutes`: current meeting length

### `GET /admin/slots?date=YYYY-MM-DD[&except=<appointment id>]`

- `date` required `date_format:Y-m-d`; `except` optional integer that must exist in `appointments`. Otherwise 422 JSON.
- Returns `{ "slots": ["…Z", ...] }` for that local day in `settings.timezone`, via `SlotService` with `ignoreMinimumNotice: true` and `exceptAppointmentId: except`.

### `POST /admin/appointments` (`StoreAppointmentRequest`)

- `start_at` (`BookingController::INSTANT`), `name` (required, max 255), `email` (required, `email:rfc,filter`, max 255), `notes` (nullable, max 1000)
- In a transaction:
  - require `start_at` among `availableSlots(start, start + 1 min, ignoreMinimumNotice: true)`, otherwise the error "That time is no longer available. Please pick another." on `start_at`
  - create a `confirmed` appointment with `end_at = start + slot_minutes` and `created_by = auth()->id()`
- Catch `UniqueConstraintViolationException` and return the same error.

### `PATCH /admin/appointments/{appointment}/reschedule` (`RescheduleAppointmentRequest`)

- `start_at` (`INSTANT`). The appointment must be `confirmed`; otherwise the error "Only confirmed appointments can be rescheduled." on `start_at`.
- In a transaction:
  - require the slot open with `ignoreMinimumNotice: true` and `exceptAppointmentId: appointment id`
  - update `start_at` and `end_at` (keeping the appointment's current duration). `slot_lock` follows via the model hook.
- Unique violation or slot not open → the "no longer available" error.

### `PATCH /admin/appointments/{appointment}/cancel`

- Sets `status = cancelled` (`slot_lock` becomes null through the hook). Cancelling an already-cancelled appointment is a no-op success.

## Testing

Run `php artisan test` (after `npm run build`). Freeze time with `travelTo`. Use `Asia/Manila` settings and Mon-Fri 09:00-17:00 rules, as in the existing tests.

**`SlotServiceTest` (extend)**
- With a 4h notice at 09:10, `ignoreMinimumNotice` returns 09:30 as the first slot, while the default still returns 13:30. Past times are never returned.
- `exceptAppointmentId` frees that appointment's slot (and its buffer); another appointment still blocks.

**`AppointmentListTest`**
- Guest is redirected to `/login`.
- Upcoming is ascending and excludes ended appointments; past is descending; all includes both.
- The status filter, the date filter (local-day boundaries in Manila), and the `q` match on name and on email each narrow the results.
- 25 appointments give a page 1 of 20 and a page 2 of 5, with the query string kept.
- Counts: today, this week (Monday-start), and cancelled in the last 30 days match fixtures placed on and outside each boundary.
- Invalid `tab`/`status`/`date` fall back to defaults without an error.
- Props never contain `manage_token` or `slot_lock`.

**`AppointmentActionsTest`**
- `/admin/slots` returns slots inside the notice window, validates `date`, and `except` frees the given appointment's slot.
- Create inside the notice window succeeds with `created_by` = admin. Create on a blocked date, off the grid, in the past, or on a taken slot fails with the `start_at` error. Name and email are validated.
- A simulated create race (`SlotService` mocked, slot already taken) returns the friendly error, not a 500.
- Reschedule moves the times, frees the old slot and keeps the duration. Moving to its own current slot succeeds. Rescheduling a cancelled appointment fails. A taken target fails.
- Cancel sets `cancelled` and clears `slot_lock`, so the same slot can be booked publicly again. A repeated cancel is a no-op. Guests are redirected for all three mutations.

UI is verified with screenshots in steps 4 and 5; they need the admin logged in (use Chrome, as in feature 3).

## Notes for the AI

- Follow `coding-standards.md`: Form Requests, typed returns, named routes, `<script setup lang="ts">`, `interface Props`, theme tokens only, and `{{ }}` for all names, emails and notes (never `v-html`).
- Format times with `Intl.DateTimeFormat` in the `timezone` prop, not the browser zone. Show "Times in Asia/Manila" near the filters.
- Row grouping: header rows per local day ("Today · Wednesday, Oct 7", otherwise "Thursday, Oct 8"). An admin-created row shows "· by admin".
- Dialogs use `ui/dialog`, trap focus, and return focus to the trigger. Each form uses `useForm`. Disable submit while `processing`; clear errors on reopen.
- The time select is labelled and lists times in the admin zone. The current time is marked "(current)" in Reschedule. Fetch `/admin/slots` with `Accept: application/json` and ignore stale responses.
- Cancel confirmation copy: "Cancel <name>'s appointment on <date> at <time>? The time becomes available to book again." Buttons: "Keep appointment" / "Cancel appointment" (destructive variant).
- Keep `DashboardTest` meaningful (component check), and keep login's `redirect()->intended(route('dashboard'))` working.
- Do not touch the public booking flow except through the backward-compatible `SlotService` signature.

## Open questions

These don't block the build; the spec uses the values shown:

1. **"This week":** Monday to Sunday in the admin's timezone.
2. **Cancelled count:** measured by `updated_at` in the last 30 days, since there is no `cancelled_at` column and adding one is a schema change.
3. **Admin override:** the admin skips only minimum notice. Working hours, blocked dates and the booking window still apply, so there are no off-hours bookings. Confirm whether the admin should also be allowed off-hours or blocked-date bookings (a larger change).
4. **Page size:** 20 per page.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":15236,"specSha256":"826b432c24d3c80d26223237921bae99dd1f511574a1edbdfcab58b3f2500e15","branch":"refs/heads/feature/admin-appointments","head":"3ba3613a2805d950d8a3b382b2de858469876069","baseRef":"refs/heads/main","baseCommit":"5dd04dad188c63900a7eeb43ccc7321da4449ef8","sourceTree":"d05a21e034df9bbe46b3479d38e07286747180a5","absentOptional":[]} -->

## Findings

#### 5/F-01 [P3] closed - Date inputs accept non-ISO and offset-less values

**File:** app/Http/Controllers/BookingController.php:49
**Found:** 2026-09-24 by /audit independent (scope: current; lens: quality, security)
**Why it matters:** The spec requires `start`/`end` (and `start_at`) to be ISO-8601 instants with an offset or `Z`, otherwise 422. Laravel's `date` rule (strtotime plus checkdate) also accepts values such as `2030-01-07` or `January 7 2030 10:00`, which are then parsed in the app timezone (UTC). The same rule is used for `start_at` in app/Http/Requests/StoreBookingRequest.php:24. Impact is low because the client always sends `Z` instants and the slot grid check still guards bookings, but the contract is looser than specified.
**Suggested fix:** Replace `date` with an explicit instant format, for example `date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z` (or an ISO-8601 regex), on `start`, `end` and `start_at`, and add one 422 case for an offset-less value.
**Resolution:** Fixed on fix/strict-iso-instants-and-booking-page-test: `start`, `end` and `start_at` now use `BookingController::INSTANT` (date_format with `p`/`P` and `.v` variants); tests cover offset-less and date-only values returning 422 and millisecond `Z` accepted. Awaiting `/audit` re-review. Closed 2026-09-24 by /audit independent (feature 5 review, target bf4f03e): `BookingController::INSTANT` rejects offset-less and date-only values, is reused by `StoreBookingRequest` and the new admin Form Requests, and tests/Feature/Booking/SlotsEndpointTest.php:76 and BookAppointmentTest.php:85 pass; no new defect from the repair.

#### 5/F-02 [P3] closed - No test asserts the public booking page render and props

**File:** app/Http/Controllers/BookingController.php:32
**Found:** 2026-09-24 by /audit independent (scope: current; lens: tests)
**Why it matters:** `BookingController@show` replaced the `/` closure and passes `businessName`, `slotMinutes` and `maxDaysAhead`. Existing tests (tests/Feature/ExampleTest.php:14, tests/Feature/Auth/AdminOnlyAccessTest.php:39) only assert `/` returns 200; nothing checks the `booking/Book` component or its props, so a prop rename or leak of extra data would go unnoticed.
**Suggested fix:** Add one `assertInertia` test in tests/Feature/Booking/ asserting component `booking/Book` and exactly the three props.
**Resolution:** Fixed on fix/strict-iso-instants-and-booking-page-test: tests/Feature/Booking/BookingPageTest.php asserts component `booking/Book`, the three prop values, and that no page-specific props exist beyond them (shared props excluded); a temporary prop rename made it fail. Awaiting `/audit` re-review. Closed 2026-09-24 by /audit independent (feature 5 review, target bf4f03e): tests/Feature/Booking/BookingPageTest.php asserts `booking/Book`, the three values, and the exact page-prop set; it passes in `php artisan test`; no new defect from the repair.

#### 5/F-05 [P1] closed - Reschedule can overlap a neighbouring appointment when the kept duration exceeds the current slot length

**File:** app/Http/Controllers/Admin/AppointmentController.php:137
**Found:** 2026-09-24 by /audit independent (scope: current; lens: quality, security, tests)
**Why it matters:** `reschedule` keeps the appointment's own duration (line 134, 141) but only checks the target with `ensureOpen`, which asks `SlotService::availableSlots` whether a slot of the *current* `settings.slot_minutes` is free (app/Services/SlotService.php:89-93 uses `$end = $start + $length`). `slot_minutes` is admin-editable (15/30/45/60, app/Http/Requests/Admin/UpdateBookingRulesRequest.php:19), so older appointments can be longer than the grid length. Concrete path: a 60-minute appointment booked before the slot length changed to 30; another confirmed appointment at 11:30 local; buffer 0. Rescheduling the first one to 11:00 passes `ensureOpen` (11:00-11:30 does not clash with 11:30-12:00), then saves 11:00-12:00, overlapping the 11:30 booking. `slot_lock` is unique only on the start instant, so nothing else stops it. This breaks the spec Goal ("no overlaps") and the standard that overlap is checked inside the transaction. The builder's own test (tests/Feature/Admin/AppointmentActionsTest.php:99) already uses a 45-minute appointment on a 30-minute grid, but places no neighbour after the target.
**Suggested fix:** Inside the same transaction, after `ensureOpen`, reject the move when `Appointment::active()->whereKeyNot($appointment->id)->overlapping($start->subMinutes($buffer), $start->addMinutes($duration + $buffer))->exists()`, with the same `start_at` error. Add a test: 45- or 60-minute appointment, a neighbour starting inside the kept duration, reschedule fails and times are unchanged.
**Resolution:** Fixed in `/implement` step 6: `reschedule` now also rejects the move when `Appointment::active()->whereKeyNot(id)->overlapping(start - buffer, start + kept duration + buffer)` exists, inside the transaction. Regression test `test_reschedule_checks_the_whole_kept_duration_for_overlaps` fails on the checkpoint code and passes now. Awaiting `/audit` re-review. Closed 2026-09-24 by /audit independent (feature 5 re-review, target 3ba3613): AppointmentController.php:141-150 checks active non-self appointments over `start - buffer` to `start + kept duration + buffer` inside the same transaction and maps a clash to the slot-taken error; tests/Feature/Admin/AppointmentActionsTest.php:139 covers the neighbour case and the unchanged times; `php artisan test` passes; no new defect from the repair.

#### 5/F-06 [P3] closed - Search treats `%` and `_` in the term as LIKE wildcards

**File:** app/Http/Controllers/Admin/AppointmentController.php:52
**Found:** 2026-09-24 by /audit independent (scope: current; lens: quality)
**Why it matters:** The spec defines `q` as a case-insensitive substring match. The term is bound safely (no injection), but `%` and `_` are not escaped, so `q=_` or `q=%` matches every row and `a_b` matches `axb`. Minor: admin-only and harmless, but the filter does not do what it says. Separately, SQLite `LOWER()` folds only ASCII, while the term uses `mb_strtolower`, so a search for a name with an uppercase non-ASCII letter (for example "Élise") will not match on SQLite.
**Suggested fix:** Escape the escape character, `%` and `_` in the term and add an explicit `ESCAPE` clause to both `LIKE` clauses (a non-backslash character such as `!` stays portable across SQLite, MySQL and Postgres), plus one test that `q=_` does not match everything. Accept or document the non-ASCII case limitation.
**Resolution:** Fixed in step 6: `%`, `_` and the escape character are escaped with `!` and `ESCAPE '!'` (portable to SQLite, MySQL and Postgres; a backslash escape breaks MySQL string literals). Test `test_search_wildcards_match_literally`. Non-ASCII case folding on SQLite is unchanged. Awaiting `/audit` re-review. Closed 2026-09-24 by /audit independent (feature 5 re-review, target 3ba3613): AppointmentController.php:51-56 escapes `!` first, then `%` and `_`, with `ESCAPE '!'` on both bound LIKE clauses; tests/Feature/Admin/AppointmentListTest.php:62 covers `_`, `100%` and `!`; no new defect.

#### 5/F-07 [P3] accepted - Admin search puts visitor names or emails in the query string, against the coding standard

**File:** resources/js/pages/admin/Appointments.vue:82
**Found:** 2026-09-24 by /audit independent (scope: current; lens: security)
**Why it matters:** coding-standards.md (Data and Time) says never put visitor names or emails in logs or query strings. The `q` filter sends the typed name or email as `GET /admin?q=...`, which lands in browser history and any web server or proxy access log. The spec explicitly defines `q` as a query parameter, so this is a spec-versus-standard conflict rather than an implementation slip; the endpoint is admin-only.
**Suggested fix:** User decision: either record an explicit exception for the admin search in coding-standards.md (smallest change, no behaviour change), or keep the search term out of the URL. Do not change shipped behaviour without that decision.
**Resolution:** Accepted by the user on 2026-09-24 (explicit decision in chat). Reason: `/admin` is admin-only behind login, the term only reaches the admin's own browser history and server logs, and a shareable or bookmarkable filtered URL is wanted. Kept as specified (`?q=`).

#### 5/F-08 [P3] closed - Reschedule checks `confirmed` outside the transaction, so a concurrent cancel could leave a cancelled row holding `slot_lock`

**File:** app/Http/Controllers/Admin/AppointmentController.php:129
**Found:** 2026-09-24 by /audit independent (scope: current; lens: security, performance)
**Why it matters:** The status check uses the route-bound model loaded before the transaction. If the same appointment is cancelled (for example from a second admin tab) between that load and `update()`, the `saving` hook recomputes `slot_lock` from the stale in-memory `confirmed` status and writes a non-null lock onto a row the database now has as `cancelled`. That row would then permanently hold the unique `slot_lock` for its new start, so `SlotService` shows the time as open while every booking of it fails with the "no longer available" error. Requires a narrow two-request race by the single admin; not reproduced.
**Suggested fix:** Inside the transaction, reload the row (`$appointment->refresh()`, or `lockForUpdate()` where supported) and re-check `status->isActive()` before `update()`.
**Resolution:** Fixed in step 6: `reschedule` refreshes the appointment and checks `confirmed` inside the transaction, before `ensureOpen` and the update. No concurrent test (not reproducible in the single-process test runner). Awaiting `/audit` re-review. Closed 2026-09-24 by /audit independent (feature 5 re-review, target 3ba3613): AppointmentController.php:130-134 refreshes the row and re-checks `isActive()` inside the transaction before any write, so the stale in-memory status no longer drives the `slot_lock` recompute. No row lock is taken; on the project's SQLite database a concurrent writer is serialised by the database lock, so the residual window is covered by the existing F-03 lead, not a new defect.

## Independent review

**Status:** passed
**Target commit:** 3ba3613a2805d950d8a3b382b2de858469876069
**Base commit:** 5dd04dad188c63900a7eeb43ccc7321da4449ef8
**Base ref:** refs/heads/main
**Spec hash:** 826b432c24d3c80d26223237921bae99dd1f511574a1edbdfcab58b3f2500e15
**Prepared by:** claude
**Builder model:** claude-opus-5-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-24T15:07:45Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-24T15:09:16Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Handoff

Review the active spec and the complete `5dd04dad188c63900a7eeb43ccc7321da4449ef8..3ba3613a2805d950d8a3b382b2de858469876069` delta in a fresh
session or isolated subagent without the builder conversation. Run all Audit lenses from scratch.
Run Check when required above. Do not edit product code, accept findings, or
reuse the existing findings as the review scope.

### Commands

- `php artisan test`: pass (89 tests, 579 assertions)
- `npm run build`: pass
- `./vendor/bin/pint --test`: pass
- `npm run format:check`: pass
- `npx eslint resources/js/pages/admin resources/js/components/AppointmentSlotPicker.vue`: pass

### Evidence

- Verified HEAD, merge base with refs/heads/main, spec SHA-256 and a working tree dirty only in blueprint/context/review.md before review.
- Reviewed the full 5dd04da..3ba3613 delta: admin AppointmentController, Form Requests, SlotService options, Setting::current reload, routes, admin Vue page and slot picker, types, and tests.
- Reschedule refreshes and re-checks status inside the transaction, runs ensureOpen excluding itself, then checks the full kept duration plus buffer against other active appointments; UniqueConstraintViolationException maps to the slot-taken error.
- Search escapes `!`, `%` and `_` and uses bound `LIKE ... ESCAPE '!'`; all admin routes sit behind auth and verified middleware, and guest access is tested.

### Findings

- F-05 closed, F-06 closed, F-08 closed. No new findings.

### Remaining risk

- F-03 and F-04 remain unverified leads (SQLite lock contention under real concurrency; proxy-dependent IP limits).
- Reschedule does not take a row lock; concurrency behaviour was not exercised beyond single-process tests.
- No browser or running-app verification was performed (Check not required).
