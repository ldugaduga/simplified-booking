# Simplified Booking - Project Overview

<!-- blueprint:source-hash fc43a95d735dad416ae09ddaab7e3bf442cc621c7a00b3c13dd90dbc01f41945 -->

> A small, self-hosted Calendly-style booking app for a client: visitors book open time slots without an account, and one admin manages availability and appointments.

## Problem

The client needs people to book appointments without email or phone back-and-forth. Hosted tools like Calendly are more than they need and cost per seat. This app is a self-hosted booking page plus a single admin dashboard.

## Users

- **Admin (the client)** - one account, created by the seeder from `.env`. Sets working hours and blocked dates; creates, reschedules, and cancels appointments. Uses `/admin`.
- **Visitors (the client's customers)** - anonymous. Pick a time and enter name, email, and notes. Never create an account.

There is no public registration. This is a client project; the client is the admin.

## Usage model

- Internet-facing: the booking page is public; `/admin` is for one trusted admin.
- Single tenant, single admin. Multiple staff (feature 20) is not a current requirement.
- Visitors are untrusted input: validate every booking field, rate-limit the booking form, and guard double booking at the database level.
- Visitor names and emails are personal data: keep them out of logs and URLs.

> TODO (confirm): expected booking volume; assumed small (dozens per week, one small VPS).

## Features

Build-plan order. Items 1-2 are shipped. The headline feature is **4. Public booking flow**.

**MVP**
1. **Admin-only authentication** (done) - login, password reset, profile/password settings; no registration or self-delete; `/admin` protected; login rate-limited.
2. **Booking data model** (done) - settings, availability rules, blocked dates, appointments; seeder; `slot_lock` double-booking guard.
3. **Availability management** - admin page for weekly hours, blocked dates, and booking rules; tested `SlotService` computing open slots across timezones.
4. **Public booking flow** - month calendar, open times in the visitor's timezone, booking form, confirmation page; transactional slot check, rate limit, honeypot.
5. **Admin appointments** - dashboard list with date and status filters; admin create, reschedule, cancel.
6. **Confirmation email** - queued email with `.ics` invite on booking, reschedule, and cancel.
7. **Production readiness** - production `.env` template, HTTPS/secure cookies/debug off, VPS deploy checklist with queue worker and cron.

**Phase 2**
8. **Visitor self-service** - private `/manage/{token}` link to cancel or reschedule.
9. **Reminder emails** - scheduled 24h and 1h reminders.
10. **Admin new-booking alert** - email the admin on each booking.
11. **Multiple meeting types** - name, duration, booking link per type.
12. **Custom booking questions** - admin-defined extra form fields.
13. **Dashboard calendar view** - week and month views.

**Phase 3 (not yet committed)**
14. **Calendar sync** - Google/Outlook busy times block slots; bookings written to the admin calendar.
15. **Automatic video links** - Google Meet or Zoom per appointment.
16. **Paid bookings** - Stripe Checkout before confirming.
17. **Admin two-factor login** - TOTP.
18. **Embeddable widget** - booking page embeddable on other sites.
19. **Booking analytics** - volume, no-shows, busiest hours.
20. **Multiple staff** - per-person availability, optional round-robin.

## Data model

Shipped in feature 2. Times are stored in UTC. Code must work on SQLite, MySQL, and Postgres.

### User (`users`)

- `id` (bigint), `name` (string), `email` (string, unique), `password` (hashed), `email_verified_at` (datetime, nullable), `remember_token`, timestamps
- Only the admin. Has many `Appointment` via `created_by`.

### Setting (`settings`)

- `id` (always 1; use `Setting::current()`)
- `timezone` (string, default `UTC`, seeded from `BOOKING_TIMEZONE`) - timezone working hours are defined in
- `slot_minutes` (smallint, 30), `buffer_minutes` (smallint, 0), `min_notice_hours` (smallint, 4), `max_days_ahead` (smallint, 60)

### AvailabilityRule (`availability_rules`)

- `weekday` (tinyint, 0 = Sunday ... 6 = Saturday, indexed)
- `start_time`, `end_time` (time, in `settings.timezone`)
- Several rows per weekday allowed (split shifts). Seeded Mon-Fri 09:00-17:00.

### BlockedDate (`blocked_dates`)

- `date` (date, unique), `reason` (string, nullable)

### Appointment (`appointments`)

- `start_at`, `end_at` (datetime, UTC; cast `immutable_datetime`)
- `status` (string, cast to `App\Enums\AppointmentStatus`: `confirmed` | `cancelled`, default `confirmed`)
- `name` (string), `email` (string), `notes` (text, nullable)
- `manage_token` (string(64), unique, auto-generated, hidden from serialization)
- `created_by` (foreign key to `users`, nullable, null on delete) - set when the admin books
- `slot_lock` (datetime, nullable, unique, hidden) - equals `start_at` while active, null when cancelled; set in the `saving` hook
- Index on (`start_at`, `status`). Scopes: `active()`, `overlapping($start, $end)`.

> Locked shapes: `slot_lock` + overlap check is the double-booking contract features 4, 5, and 8 rely on. Cancel by status, never delete. `manage_token` backs feature 8.

## Tech stack

- **Laravel 12 (PHP 8.2+)** - routing, auth, validation, queues, scheduler, mail
- **Inertia.js 2 + Vue 3 + TypeScript** - SPA-style pages without a separate API
- **Tailwind CSS 3 + shadcn-vue (radix-vue) + lucide-vue-next** - UI components and icons
- **Ziggy** - named Laravel routes in Vue via `route()`
- **SQLite** locally; **MySQL or Postgres** in production (`.env` switch only)
- **PHPUnit 11** - feature tests on in-memory SQLite (`php artisan test`)
- **Pint, Prettier, ESLint** - formatting and linting

## Monetization

Not decided. The app is a client deliverable. Paid bookings via Stripe (feature 16) remain optional.

## UI/UX

Clean and minimal, Calendly-like: a centered booking card with a month calendar, time buttons for the chosen day, and a short form. The admin uses the starter kit's sidebar layout with light and dark modes.

- `/` - public booking page (placeholder until feature 4)
- `/book/confirmed/{id}` - booking confirmation (feature 4)
- `/manage/{token}` - visitor cancel/reschedule (feature 8)
- `/login`, `/forgot-password`, `/reset-password/{token}` - admin auth (shipped)
- `/admin` - appointments dashboard (placeholder until feature 5)
- `/admin/availability` - hours, blocked dates, rules (feature 3)
- `/admin/appointments/...` - create, view, reschedule, cancel (feature 5)
- `/settings/profile`, `/settings/password`, `/settings/appearance` - admin settings (shipped)

> TODO (confirm): client branding (logo, colours, business name) and any intro text or photo on the booking page.

## Deployment

- **Target:** Linux VPS, provider not chosen (Hetzner or DigitalOcean leading). Ubuntu LTS, Nginx, PHP 8.4-FPM, MySQL 8 or Postgres 16, Let's Encrypt. Optionally managed by Forge, Ploi, or Coolify.
- **Build:** `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`
- **Release:** `php artisan migrate --force` (`--seed` on first deploy only), `php artisan optimize`
- **Worker:** Supervisor running `php artisan queue:work --tries=3`
- **Cron:** `* * * * * php artisan schedule:run`
- **Env vars:** `APP_KEY`, `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`, `DB_*`, `MAIL_*`, `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `BOOKING_TIMEZONE`
- **Health check:** `/up`

> TODO (confirm): domain name and mail provider.

## Open questions

> Resolve in the plans, then re-run /overview.

- Client business type and what appointments are for (affects copy and form fields).
- Client branding, domain, mail provider, and expected booking volume (see TODOs above).
- `PLAN.md` in the repo root duplicates these plans with milestone numbering (M1-M5) that differs from build-plan IDs (M5 is split into features 6 and 7). Decide whether to keep it in sync, trim it to a pointer, or remove it.
