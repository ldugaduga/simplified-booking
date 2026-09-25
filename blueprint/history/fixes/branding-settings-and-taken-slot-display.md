# Fix: Editable branding, and grey out taken time slots

**Type:** Fix
**Status:** verified
**Branch:** fix/branding-settings-and-taken-slot-display

## The problem

1. **No way to edit branding.** The business name shown on the public booking page, the confirmation page, the `.ics` invite, and the three confirmation emails is hard-coded to `config('app.name')` ("Simplified Booking") in six places. There's no admin UI to change it, and no description field at all. Feature 4's spec explicitly deferred this ("Business branding fields... not in the data model yet").
2. **A booked slot disappears instead of showing as taken.** `GET /slots` only ever returns *open* start times (`app/Http/Controllers/BookingController.php:52-65`, via `SlotService::availableSlots()`). When someone books a time, it's simply absent from the next `/slots` response, so in `resources/js/pages/booking/Book.vue` the time button vanishes from the list rather than showing as unavailable. A visitor who had the page open can't tell whether the time was ever offered.

## The fix

### 1. Branding settings

- Add nullable `business_name` (string, 255) and `business_description` (string, 500) columns to `settings` via a new migration. `Setting::current()` already backfills column defaults on a fresh row (`app/Models/Setting.php`), so both stay `null` until the admin sets them.
- Add a **Branding** tab to the existing `/settings/*` area (`resources/js/layouts/settings/Layout.vue`), alongside Profile, Password, and Appearance, since that's the app's existing "admin settings" area.
- `App\Http\Controllers\Settings\BrandingController` (`edit`, `update`), following the same inline-`validate()` convention as `ProfileController`/`PasswordController` in that namespace (no separate Form Request there). Routes in `routes/settings.php`: `GET /settings/branding` (`branding.edit`), `PATCH /settings/branding` (`branding.update`).
- `resources/js/pages/settings/Branding.vue`, matching `settings/Profile.vue`'s layout: a name field and a description textarea, `Save` button, the same `Saved.` transition.
- Replace every `config('app.name')` used for the *visitor-facing* business name with `Setting::current()->business_name ?: config('app.name')`, so an empty setting falls back to the app name instead of showing blank:
  - `BookingController::show` and `::confirmed` (`businessName` prop)
  - `IcsGenerator::summary()` (already reads `Setting::current()` for other fields, so this follows the file's own pattern)
  - The three Mailables (`AppointmentBooked`, `AppointmentRescheduled`, `AppointmentCancelled`): add a `businessName()` helper on `AppointmentMailable` and pass it to each view instead of the views calling `config('app.name')` directly.
- Show `business_description` on the public booking page only (`Book.vue`, under the business name), since that's the one place with room for it in the current layout; it is not added to the emails or the confirmation page, to keep this change small.
- Must not change: `HandleInertiaRequests`'s shared `name` prop (the app's own product name, used for the browser tab title and the admin chrome) stays `config('app.name')`. That's the app you're running, not the client's business, and conflating the two was explicitly out of scope for feature 4.

### 2. Grey out taken slots instead of hiding them

- Add `SlotService::takenSlots(CarbonImmutable $from, CarbonImmutable $to): Collection` returning the UTC start times of grid slots that are *within working hours, not on a blocked date, and inside the booking window*, but that clash with an active appointment (the same overlap/buffer rule `availableSlots()` already applies). Minimum notice does not apply here: a slot inside the notice window is simply not part of the public grid at all (that's why `availableSlots()` never returns it either), so there is nothing to greyout for it.
- Implement this by factoring the shared grid-and-clash logic `availableSlots()` already computes into one private method that both public methods filter, rather than duplicating the loop. `availableSlots()`'s existing behavior and signature are unchanged.
- `BookingController::slots` adds a `taken` key to its JSON response alongside the existing `slots` key: `{ "slots": [...open...], "taken": [...taken...] }`. Both are UTC ISO strings, ascending, same format as today. This is additive; nothing currently reading `slots` breaks.
- `Book.vue` merges the two into one time list per day: available times render as today (clickable), taken times render as a disabled, greyed, struck-through button with the same time label and a tooltip ("Already booked"). A taken time is never selectable and never counts toward a day's "X open" count or a day's available/unavailable calendar state (a day with zero *open* slots still shows as unavailable on the calendar, matching today's behavior and the approved mockup's "fully booked" treatment).

## Build steps

- [x] **1. Branding settings (backend + admin UI).** Migration, `Setting` fillable/casts, `BrandingController`, routes, `Branding.vue`, nav tab. Add `tests/Feature/Settings/BrandingSettingsTest.php`.
  - Done when: an admin can view and save the branding form; validation rejects a name over 255 chars and a description over 500; a guest is redirected to login; `Setting::current()->business_name` persists after save.
- [x] **2. Wire branding into the visitor-facing surfaces.** Update `BookingController` (both actions), `IcsGenerator`, `AppointmentMailable` + its three views. Extend `tests/Unit/IcsGeneratorTest.php`, `tests/Feature/Mail/AppointmentMailTest.php`, and add a case to the booking-page test for the fallback and the override.
  - Done when: with `business_name` unset, every surface still shows `config('app.name')` (no regression); with it set, the public page, confirmation page, `.ics` `SUMMARY`, and all three emails show the configured name; `business_description` appears on the public page only.
- [x] **3. `SlotService::takenSlots()` and the `/slots` endpoint.** Add the method (refactored from the shared grid logic), extend `BookingController::slots`, extend `tests/Feature/SlotServiceTest.php` and `tests/Feature/Booking/SlotsEndpointTest.php`.
  - Done when: the cases under Testing for `takenSlots()` and the endpoint pass, and all existing `SlotServiceTest`/`Booking` tests still pass unchanged.
- [x] **4. Grey out taken slots in the UI.** Update `Book.vue` to fetch and merge `taken`, and render disabled buttons for them.
  - Done when: `npm run build` passes, `php artisan test` is green, and a screenshot (browser pane, no login needed) of a day with a mix of open and taken times shows the taken time as a disabled, greyed, struck-through button that cannot be picked, in both light and dark.

## Files / areas

- `database/migrations/*_add_branding_to_settings_table.php` - new
- `app/Models/Setting.php` - add `business_name`, `business_description` to `$fillable`
- `app/Http/Controllers/Settings/BrandingController.php` - new
- `routes/settings.php` - two new routes
- `resources/js/pages/settings/Branding.vue` - new
- `resources/js/layouts/settings/Layout.vue` - add the Branding nav item
- `app/Http/Controllers/BookingController.php` - `show`, `confirmed`, `slots`
- `app/Services/IcsGenerator.php` - `summary()`
- `app/Mail/AppointmentMailable.php` (+ 3 subclasses), `resources/views/emails/*.blade.php` - businessName wiring
- `app/Services/SlotService.php` - new `takenSlots()` method, shared internal refactor
- `resources/js/pages/booking/Book.vue` - fetch/merge/render taken slots, show description
- Tests: `tests/Feature/Settings/BrandingSettingsTest.php` (new); extended: `tests/Unit/IcsGeneratorTest.php`, `tests/Feature/Mail/AppointmentMailTest.php`, `tests/Feature/SlotServiceTest.php`, `tests/Feature/Booking/SlotsEndpointTest.php`

## Data / contracts

### `PATCH /settings/branding` (auth required)

| Field | Rules |
|---|---|
| `business_name` | nullable, string, max 255 |
| `business_description` | nullable, string, max 500 |

Persists onto the single `settings` row via `Setting::current()->update(...)`. Redirects `back()`; errors keyed by field.

### `GET /settings/branding` props

- `businessName: string | null`, `businessDescription: string | null` (the raw stored values, not the `config('app.name')` fallback, so the form shows blank until the admin sets one).

### `SlotService::takenSlots(CarbonImmutable $from, CarbonImmutable $to): Collection`

Same grid as `availableSlots($from, $to)` (working hours, not blocked, inside `max_days_ahead`, `min_notice_hours` still applied since a slot inside the notice window was never a candidate to begin with), but returns the starts where the buffer-widened overlap check against active appointments *does* clash, instead of excluding them. Same ascending, deduplicated, UTC `CarbonImmutable` result shape as `availableSlots()`.

### `GET /slots` response (extended)

```json
{ "slots": ["2026-10-14T02:30:00Z"], "taken": ["2026-10-14T03:00:00Z"] }
```

`taken` uses `takenSlots()` with no admin exceptions (no `ignoreMinimumNotice`, no `exceptAppointmentId`) - this endpoint is public.

## Testing

Run `php artisan test`.

**`BrandingSettingsTest`**
- Guest `GET`/`PATCH` redirected to `/login`
- Valid update persists both fields; a 256-char name and a 501-char description are each rejected; either field alone can be cleared back to `null`

**`IcsGeneratorTest` (extend)**
- `SUMMARY` uses `business_name` when set; falls back to `config('app.name')` when it's `null`

**`AppointmentMailTest` (extend)**
- Each mailable's rendered view contains `business_name` when set, and `config('app.name')` when it's not

**Booking page test (extend, wherever `businessName`/props are already asserted)**
- `business_name` set: the `home` route's Inertia props show it and the description; unset: props show `config('app.name')` and a `null` description

**`SlotServiceTest` (extend)**
- A confirmed appointment's slot appears in `takenSlots()` and not in `availableSlots()`, for the same window
- A time before the notice window or beyond `max_days_ahead`, or on a blocked date, appears in neither list
- With a buffer set, the padded neighbors of a booked slot appear in `takenSlots()` too, matching `availableSlots()`'s existing buffer test
- A cancelled appointment's old slot appears in neither list (it's open, so it's in `availableSlots()`, not `takenSlots()`)

**`SlotsEndpointTest` (extend)**
- A booked slot appears in `taken` and not in `slots`; a blocked date's times appear in neither
- The response contains no appointment name or email (same privacy check as the existing `slots` assertion)

UI rendering (step 4) is verified with a screenshot in the browser pane; the public page needs no login.

## Notes for the AI

- Keep `SlotService::availableSlots()`'s existing behavior, signature, and all current tests passing unchanged; `takenSlots()` is additive.
- Follow `coding-standards.md`: Form Request pattern for `Admin\*` controllers doesn't apply here, since `Settings\*` controllers in this codebase already use inline `$request->validate()` - match that existing local convention instead.
- `business_description` is user-entered text; render it with `{{ }}` in `Book.vue`, never `v-html`.
- The taken-slot button needs an accessible name distinguishing it from an open one, e.g. `aria-label="9:00 AM, already booked"`, and `aria-disabled`/`disabled` so it isn't reachable as an actionable control.
- Don't add a new admin-facing "fully booked" distinction to the calendar day cells; that's unchanged from today (a day with zero open slots stays marked unavailable, same as before this fix).
- Reuse the theme tokens already in `app.css` for the disabled/greyed style (for example `bg-muted text-muted-foreground`), consistent with disabled controls elsewhere in the app.

## Verify

- Log in, open `/settings/branding`, set a business name and description, save, confirm "Saved." appears and the value persists on reload.
- Open `/` (no login) and confirm the public page shows the new name and description.
- Book an appointment, then check its confirmation email/`.ics` (via the log driver) for the new name.
- With the browser pane on `/`, pick a day with several open times, book one from another tab/session, then reload `/` and confirm that time now shows as a disabled, greyed, struck-through button rather than disappearing.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":12397,"specSha256":"3aa7cb150cec215731fea7ee0814f39407afc1c2960f732049796175ae048b35","branch":"refs/heads/fix/branding-settings-and-taken-slot-display","head":"05ece9897f2f0669b5c91fa6f06d8b4d1a3e71cb","baseRef":"refs/heads/main","baseCommit":"ad10c7c73cee94c07477c84c2be082d253a57fd8","sourceTree":"15d502c86be59d1ecf9990b808ab7af66d5b2975","absentOptional":[]} -->

## Independent review

**Status:** passed
**Target commit:** 05ece9897f2f0669b5c91fa6f06d8b4d1a3e71cb
**Base commit:** ad10c7c73cee94c07477c84c2be082d253a57fd8
**Base ref:** refs/heads/main
**Spec hash:** 3aa7cb150cec215731fea7ee0814f39407afc1c2960f732049796175ae048b35
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-sonnet-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T01:36:06Z
**Workflow:** regular
**Check required:** no

### Handoff

Review the active spec and the complete `ad10c7c73cee94c07477c84c2be082d253a57fd8..05ece9897f2f0669b5c91fa6f06d8b4d1a3e71cb` delta in a fresh
session or isolated subagent without the builder conversation. Run all Audit lenses from scratch.
Run Check when required above. Do not edit product code, accept findings, or
reuse the existing findings as the review scope.

**Reviewer adapter:** claude
**Reviewer model:** claude-sonnet-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T01:38:07Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Commands

- `php artisan test`: pass (110 passed, 698 assertions)
- `npm run build`: pass
- `./vendor/bin/pint --test`: pass
- `npm run format:check`: pass

### Evidence

- Verified `HEAD` (05ece9897f2f0669b5c91fa6f06d8b4d1a3e71cb) equals Target commit, and `git merge-base refs/heads/main HEAD` equals Base commit (ad10c7c73cee94c07477c84c2be082d253a57fd8).
- `shasum -a 256 blueprint/context/current-feature.md` matches the recorded Spec hash.
- `git status --porcelain` shows only `blueprint/context/review.md` modified; no other path differs from the target.
- Reviewed the full `git diff ad10c7c..05ece98` (24 files) fresh, without treating `blueprint/context/findings.md` as the review scope.
- `routes/settings.php`: `branding.edit`/`branding.update` are registered inside the same `Route::middleware('auth')` group as `password.edit`/`profile.edit` (confirmed by reading the route group in the diff), so branding settings are auth-gated as the spec requires.
- `BrandingController::update` validates `business_name` (max:255) and `business_description` (max:500), matching the spec's Data/contracts table; `BrandingSettingsTest` covers guest redirect, valid save, clearing each field, and overlong rejection (all passing).
- `config('app.name')` fallback wiring (`BookingController::show`/`confirmed`, `IcsGenerator::summary`, `AppointmentMailable::businessName()` + 3 Mailables + 3 Blade views) matches the spec's file list exactly; `AppointmentMailTest`, `IcsGeneratorTest`, and `BookingPageTest` each assert both the override and the `config('app.name')` fallback.
- `resources/js/pages/booking/Book.vue`: `business_description` and all time labels render with `{{ }}` interpolation only; no `v-html` was introduced anywhere in the diff (checked the full Book.vue diff for `v-html`, none present).
- Confirmed `resources/js/layouts/settings/Layout.vue` HandleInertiaRequests shared `name` prop is untouched (not present in the diff; only Branding.vue and BrandingController wiring were added).
- `app/Services/SlotService.php`: `availableSlots()` keeps its original signature and now delegates to a private `candidates()` method, filtering `available === true`; `takenSlots()` filters `available === false` with no minimum-notice or exception-id parameters, matching the spec. All 14 pre-existing `SlotServiceTest` cases plus 4 new ones pass unmodified in intent (test output confirms all by name).
- `BookingController::slots()` adds a `taken` key alongside the existing `slots` key, additive and same UTC/ascending format; `SlotsEndpointTest::test_booked_slots_appear_taken_and_blocked_dates_appear_in_neither_list` and the pre-existing privacy assertions (`assertStringNotContainsString` for name/email) both pass, confirming `takenSlots()` exposes bare timestamps only.
- `Book.vue`: `dayTimes` merges `slotsByDate` (open) and `takenByDate` (taken) into one sorted list; the taken branch renders `disabled`, `aria-disabled="true"`, a distinguishing `aria-label` ("… already booked"), and `line-through`/`bg-muted text-muted-foreground` styling, and has no `@click` handler, so a taken time can never become `pickedSlot`. `openCount` (used for the "X open" label) and the calendar day-count logic at line 165 both read from `slotsByDate` only, not the merged list, so day-level open/closed calendar state is unchanged from before this fix.
- Privacy judgment: exposing which specific times are taken (bare UTC timestamps only, no name/email/appointment id) via the public `/slots` endpoint is proportionate for this single-admin booking tool. It reveals booking density/timing to any visitor, which is an inherent property of a public calendar-style booking page and is explicitly the intended behavior change in this fix; no personal data crosses the boundary, and the existing endpoint was already public and rate-limited (`throttle:60,1`, tracked separately as unverified F-04). No new trust boundary is introduced.

### Findings

- None

### Remaining risk

- F-03 and F-04 (pre-existing, unrelated to this fix's code paths) remain unverified; not re-examined here since this diff does not touch SQLite transaction handling or trusted-proxy configuration.
- No browser-based UI evidence was captured in this review pass (screenshot verification per the spec's step 4 done-when was not re-run); `npm run build` and the Vue diff review are the basis for the UI-correctness findings above, consistent with the coding standard that Vue rendering/styling is verified by screenshot and build rather than unit tests.
- No dedicated security-scanner or dependency-audit command is declared in this project; only manual inspection was performed for the security lens.
