# Simplified Booking: Project Plan

A self-hosted booking app in the style of Calendly. A single admin manages availability and appointments. Visitors book open time slots from a public calendar without needing an account.

Setup and usage instructions are in [README.md](README.md).

---

## 1. Goals

- **Simple for visitors:** pick a day, pick a time, enter name and email, done. No account needed.
- **Simple for the admin:** one dashboard showing all appointments, and one page for working hours.
- **Secure:** only the admin can log in, and there's no public sign-up.
- **Cheap to run:** a single small VPS, no paid services required.

## 2. Features

### Phase 1: MVP
| Area | Feature | Details |
|---|---|---|
| Admin login | Only the admin can log in | Admin account created by the seeder from `.env`; login rate-limited; no registration; admin can't delete their own account |
| Availability | Weekly working hours | Several time ranges per weekday are allowed (e.g. 09:00-12:00 and 13:00-17:00) |
| Availability | Booking rules | Slot length, buffer between meetings, minimum notice, how many days ahead people can book |
| Availability | Blocked dates | Holidays and days off, with an optional reason |
| Dashboard | Appointment list | Upcoming and past appointments, filtered by date and status |
| Appointments | Admin creates | Admin can book for someone and skip the minimum-notice rule |
| Appointments | Reschedule / cancel | Status changes instead of deleting, so history is kept |
| Public | Availability calendar | Month view, then the open times for the chosen day, shown in the visitor's timezone |
| Public | Booking form | Name, email and notes; the server re-checks the slot before saving |
| Public | Confirmation | Success page, plus an email with an `.ics` calendar invite |

### Phase 2: Quality of life
- Visitors cancel or reschedule through a private link (`/manage/{token}`)
- Reminder emails 24 hours and 1 hour before (Laravel scheduler)
- Multiple meeting types (e.g. 15-min intro, 60-min consult), each with its own length and link
- Custom booking questions
- Calendar view on the dashboard (week and month)
- Admin email alert for each new booking

### Phase 3: Growth
- Google and Outlook calendar sync (busy times block slots; bookings added to the admin's calendar)
- Automatic Google Meet or Zoom links
- Paid bookings through Stripe Checkout
- Multiple staff members, each with their own availability (optional round-robin)
- Embeddable booking widget for other websites
- Analytics: booking volume, no-shows, busiest hours
- Two-factor login (TOTP) for the admin

## 3. Tech stack

| Layer | Choice | Why |
|---|---|---|
| Backend | **Laravel 12** | Login, queues, scheduler, email and validation are built in; well-documented VPS deployment |
| Frontend | **Inertia.js + Vue 3 + TypeScript** | A single-page-app feel without building a separate API |
| UI | **Tailwind CSS + shadcn-vue** | Ready-made form, dialog, table and calendar components |
| Database | **SQLite** locally, **MySQL or Postgres** in production | No database server needed for development; switch by changing `.env` |
| Email | Laravel Mail (SMTP / Resend / Postmark) | `log` driver locally |
| Tests | PHPUnit | In-memory SQLite |

Other options we considered: Next.js + Postgres (all TypeScript, best suited to Vercel), and SvelteKit + PocketBase (fastest to build, harder to grow). We chose Laravel because it's self-hosted on a VPS and has the most built in.

## 4. Key design decisions

1. **Open slots are calculated when requested, never stored.**
   Open slots = weekly hours − blocked dates − active appointments (plus buffer) − minimum-notice window − anything beyond `max_days_ahead`. One service, `SlotService::availableSlots()`, does this and has its own tests.
2. **All times are stored in UTC.** Working hours are defined in the admin's timezone (`settings.timezone`). Visitors see times in their browser's timezone.
3. **The database prevents double booking.** `appointments.slot_lock` holds `start_at` while an appointment is active and becomes NULL when it's cancelled. A unique index on it blocks two live bookings with the same start time. This works on SQLite, MySQL and Postgres. Bookings whose times overlap without starting at the same moment are checked with `Appointment::overlapping()` inside a database transaction.
4. **Visitors don't need accounts.** Each appointment gets a random 64-character `manage_token`, which is hidden from JSON output, for cancel/reschedule links.
5. **Configuration goes through `config/booking.php`**, never `env()` directly in code, so it keeps working after `php artisan config:cache` in production.

## 5. Data model

| Table | Columns | Notes |
|---|---|---|
| `users` | name, email, password | The admin account |
| `settings` | timezone, slot_minutes (30), buffer_minutes (0), min_notice_hours (4), max_days_ahead (60) | Single row; use `Setting::current()` |
| `availability_rules` | weekday (0 = Sunday), start_time, end_time | Several rows per weekday allowed |
| `blocked_dates` | date (unique), reason | |
| `appointments` | start_at, end_at (UTC), status, name, email, notes, manage_token, created_by, slot_lock | Status: `confirmed` or `cancelled` |

Phase 2 adds `event_types` (name, slug, duration, description) and an `appointments.event_type_id` column.

## 6. Routes

| Route | Access | Milestone |
|---|---|---|
| `/` | public: booking calendar | M3 (placeholder now) |
| `/book/confirmed/{id}` | public: confirmation | M3 |
| `/manage/{token}` | public, needs the token: cancel/reschedule | Phase 2 |
| `/login`, `/forgot-password` | guests only | ✅ M1 |
| `/admin` | admin: appointments dashboard | ✅ M1 (placeholder) → M4 |
| `/admin/availability` | admin: hours, blocked dates, rules | M2 |
| `/admin/appointments/*` | admin: create / view / reschedule / cancel | M4 |
| `/settings/*` | admin: profile, password, appearance | ✅ M1 |

## 7. Milestones

- [x] **M1: Foundation**
  Laravel + Vue setup, admin-only login, `/admin` protected, schema + models, seeder, tests (31 passing)
- [ ] **M2: Availability**
  Admin page for weekly hours, blocked dates and booking rules; `SlotService` with timezone and edge-case tests
- [ ] **M3: Public booking**
  Month calendar → open times → booking form → confirmation page; transaction + `slot_lock` guard; rate limit and honeypot field against spam
- [ ] **M4: Admin appointments**
  Dashboard list with filters; create, reschedule and cancel
- [ ] **M5: Email + deploy**
  Confirmation email with `.ics`, queue worker, production `.env`, deploy to VPS

After the MVP, pick Phase 2 features one at a time.

## 8. Security checklist

- [x] No public registration route
- [x] Login rate-limited (per route, plus 5 failed attempts per email/IP)
- [x] `/admin` protected by the `auth` middleware
- [x] Passwords hashed (bcrypt); session cookies httpOnly; CSRF protection on all forms
- [x] Admin can't delete their own account
- [ ] Rate limit and honeypot field on the public booking form (M3)
- [ ] All input checked with Form Requests (M2-M4)
- [ ] HTTPS only in production, `APP_DEBUG=false`, secure cookies (M5)
- [ ] Admin 2FA (Phase 3)

## 9. Local development

- The database is one file, `database/database.sqlite` (git-ignored). See [README.md → The database](README.md#the-database).
- `composer run dev` starts the server, queue, logs and Vite together.
- Email uses the `log` driver locally; messages appear in `storage/logs/laravel.log`.

## 10. Deployment (VPS)

**Hosting options** (approximate starting prices; check current pricing):

| Provider | Starting plan | Notes |
|---|---|---|
| Hetzner Cloud | ~€4-5/mo | Best value; EU, US and Singapore regions |
| DigitalOcean | ~$6/mo | Easiest to start; many Laravel guides |
| Vultr | ~$5-6/mo | Many Asia regions (Singapore, Tokyo) |
| Linode (Akamai) | ~$5/mo | Reliable, similar to DigitalOcean |
| AWS Lightsail | ~$5/mo | Makes sense if you already use AWS |

1-2 GB of RAM is enough. Pick the region closest to your users.

**Server setup tools** (optional): Laravel Forge (~$12/mo, official), Ploi (free tier), Coolify (free, self-hosted). Laravel Cloud is a fully managed alternative.

**Server stack:** Ubuntu LTS, Nginx, PHP 8.4-FPM, MySQL 8 or Postgres 16, Supervisor (queue worker), cron (scheduler), Let's Encrypt (HTTPS).

**First deploy:**
1. Create the database and a database user; set up `.env` with `APP_ENV=production`, `APP_DEBUG=false`, the `DB_*` values, `MAIL_*` values, `ADMIN_*` values and `BOOKING_TIMEZONE`.
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build`
4. `php artisan key:generate` (first deploy only)
5. `php artisan migrate --force --seed` (seed on the first deploy only)
6. `php artisan optimize`
7. Supervisor: `php artisan queue:work --tries=3`
8. Cron: `* * * * * php /path/to/artisan schedule:run`

**Later deploys:** pull, then run steps 2, 3, 5 (without `--seed`) and 6, then `php artisan queue:restart`.
