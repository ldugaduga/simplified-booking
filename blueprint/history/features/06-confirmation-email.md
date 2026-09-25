# Feature: Confirmation email

**From build-plan:** feature 6
**Build attempt:** 1
**Status:** verified
**Branch:** feature/confirmation-email

## Goal

Send the visitor a queued confirmation email with a calendar invite (`.ics`) whenever an appointment is booked, rescheduled, or cancelled: from the public booking flow (feature 4) and from the admin's create, reschedule, and cancel actions (feature 5). The email carries no visitor-management link (that's feature 8) and no admin notification (feature 10).

## In scope

- `App\Services\IcsGenerator`: builds a minimal RFC 5545 `.ics` string for one appointment
- Three queued Mailables: `AppointmentBooked`, `AppointmentRescheduled`, `AppointmentCancelled`, each with a plain Blade view and an attached `.ics`
- Sending calls added to the four places that create, reschedule, or cancel an appointment: `BookingController::store`, `Admin\AppointmentController::store`, `::reschedule`, `::cancel`
- Feature tests asserting the right mailable is queued to the right address, and unit-style tests for the ICS content

## Out of scope

- Any admin notification email (feature 10)
- Visitor self-service links using `manage_token` (feature 8)
- Reminder emails (feature 9)
- Retrying or alerting on a failed send beyond Laravel's normal queued-job retry
- Schema changes (no new columns)

## Build loop

- `workflow.stepReview` is `feature`: build all steps, then present one review packet at the end.
- `workflow.checkpointCommits` is `disabled`: no commits during `/implement`. `/complete` creates the feature commit.
- Every step leaves `php artisan test` green and `npm run build` passing.
- This feature only touches Mail/queue code and views; the browser-based UI is unchanged, so no screenshot evidence is required. `Mail::fake()` is the evidence for sending; `MAIL_MAILER=log` plus a manually triggered booking is the manual try path.

## Build steps

- [x] **1. `IcsGenerator` and its tests.** Add `App\Services\IcsGenerator::forAppointment(Appointment $appointment, string $method): string`, `$method` being `REQUEST` or `CANCEL`. Add `tests/Unit/IcsGeneratorTest.php`.
  - Done when: the cases under Testing for the generator pass.
- [x] **2. Mailables and views.** Add the three Mailable classes and their Blade views under `resources/views/emails/`. Add `tests/Feature/Mail/AppointmentMailTest.php` that builds each Mailable directly (no controller involved) and asserts its subject, recipient, and that the `.ics` attachment is present with the right MIME `method`.
  - Done when: those tests pass.
- [x] **3. Wire the four triggers.** Add the `Mail::to($appointment->email)->queue(...)` call to each of the four call sites, placed after the appointment is saved (outside the `DB::transaction`/`guardSlot` call, before the controller returns). Extend `tests/Feature/Booking/BookAppointmentTest.php` and `tests/Feature/Admin/AppointmentActionsTest.php` with `Mail::fake()` assertions for each trigger, including that cancelling an already-cancelled appointment queues nothing.
  - Done when: the cases under Testing for the triggers pass, and the full suite (`php artisan test`) stays green.

## Files / areas

- `app/Services/IcsGenerator.php` - new
- `app/Mail/AppointmentBooked.php`, `AppointmentRescheduled.php`, `AppointmentCancelled.php` - new
- `resources/views/emails/appointment-booked.blade.php`, `appointment-rescheduled.blade.php`, `appointment-cancelled.blade.php` - new
- `app/Http/Controllers/BookingController.php` - one line in `store`
- `app/Http/Controllers/Admin/AppointmentController.php` - one line each in `store`, `reschedule`, `cancel`
- `tests/Unit/IcsGeneratorTest.php`, `tests/Feature/Mail/AppointmentMailTest.php` - new
- `tests/Feature/Booking/BookAppointmentTest.php`, `tests/Feature/Admin/AppointmentActionsTest.php` - extended

## Data / contracts

### `IcsGenerator::forAppointment(Appointment $appointment, string $method): string`

Builds this structure (CRLF line endings, per RFC 5545):

```
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Simplified Booking//EN
METHOD:{REQUEST|CANCEL}
BEGIN:VEVENT
UID:appointment-{id}@{host}
DTSTAMP:{now, UTC, YYYYMMDDTHHMMSSZ}
DTSTART:{start_at, UTC, YYYYMMDDTHHMMSSZ}
DTEND:{end_at, UTC, YYYYMMDDTHHMMSSZ}
SUMMARY:{slot_minutes}-minute meeting with {config('app.name')}
STATUS:{CONFIRMED|CANCELLED}
END:VEVENT
END:VCALENDAR
```

- `{host}`: `parse_url(config('app.url'), PHP_URL_HOST)`, falling back to `localhost` if that's empty.
- `UID` is the same for booked and rescheduled invites to the same appointment, so a calendar app updates the existing event instead of adding a duplicate.
- `STATUS` is `CANCELLED` only when `$method === 'CANCEL'`; otherwise `CONFIRMED`.
- Values containing a comma, semicolon, or backslash are escaped per RFC 5545 (only `SUMMARY` can contain one, via `config('app.name')`).

### Mailables

| Class | Trigger | Subject | ICS method |
|---|---|---|---|
| `AppointmentBooked` | visitor or admin creates a confirmed appointment | "You're booked: {slot_minutes}-minute meeting on {date} at {time} ({tz abbr})" | `REQUEST` |
| `AppointmentRescheduled` | admin reschedules | "Your appointment has moved to {date} at {time} ({tz abbr})" | `REQUEST` |
| `AppointmentCancelled` | admin cancels an active appointment | "Your appointment on {date} at {time} ({tz abbr}) has been cancelled" | `CANCEL` |

- Each `implements ShouldQueue`, `use Queueable, SerializesModels`.
- Constructor takes the `Appointment` model (via `SerializesModels`, so the queued job re-reads it from the database when it runs).
- `envelope()` sets the subject above; `content()` points at the matching Blade view; `attachments()` calls `Attachment::fromData(fn () => IcsGenerator::forAppointment($this->appointment, '<METHOD>'), 'invitation.ics')->withMime("text/calendar; charset=utf-8; method=<METHOD>")`.
- Dates and times in the subject and view body are formatted in `Setting::current()->timezone` (the admin's configured timezone), since the visitor's browser timezone isn't stored server-side. Use `Carbon`'s `T` format for the abbreviation (for example "PST", "+08").
- Views show: what (the meeting length and business name) and when (date, time range, timezone). For `AppointmentRescheduled`, the previous time is not shown, since the model only has the new one; the view says "This replaces any earlier invite for this meeting." For `AppointmentCancelled`, the view says the time is now free and does not attach a "confirmed" tone.
- No visitor-facing link of any kind (no manage/cancel/reschedule URL): that's feature 8.

### Trigger call sites

| Controller | Method | Mailable | Condition |
|---|---|---|---|
| `BookingController` | `store` | `AppointmentBooked` | always, after the transaction commits |
| `Admin\AppointmentController` | `store` | `AppointmentBooked` | always, after the transaction commits |
| `Admin\AppointmentController` | `reschedule` | `AppointmentRescheduled` | always, after the transaction commits |
| `Admin\AppointmentController` | `cancel` | `AppointmentCancelled` | only when the appointment was active and got cancelled (the existing no-op branch on an already-cancelled appointment sends nothing) |

Each call is `Mail::to($appointment->email)->queue(new X($appointment));`, placed after the row is saved and the surrounding transaction/guard call has returned, before the controller's `return back()` / redirect. A failure to enqueue (for example a broken queue connection) is not caught specially; it behaves like any other unexpected server error already possible in these actions.

## Testing

Run `php artisan test`. Use `Mail::fake()` for every controller-level test; nothing here needs a real queue worker or `npm run build` changes.

**`IcsGeneratorTest`**
- Produces `BEGIN:VCALENDAR` ... `END:VCALENDAR` with CRLF line endings
- `DTSTART`/`DTEND` match `start_at`/`end_at` converted to UTC in `YYYYMMDDTHHMMSSZ`
- `UID` is stable for the same appointment across two calls (booked, then rescheduled)
- `METHOD:REQUEST` + `STATUS:CONFIRMED` for `REQUEST`; `METHOD:CANCEL` + `STATUS:CANCELLED` for `CANCEL`
- A business name containing a comma is escaped in `SUMMARY`

**`AppointmentMailTest`**
- Each of the three Mailables: correct subject text (spot-check the date/time formatting), addressed to the appointment's email, and an attachment present whose MIME type contains `method=REQUEST` or `method=CANCEL` as expected
- The rendered view contains the meeting length and the business name, and contains no `manage_token` value anywhere in its output

**Trigger tests (added to existing files)**
- A successful public booking (`POST /book`) queues one `AppointmentBooked` to the visitor's email; a rejected booking (any existing failure case) queues nothing
- Admin `store` queues one `AppointmentBooked`
- Admin `reschedule` queues one `AppointmentRescheduled` to the appointment's email
- Admin `cancel` on an active appointment queues one `AppointmentCancelled`; calling `cancel` again on the now-cancelled appointment queues nothing

## Notes for the AI

- Follow `coding-standards.md`: typed properties/returns, `{{ }}` (never `{!! !!}`) for any user-controlled text in the Blade views (name, notes are not shown; only business-controlled `config('app.name')` and appointment times appear).
- `Mailable` classes go in `app/Mail/`, matching Laravel's convention (there's no existing convention to follow yet in this project, since this is the first mail feature).
- Reuse `Setting::current()` for the timezone; don't add a new settings mechanism.
- Don't build a shared "AppointmentMailer" abstraction for the four one-line call sites: proportional engineering says a direct `Mail::to(...)->queue(...)` call at each site is simpler than a new service for three call patterns.
- `.env`/`.env.example` already default to `MAIL_MAILER=log` and `QUEUE_CONNECTION=database`; no config changes are needed for this feature to work locally or in tests.
- Keep `IcsGenerator` a stateless service (a plain class with one static-feeling public method taking its inputs as arguments), consistent with `SlotService`'s style of a constructor-injected, side-effect-free service.

## Open questions

These don't block the build; the spec uses the values shown:

1. **No sequence counter for reschedules.** RFC 5545 calendar clients often use a `SEQUENCE` number to recognize an update to the same event. This project has no place to store one without a schema change, so every invite is sent as `SEQUENCE:0` implicitly (omitted, which most clients treat as 0). Some calendar apps may show the rescheduled invite as a separate update prompt rather than silently moving the event: this is a known limitation, not a bug, and can be revisited if it causes real friction.
2. **Admin-created bookings get the same "You're booked" email as public ones**, since the spec's wording ("sent on booking") doesn't distinguish who initiated it and the visitor still needs the invite either way.
3. **Timezone shown in the email is the business's configured timezone**, not the visitor's, because the visitor's timezone is only known in their browser at booking time and isn't persisted.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":11145,"specSha256":"6a09e6ff6cc7ff5fa49cc8d565f74ec1306096c2c69c1e53cbef6eea1f2d6e86","branch":"refs/heads/feature/confirmation-email","head":"7391b4dd91148465003a9fa6338e4107e819540a","baseRef":"refs/heads/main","baseCommit":"da27f1348ac9cc54d106b16451b32f16fa7788b5","sourceTree":"50e8ae4397913513209da9c6fa99d7e5f971683f","absentOptional":[]} -->

## Independent review

**Status:** passed
**Target commit:** 7391b4dd91148465003a9fa6338e4107e819540a
**Base commit:** da27f1348ac9cc54d106b16451b32f16fa7788b5
**Base ref:** refs/heads/main
**Spec hash:** 6a09e6ff6cc7ff5fa49cc8d565f74ec1306096c2c69c1e53cbef6eea1f2d6e86
**Prepared by:** claude
**Builder model:** claude-sonnet-5
**Requested reviewer:** claude
**Requested model:** claude-sonnet-5
**Requested execution:** automatic
**Requested at:** 2026-09-25T00:14:16Z
**Workflow:** regular
**Check required:** no
**Reviewer adapter:** claude
**Reviewer model:** claude-sonnet-5
**Reviewer context:** fresh subagent
**Actual execution:** automatic
**Reviewed at:** 2026-09-25T00:16:35Z
**Scope:** current
**Lenses:** quality, security, performance, tests
**Verdict:** passed
**Check result:** not-required

### Handoff

Review the active spec and the complete `da27f1348ac9cc54d106b16451b32f16fa7788b5..7391b4dd91148465003a9fa6338e4107e819540a` delta in a fresh
session or isolated subagent without the builder conversation. Run all Audit lenses from scratch.
Run Check when required above. Do not edit product code, accept findings, or
reuse the existing findings as the review scope.

### Commands

- `php artisan test`: pass (98 passed, 624 assertions)
- `npm run build`: pass
- `./vendor/bin/pint --test`: pass
- `npm run format:check`: pass

### Evidence

- `git diff da27f1348ac9cc54d106b16451b32f16fa7788b5..7391b4dd91148465003a9fa6338e4107e819540a --stat` reviewed in full: 15 files, IcsGenerator, three Mailables plus the shared abstract base, three Blade views, four controller trigger sites, and the new/extended tests.
- `app/Services/IcsGenerator.php` verified against RFC 5545: CRLF line endings, stable `UID` across `REQUEST`/`CANCEL` calls for the same appointment, UTC `DTSTART`/`DTEND` in `Ymd\THis\Z`, `STATUS` tied correctly to `$method`, and `SUMMARY` escaping confirmed correct by inspecting the literal PHP string `addcslashes($value, "\;,\n")` byte-by-byte (`php -r`) as backslash, semicolon, comma, newline — the exact RFC 5545 TEXT-escape set — and cross-checked against the passing comma-in-business-name test.
- `app/Mail/AppointmentMailable.php` and its three subclasses: all `implements ShouldQueue`, `use Queueable, SerializesModels`; confirmed via `phpunit.xml` (`MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`) and `.env.example` (`QUEUE_CONNECTION=database`, `MAIL_MAILER=log`) that this matches the spec's stated test/production queue behavior.
- All three Blade views (`resources/views/emails/appointment-*.blade.php`) read in full: only `{{ }}` output, only `$minutes`, `$when`, and `config('app.name')` are rendered — no `manage_token`, `name`, or `notes`, and no `{!! !!}`. `AppointmentMailTest` additionally asserts `manage_token` is absent from each rendered view.
- All four trigger call sites read in context: `BookingController::store` queues after `DB::transaction(...)` returns (with the `UniqueConstraintViolationException` catch already resolved); `Admin\AppointmentController::store`/`reschedule` queue after `guardSlot()` (which wraps `DB::transaction`) returns; `cancel` queues only inside the `if ($appointment->status->isActive())` branch, so a re-cancel is a no-op. All four calls sit before the controller's `return`/`redirect`. No mail is queued while a transaction could still roll back, so there is no stale-read risk to document as a finding; `SerializesModels` re-fetching at execution time is not actually load-bearing here since the row is already committed before `queue()` is called.
- `tests/Unit/IcsGeneratorTest.php`, `tests/Feature/Mail/AppointmentMailTest.php`, and the extended `tests/Feature/Booking/BookAppointmentTest.php` / `tests/Feature/Admin/AppointmentActionsTest.php` read in full: cover CRLF format, UTC conversion, UID stability, method/status pairing, summary escaping, per-mailable subject/recipient/attachment assertions, rendered-view content, and `Mail::fake()`/`assertQueued`/`assertNothingQueued` at all four trigger sites including the reject-booking and re-cancel no-op cases named in the spec.
- Proportionality: the abstract `AppointmentMailable` base class centralizes `formattedWhen()`, `minutes()`, and `icsAttachment()`, which all three concrete Mailables use verbatim; without it the same three methods would be duplicated three times. This is ordinary inheritance inside one class family, not the "AppointmentMailer" call-site abstraction the spec explicitly rejected, so it is justified.

### Findings

- None

### Remaining risk

- F-03 and F-04 in the findings ledger are pre-existing, unrelated `unverified` P2 leads (SQLite lock-vs-unique-violation race, proxy-dependent per-IP throttling); this feature's diff does not touch that code and neither lead was re-examined.
- No real two-process concurrency or production queue-worker run was exercised; `QUEUE_CONNECTION=sync` in tests means the mail send path was verified with immediate in-process execution and `Mail::fake()`, not against an actual queued/database-driven worker.
