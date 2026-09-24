# Feature: Availability management

**From build-plan:** feature 3
**Build attempt:** 1
**Branch:** feature/availability-management
**Status:** verified

## Goal

Give the admin one page to set when they can be booked: weekly working hours, blocked dates, and booking rules (meeting length, buffer, minimum notice, booking window, timezone). Also add a tested `SlotService` that turns those settings, plus existing appointments, into open slot start times. Features 4 (public booking) and 5 (admin appointments) will use it. This is also the first UI feature, so it brings the approved prototype theme into the app.

## Design reference

- `prototypes/admin-availability.html` - layout, states, and copy for this page
- `prototypes/admin-shell.css` - admin sidebar look (the app keeps its existing shadcn sidebar component; match its feel, not its markup)
- `prototypes/theme.css` - approved tokens (crisp and professional, teal accent, Inter), light and dark. The comments on the right name the matching shadcn variable in `resources/css/app.css`.

## In scope

- Port `prototypes/theme.css` tokens into `resources/css/app.css` (`:root` and `.dark`) and switch the app font to Inter
- `App\Services\SlotService` computing open slot starts (UTC) for a UTC window
- `/admin/availability` page with three independent forms: weekly hours, booking rules, blocked dates
- Server-side validation for all three, with per-field errors shown in the UI
- "Availability" item in the admin sidebar and header nav
- Feature tests for the slot logic, validation, persistence, and access rules

## Out of scope

- Public calendar, booking form, or any visitor-facing endpoint that exposes slots (feature 4)
- Admin create, reschedule, or cancel, and the admin skip-min-notice rule (feature 5)
- Emails of any kind (features 6, 9, 10)
- Multiple meeting types; per-type durations (feature 11)
- Calendar sync or external busy times (feature 14)
- Replacing the dashboard placeholder content
- Adding new UI dependencies (no select/switch packages): use native `<select>` and the existing `Checkbox`, `Input`, `Label`, `Button`, and `Card` components

## Build loop

- `workflow.stepReview` is `feature`: build all steps, then present one review packet at the end. No per-step approval pauses.
- `workflow.checkpointCommits` is `disabled`: no commits during `/implement`. `/complete` creates the final feature commit.
- Each step must leave `php artisan test` green and `npm run build` passing before moving on.

## Build steps

- [x] **1. Port the theme and font.** Copy the light-token HSL triplets from `prototypes/theme.css` into `:root` and the `[data-theme="dark"]` triplets into `.dark` in `resources/css/app.css`, using the shadcn names in the file's comments. Map any shadcn tokens the prototype doesn't name to the nearest prototype token (sidebar, secondary, popover, ring, chart colours unchanged). Set `--radius: 0.375rem`. Replace Instrument Sans with Inter in `resources/views/app.blade.php` (Bunny Fonts `inter:400,500,600,700`), `tailwind.config.js` `fontFamily.sans`, and the `--font-sans` line in `app.css`.
  - Done when: `npm run build` passes; `php artisan test` is green; a screenshot of `/login` and `/admin` shows teal primary buttons and Inter in light mode, and the appearance toggle switches to the teal-on-dark palette.
- [x] **2. `SlotService` with tests.** Create `app/Services/SlotService.php` with `availableSlots(CarbonImmutable $from, CarbonImmutable $to): Collection` implementing the rules under Data / contracts. Add `tests/Feature/SlotServiceTest.php`.
  - Done when: every case listed under Testing for the slot service passes in `php artisan test`.
- [x] **3. Routes, requests, controllers, minimal page.** Add the routes under Data / contracts inside the existing `auth` + `verified` + `admin` prefix group in `routes/web.php`. Add `App\Http\Controllers\Admin\AvailabilityController` (`edit`, `updateHours`, `updateRules`) and `BlockedDateController` (`store`, `destroy`), plus Form Requests in `app/Http/Requests/Admin/`. Create `resources/js/pages/admin/Availability.vue` rendering the props as plain lists (the full UI is step 4). Add `tests/Feature/Admin/AvailabilityTest.php`.
  - Done when: the access, validation, and persistence cases listed under Testing pass, and `/admin/availability` renders for a logged-in admin.
- [x] **4. Availability page UI.** Build `Availability.vue` to match `prototypes/admin-availability.html`: a weekly-hours card and a side column with booking rules and blocked dates, stacking on narrow screens. Add an "Availability" nav item (lucide `Clock`) to `AppSidebar.vue` and `AppHeader.vue`, with breadcrumbs. Cover the states listed under Notes for the AI.
  - Done when: `npm run build` passes, `php artisan test` is green, and screenshots show the page in light and dark at desktop and narrow widths. The screenshots must show a successful save of each form ("Saved." appears), an overlapping-range error on the right row, a duplicate blocked-date error, and the empty weekly-hours notice.

## Files / areas

- `resources/css/app.css`, `resources/views/app.blade.php`, `tailwind.config.js` - theme and font
- `app/Services/SlotService.php` - new
- `app/Http/Controllers/Admin/AvailabilityController.php`, `app/Http/Controllers/Admin/BlockedDateController.php` - new
- `app/Http/Requests/Admin/UpdateWeeklyHoursRequest.php`, `UpdateBookingRulesRequest.php`, `StoreBlockedDateRequest.php` - new
- `routes/web.php` - admin availability routes
- `resources/js/pages/admin/Availability.vue` - new
- `resources/js/components/AppSidebar.vue`, `resources/js/components/AppHeader.vue` - nav item
- `resources/js/types/index.ts` - `AvailabilityRule`, `BookingSettings`, `BlockedDate` types
- `tests/Feature/SlotServiceTest.php`, `tests/Feature/Admin/AvailabilityTest.php` - new
- Existing models used as-is: `Setting`, `AvailabilityRule`, `BlockedDate`, `Appointment` (no schema changes)

## Data / contracts

### Routes (all require `auth` + `verified`, prefix `/admin`)

| Method | Path | Name | Action |
|---|---|---|---|
| GET | `/admin/availability` | `admin.availability.edit` | `AvailabilityController@edit` |
| PUT | `/admin/availability/hours` | `admin.availability.hours.update` | `AvailabilityController@updateHours` |
| PUT | `/admin/availability/rules` | `admin.availability.rules.update` | `AvailabilityController@updateRules` |
| POST | `/admin/blocked-dates` | `admin.blocked-dates.store` | `BlockedDateController@store` |
| DELETE | `/admin/blocked-dates/{blockedDate}` | `admin.blocked-dates.destroy` | `BlockedDateController@destroy` (route model binding) |

Every mutation redirects `back()` on success. Validation failures return Inertia errors keyed by field.

### Page props (`admin/Availability`)

- `rules`: `{ weekday: 0-6, start_time: "HH:MM", end_time: "HH:MM" }[]`, sorted by weekday, then start_time
- `settings`: `{ timezone, slot_minutes, buffer_minutes, min_notice_hours, max_days_ahead }` from `Setting::current()`
- `blockedDates`: `{ id, date: "YYYY-MM-DD", reason: string | null, is_past: boolean }[]`, ascending. `is_past` compares against today in `settings.timezone`.
- `timezones`: `DateTimeZone::listIdentifiers()`

### Weekly hours (`PUT .../hours`)

- Body: `{ rules: [{ weekday, start_time, end_time }] }`. An empty array is allowed (no working hours).
- Rules: `weekday` integer 0-6; times `date_format:H:i`; `end_time` after `start_time` on the same day (no ranges crossing midnight). Ranges on the same weekday must not overlap; touching (`12:00` end, `12:00` start) is allowed. Report an overlap error on the later range's `rules.N.start_time` key.
- Persist by replacing all `availability_rules` rows inside one DB transaction (delete, then insert). Times are wall-clock values in `settings.timezone`.

### Booking rules (`PUT .../rules`)

- `slot_minutes`: one of 15, 30, 45, 60 (the approved mockup's options)
- `buffer_minutes`: integer 0-120
- `min_notice_hours`: integer 0-720
- `max_days_ahead`: integer 1-365
- `timezone`: required, Laravel `timezone:all` rule
- Updates the single `settings` row. Changing the timezone keeps the same wall-clock hours and applies them in the new zone (hint text says so).

### Blocked dates

- Store: `date` required, `date_format:Y-m-d`, not before today in `settings.timezone`, `unique:blocked_dates,date`. `reason` nullable string, max 255.
- Destroy: deletes the row. The UI shows past dates muted, with no remove button.

### `SlotService::availableSlots(CarbonImmutable $from, CarbonImmutable $to): Collection<int, CarbonImmutable>`

Returns UTC slot **start** instants `s` with `$from <= s < $to`, ascending, no duplicates. Visitor timezone handling is feature 4's job. The service reads `Setting::current()`, all `AvailabilityRule`s, `BlockedDate`s in range, and active appointments overlapping the widened range, all in a fixed number of queries (not one per day). Rules, with `tz = settings.timezone` and `len = slot_minutes`:

1. Walk each local calendar date in `tz` that the UTC window touches.
2. Skip dates listed in `blocked_dates`.
3. For each rule on that weekday, candidates start at the range start and step by `len` minutes; a candidate is kept only if `start + len <= range end`.
4. Build each candidate as a local wall-clock time in `tz`, then convert to UTC. Drop a candidate whose local time does not exist (DST gap), which you can detect when converting back does not return the same wall-clock time.
5. Drop candidates starting before `now() + min_notice_hours`.
6. Drop candidates whose local date in `tz` is after `today(tz) + max_days_ahead` days.
7. Drop candidates that conflict with an active (`confirmed`) appointment `a`, meaning `a.start_at - buffer < slot.end` and `a.end_at + buffer > slot.start`. Buffer only pads existing appointments; free slots still step by `len`.
8. Drop candidates outside `[$from, $to)`.

## Testing

Test command: `php artisan test` (run `npm run build` first; Inertia page tests need the manifest). Freeze time with `$this->travelTo()`.

**SlotService (`tests/Feature/SlotServiceTest.php`)**
- Mon-Fri 09:00-17:00, 30-min slots, `Asia/Manila`, no bookings: a weekday returns 16 slots from 01:00Z to 08:30Z; Saturday returns none
- Split shift (09:00-12:00 and 13:00-17:00) leaves no slot at 12:00 or 12:30
- 45-min slots in a 09:00-10:00 range yield only 09:00 (a slot must fit fully)
- Blocked date returns no slots that day
- Minimum notice: with now = 09:10 local and a 4h notice, the first slot is 13:30
- Booking window: with `max_days_ahead` 2, day +3 is empty and day +2 is not
- A confirmed appointment at 10:00-10:30 removes that slot; with a 15-min buffer, 09:30 and 10:30 are also gone, and 09:00 and 11:00 remain
- A cancelled appointment does not block
- Timezone with DST (`America/New_York`, spring-forward day with a 01:00-04:00 rule) skips the nonexistent 02:00-02:59 slots and returns correct UTC instants
- Results are limited to `[$from, $to)` and sorted ascending

**Admin availability (`tests/Feature/Admin/AvailabilityTest.php`)**
- Guest: GET and every mutation redirect to `/login`, and nothing changes in the database
- Admin: GET renders `admin/Availability` with the four props
- Hours: a valid payload replaces all rules; an empty array clears them; end before or equal to start is rejected; overlapping ranges are rejected with the error on the right key; touching ranges are accepted; bad weekday or time format is rejected
- Rules: valid update persists; `slot_minutes` 20, negative buffer, `max_days_ahead` 0, and an unknown timezone are each rejected
- Blocked dates: add persists; a duplicate date is rejected; a past date is rejected; a reason over 255 characters is rejected; destroy removes the row

UI rendering is verified with screenshots (step 4), not unit tests.

## Notes for the AI

- Follow `blueprint/context/coding-standards.md`: Form Requests, typed returns, `casts()`, named routes via Ziggy `route()`, `<script setup lang="ts">`, `interface Props`, `useForm`, theme tokens only (no raw colours).
- Three separate `useForm` instances so saving one card never submits or resets another. Show "Saved." with the same `TransitionRoot` + `recentlySuccessful` pattern as `settings/Password.vue`. Disable a card's submit button while `form.processing`.
- Weekly hours UI: one row per weekday (Sunday to Saturday). A `Checkbox` toggles the day on; turning it on adds a 09:00-17:00 range, turning it off removes that day's ranges. There's an add-range (+) and a remove-range (x) button per range. Native `<select>`s use `Input`-like classes and offer 15-minute steps from 00:00 to 23:45, shown in 12-hour format and submitted as `HH:MM`. Every select needs an accessible name (for example `aria-label="Tuesday range 2 end time"`). Show errors under the row using `InputError`, and set `aria-invalid` on the offending selects.
- States to cover:
  - Empty weekly hours: a notice that visitors will see no open times.
  - Empty blocked dates: "No blocked dates yet."
  - Unsaved changes: `form.isDirty` shows an "Unsaved changes" hint and a Discard button (`form.reset()`).
  - Validation errors inline, and the global Inertia error behaviour for unexpected server errors.
- Blocked-date reasons are user text: render with `{{ }}` interpolation only, never `v-html`.
- Show the timezone in the page header ("Hours are in Asia/Manila") and under the timezone select ("Changing this keeps the same hours in the new timezone.").
- Keep `SlotService` free of HTTP or Inertia concerns and inject nothing exotic: plain model queries and Carbon. Feature 4 will call it from a controller.
- Do not touch the migrations; the shipped schema already fits.
- Remove nothing from `prototypes/` in this feature; `/complete` handles that.

## Open questions

These don't block the build; the spec uses the values shown. Confirm or adjust at review:

1. **Rule limits:** meeting length 15/30/45/60 min, buffer 0-120 min, minimum notice 0-720 h, booking window 1-365 days.
2. **Time picker granularity:** 15-minute steps, and no range may end at midnight (latest end is 23:45).
3. **Buffer meaning:** buffer pads only existing bookings (both sides), not every free slot.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":14202,"specSha256":"2ebd46a9576dd356781f56f6bdd5726e3ae2d8d8f66c6373ad591f9ae035a89e","branch":"refs/heads/feature/availability-management","head":"e3e673312c5e2b10e373836d98d4db43a06912b3","baseRef":"refs/heads/main","baseCommit":"e3e673312c5e2b10e373836d98d4db43a06912b3","sourceTree":"06945e1a2b4548d7f70d11aa07567f255a5c4851","absentOptional":[]} -->
