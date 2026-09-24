# Feature: Public booking flow

**From build-plan:** feature 4
**Build attempt:** 1
**Branch:** feature/public-booking-flow
**Status:** verified

## Goal

Let a visitor with the booking link pick an open time and book it without an account. The public page at `/` shows a month calendar, then the open times for the chosen day in the visitor's timezone, then a short form (name, email, notes). A successful booking lands on a confirmation page. The server re-checks the slot inside a transaction, relies on the existing `slot_lock` unique index so two people can't take the same slot, rate-limits booking attempts, and drops honeypot spam.

## Design reference

- `prototypes/booking.html` - calendar + time-slot layout, day states (available, selected, unavailable, blocked, today), timezone select, stacking on narrow screens
- `prototypes/booking-form.html` - details form (with inline error) and the "You're booked" confirmation layout
- Theme tokens are already in `resources/css/app.css` (ported in feature 3); use Tailwind theme classes only

## In scope

- `GET /slots` JSON endpoint: open slot starts (UTC) for a requested window, using `SlotService`
- Public booking page at `/` (replaces the `Welcome.vue` placeholder): month calendar, day's times, timezone select, details form
- `POST /book`: validation, honeypot, per-IP booking rate limit, transactional slot re-check, create a `confirmed` appointment
- `GET /book/confirmed/{appointment}`: signed, time-limited confirmation page
- Loading, empty, error, validation, "slot just taken", and rate-limited states
- Feature tests for the endpoints and the booking rules

## Out of scope

- Any email, including the `.ics` invite and the "Add to calendar" button (feature 6). The confirmation page does not claim an email was sent.
- Visitor cancel/reschedule and any use of `manage_token` in a URL (feature 8)
- Admin create/reschedule/cancel and the admin skip-min-notice rule (feature 5)
- Multiple meeting types (feature 11), custom questions (feature 12), video links (feature 15)
- Business branding fields (name, logo, description, host photo): not in the data model yet (see Open questions)
- Schema changes: none needed

## Build loop

- `workflow.stepReview` is `feature`: build all steps, then present one review packet at the end.
- `workflow.checkpointCommits` is `disabled`: no commits during `/implement`. `/complete` creates the feature commit.
- Every step leaves `php artisan test` green and `npm run build` passing.

## Build steps

- [x] **1. Slots endpoint.** Add `App\Http\Controllers\BookingController` with a `slots` action and a `GET /slots` route (throttled) returning open slots for a UTC window from `SlotService`. Validate the window as described under Data / contracts. Add `tests/Feature/Booking/SlotsEndpointTest.php`.
  - Done when: tests prove the response shape, window validation (bad format, end before start, span over 45 days), that booked and blocked times are absent, and that no appointment data leaks.
- [x] **2. Book and confirm endpoints.** Add `App\Http\Requests\StoreBookingRequest` (validation, honeypot, per-IP rate limit) and the `store` and `confirmed` actions with the `POST /book` and signed `GET /book/confirmed/{appointment}` routes. Create the appointment in a transaction after re-checking the slot, and turn a `slot_lock` unique violation into a validation error. Add a minimal `booking/Confirmed.vue` page. Add `tests/Feature/Booking/BookAppointmentTest.php`.
  - Done when: the cases listed under Testing for booking pass, including the double-booking race (a unique-violation path) and an unsigned or expired confirmation URL returning 403.
- [x] **3. Calendar and times UI.** Replace the `/` placeholder with `resources/js/pages/booking/Book.vue`, rendered by `BookingController@show`, matching `prototypes/booking.html`. It fetches `/slots` for the visible month in the visitor's timezone, shows the day states and that day's times, and has a timezone select that re-groups the times. Delete `resources/js/pages/Welcome.vue`.
  - Done when: `npm run build` passes, `php artisan test` is green (the existing `/` tests still pass), and screenshots show the month view with available and unavailable days, a selected day's times, the loading state, the "no open times this month" empty state, and the fetch-error state with a working Retry. Also light and dark, and a narrow width.
- [x] **4. Details form and confirmation UI.** After a time is picked, show the details step on the same page (matching `prototypes/booking-form.html`): chosen-slot summary with Change, then name, email, notes, the hidden honeypot and Confirm booking. Submit to `POST /book`, show field errors inline, and handle "slot just taken" by returning to the times with a message and refreshed slots. Finish `booking/Confirmed.vue` to match the mockup's confirmed state, without email or `.ics` claims.
  - Done when: `npm run build` passes, `php artisan test` is green, and screenshots show the form with an inline email error, a successful booking reaching the confirmation page, the slot disappearing from `/slots` afterwards, and the "just taken" message.

## Files / areas

- `routes/web.php` - public routes (replace the `/` closure)
- `app/Http/Controllers/BookingController.php` - new: `show`, `slots`, `store`, `confirmed`
- `app/Http/Requests/StoreBookingRequest.php` - new
- `app/Services/SlotService.php` - used as-is (no signature change)
- `app/Models/Appointment.php` - used as-is (`slot_lock`, `manage_token`, scopes)
- `resources/js/pages/booking/Book.vue`, `resources/js/pages/booking/Confirmed.vue` - new
- `resources/js/pages/Welcome.vue` - deleted
- `tests/Feature/Booking/SlotsEndpointTest.php`, `tests/Feature/Booking/BookAppointmentTest.php` - new
- Existing tests that hit `/` (`ExampleTest`, `AdminOnlyAccessTest`) must keep passing

## Data / contracts

### Routes (public, no auth)

| Method | Path | Name | Middleware | Action |
|---|---|---|---|---|
| GET | `/` | `home` | - | `BookingController@show` → Inertia `booking/Book` |
| GET | `/slots` | `booking.slots` | `throttle:60,1` | `BookingController@slots` → JSON |
| POST | `/book` | `booking.store` | - (rate limit in the Form Request) | `BookingController@store` |
| GET | `/book/confirmed/{appointment}` | `booking.confirmed` | `signed` | `BookingController@confirmed` → Inertia `booking/Confirmed` |

### `booking/Book` props

- `businessName`: `config('app.name')`
- `slotMinutes`: `Setting::current()->slot_minutes`
- `maxDaysAhead`: `Setting::current()->max_days_ahead` (the client uses it to disable "next month" past the window; the server enforces it regardless)

### `GET /slots?start=<ISO-8601>&end=<ISO-8601>`

- `start`, `end`: required, parseable ISO-8601 instants with offset or `Z`. `end` must be after `start`; span at most 45 days. Otherwise 422 JSON validation errors.
- 200 response: `{ "slots": ["2026-10-14T02:30:00Z", ...] }`, UTC ISO strings with `Z`, ascending, from `SlotService::availableSlots(start, end)`.
- Returns start times only: never appointment names, emails, IDs, or counts.
- The client asks for the visible month's first to last local day in the visitor's timezone, converted to UTC, and groups the results by local date in that timezone.

### `POST /book` (`StoreBookingRequest`)

| Field | Rules |
|---|---|
| `start_at` | required; ISO-8601 instant; must equal a start returned by `SlotService::availableSlots(start_at, start_at + 1 minute)` → otherwise error "That time is no longer available. Please pick another." |
| `name` | required, string, max 255, trimmed |
| `email` | required, `email:rfc,filter` (the filter rejects dotless domains such as `name@gmail`, matching the mockup), max 255 |
| `notes` | nullable, string, max 1000 (mockup: "Max 1,000 characters") |
| `company_website` | honeypot: `prohibited` (any value fails with a generic "Something went wrong. Please try again." and nothing is stored) |

- Rate limit: max 5 booking attempts per IP per 10 minutes, counted in the Form Request with `RateLimiter` (same pattern as `LoginRequest`). When exceeded, return a validation error on `start_at`: "Too many booking attempts. Please try again in N minutes." This avoids Inertia's raw 429 modal.
- Store: in `DB::transaction`: re-run the slot check, then create an `Appointment` with `start_at`, `end_at = start_at + slot_minutes`, `status = confirmed`, `name`, `email`, `notes`, `created_by = null`. `manage_token` and `slot_lock` are set by the model hooks. Catch `Illuminate\Database\UniqueConstraintViolationException` and turn it into the same "no longer available" error on `start_at`.
- SQLite has no row locking, so the unique `slot_lock` is the race guard for same-start bookings. The in-transaction overlap check (through `SlotService`) covers different-start overlaps.
- Success: redirect to `URL::temporarySignedRoute('booking.confirmed', now()->addDay(), $appointment)`.

### `GET /book/confirmed/{appointment}` → `booking/Confirmed`

- Requires a valid signature (unsigned, tampered, or expired → Laravel's 403). IDs are sequential, so the signature is what stops one visitor reading another's booking.
- Props: `{ name, email, start_at (UTC ISO Z), end_at (UTC ISO Z), slotMinutes, businessName }`. Never `manage_token`, `id`, or notes.
- The page formats times in the browser's timezone with `Intl.DateTimeFormat`.

## Testing

Run `php artisan test` (after `npm run build`). Freeze time with `travelTo`. Seed rules and settings in `setUp` the way `SlotServiceTest` does.

**`SlotsEndpointTest`**
- Returns `{ slots: [...] }` in UTC `Z` format, ascending, for a valid window
- A booked slot and a blocked date are absent
- 422 for a missing or unparseable `start`/`end`, `end` <= `start`, and a span over 45 days
- The response body never contains an appointment's name or email

**`BookAppointmentTest`**
- A valid booking creates one `confirmed` appointment with the correct `end_at`, a 64-character `manage_token`, and `created_by` null. It redirects to a signed confirmation URL that returns 200 with the `booking/Confirmed` component and no `manage_token` in the props.
- Rejected with an error on `start_at` and nothing stored: not on the slot grid, inside minimum notice, beyond the booking window, on a blocked date, or already booked
- Double-booking race: with an appointment created directly at the same start (bypassing the pre-check), `store` returns the "no longer available" error instead of a 500
- Missing name, invalid email, and notes over 1000 characters are each rejected
- A filled honeypot is rejected and stores nothing
- The 6th attempt from the same IP within 10 minutes gets the rate-limit error
- An unsigned, tampered, or expired confirmation URL returns 403

UI rendering is verified with screenshots (steps 3 and 4). The public page needs no login, so the browser pane can capture it once the user starts the server.

## Notes for the AI

- Follow `coding-standards.md`: Form Request, typed returns, named routes via `route()`, `<script setup lang="ts">`, `interface Props`, theme tokens only, and `{{ }}` interpolation for all visitor text (never `v-html`).
- Use native `fetch` for `/slots` (no new dependency). Track `loading`, `error`, and `slots` state. Abort or ignore stale responses when the month or timezone changes quickly.
- Timezone select: default `Intl.DateTimeFormat().resolvedOptions().timeZone`, with options from `Intl.supportedValuesOf('timeZone')`. Label it "Times shown in", and mark the detected zone "(detected)".
- Calendar: weeks start on Sunday (as in the mockup). Day states:
  - available: at least one slot that local date
  - selected
  - unavailable: no slots, or in the past
  - today: dot marker

  Every day is a `<button>`, disabled when unavailable, with an `aria-label` like "Wednesday, October 14, 2026, 9 open times". Previous month is disabled for the current month; next month is disabled past `maxDaysAhead`. The mockup's separate "blocked" strikethrough is not distinguishable from the public data, so blocked days show as unavailable (the public API does not reveal why).
- Auto-select the first available day when a month loads, and show its times. After picking a time, show a "Next" button next to it (as in the mockup); Next opens the details step.
- Details step: `useForm({ start_at, name, email, notes, company_website: '' })`. Hide the honeypot off-screen with `tabindex="-1"`, `autocomplete="off"` and `aria-hidden="true"`. Label every field and use `InputError` with `aria-invalid`/`aria-describedby`. Focus the first invalid field after a failed submit. Disable Confirm while `form.processing`.
- On a `start_at` error: return to the times view, show the message above the times (`role="alert"`), refetch slots, and clear the picked time.
- Empty month: "No open times in <Month>." with a "Next month" action when allowed. Fetch error: "Couldn't load available times." with Retry.
- Keep the public page free of the admin layout. Reuse the `Card`, `Button`, `Input`, `Label` and `InputError` components.

## Open questions

These don't block the build; the spec uses the values shown:

1. **Branding:** the header shows `APP_NAME` ("Simplified Booking") and "<slot length>-minute meeting". The mockup's host name, description and "Video call" line are left out until branding fields exist.
2. **Rate limit:** 5 booking attempts per IP per 10 minutes; `/slots` allows 60 requests per minute.
3. **Confirmation link lifetime:** 24 hours, then 403.
4. **Honeypot response:** a visible generic error rather than a fake success.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":13575,"specSha256":"19cfae72194924e01c0375458727a3ec5c4dce61f4f709ee1ce46c24db0f3975","branch":"refs/heads/feature/public-booking-flow","head":"f72594a0a4b2eb6a6e2629bc9ef2637ffbac9466","baseRef":"refs/heads/main","baseCommit":"24fe29cec3ee2852db42d992d1f3758f77cadeda","sourceTree":"89fbd96bd64bd127ad64758901299cd18a401942","absentOptional":[]} -->

## Independent review

**Status:** passed
**Target commit:** f72594a0a4b2eb6a6e2629bc9ef2637ffbac9466
**Base commit:** 24fe29cec3ee2852db42d992d1f3758f77cadeda
**Base ref:** refs/heads/main
**Spec hash:** 19cfae72194924e01c0375458727a3ec5c4dce61f4f709ee1ce46c24db0f3975
**Prepared by:** claude
**Builder model:** claude-opus-5-5
**Requested reviewer:** claude
**Requested model:** claude-opus-5-5
**Requested execution:** automatic
**Requested at:** 2026-09-24T08:29:30Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-opus-5-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-24T08:32:48Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `git rev-parse HEAD` / `git merge-base refs/heads/main HEAD`: pass (match Target and Base commits)
- `shasum -a 256 blueprint/context/current-feature.md`: pass (matches Spec hash; spec is tracked)
- `git status --porcelain --untracked-files=all`: pass (only blueprint/context/review.md modified)
- `php artisan test`: pass (65 tests, 281 assertions)
- `./vendor/bin/pint --test`: pass
- `npm run format:check`: pass
- `npx eslint resources/js/pages/booking`: pass
- `npm run build`: pass

### Evidence

- Reviewed the full `24fe29c..f72594a` delta: BookingController, StoreBookingRequest, routes/web.php, booking/Book.vue, booking/Confirmed.vue, Welcome.vue deletion, both Booking feature tests, and the spec; followed SlotService, Appointment model hooks, the appointments migration (`slot_lock` unique), LoginRequest, bootstrap/app.php and config/database.php.
- POST /book: rules match the spec table; honeypot `prohibited` passes the empty/null value the client sends and fails any filled value; limiter hits every attempt before validation (5 per IP per 600 s) and returns a `start_at` validation error, not a 429.
- Store path: slot re-check runs inside `DB::transaction`; `UniqueConstraintViolationException` from the `slot_lock` unique index maps to the same `start_at` error; test forces the unique path with a mocked `SlotService`.
- Confirmation: route uses `signed`; `temporarySignedRoute` for 24 h; props are name, email, UTC start/end, slotMinutes, businessName only (no id, notes, manage_token); unsigned, tampered-id and expired URLs return 403 in tests.
- GET /slots: `throttle:60,1`, required dates, `after:start`, max 45 days (Carbon 3 float diff); returns UTC `Z` start strings only; test asserts no name/email leak.
- Book.vue timezone math: two-pass offset correction for local midnight, `h23` hour cycle, month+1 rollover via `Date.UTC`, grouping by local date in the selected zone, stale responses ignored by request id; visitor text rendered with `{{ }}` only (no `v-html`).

### Findings

- F-01 [P3] open: date inputs accept non-ISO / offset-less values (spec drift)
- F-02 [P3] open: no test asserts `/` renders `booking/Book` with its props
- F-03 [P2] unverified: concurrent SQLite bookings may raise "database is locked" (500) instead of the slot-taken error
- F-04 [P2] unverified: per-IP limits depend on trusted-proxy configuration that is not set

### Remaining risk

- Check was not required and was not run; no browser or running-app evidence was inspected in this review.
- The double-booking race is only tested sequentially; true concurrent behavior on SQLite is unverified (F-03).
- Rate-limit behavior behind a production proxy is unverified until deployment is configured (F-04).
- No dependency or vulnerability scan command is declared; none was run.
