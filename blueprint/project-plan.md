# Project Plan

> Seeded by `/adopt` from the existing code, `PLAN.md`, and the adoption
> interview. Items marked `> TODO (confirm)` are inferences to check. When this
> and `build-plan.md` look right, run `/overview`.

## 1. Problem - What problem are we solving?

A client needs a simple way for people to book appointments without the
back-and-forth of email or phone. Hosted tools like Calendly are more than they
need (and a recurring cost per seat). This app is a small, self-hosted booking
page: visitors see open times and book one in a few clicks, and a single admin
sees and manages every appointment in one place.

> TODO (confirm): the client's business type and what the appointments are for
> (consultations, services, meetings), since that shapes copy and form fields.

## 2. Users - Who is this for?

- **Admin (the client):** one person who sets working hours, blocks days off, and
  creates, reschedules, or cancels appointments. Logs in to `/admin`.
- **Visitors (the client's customers):** anyone with the booking link. They never
  create an account; they pick a time and enter name, email, and notes.

This is a client project: it is built for someone else's business, and they
will be the admin.

## 3. Features - What does the MVP need?

Already built:
- Admin-only login (no public sign-up, rate-limited, admin cannot delete own account)
- Protected admin area at `/admin` with profile, password, and appearance settings
- Data model for settings, weekly hours, blocked dates, and appointments, with a
  database-level guard against two live bookings in the same slot

Still to build for the MVP:
- Admin availability page: weekly hours, blocked dates, slot length, buffer,
  minimum notice, booking window
- Public booking: month calendar, open times in the visitor's timezone, booking
  form, confirmation page
- Admin appointments: dashboard list with filters, create, reschedule, cancel
- Confirmation email with an `.ics` calendar invite

Later (Phase 2 and 3 in `PLAN.md`): visitor self-cancel/reschedule links,
reminders, multiple meeting types, custom questions, calendar view, calendar
sync, video links, paid bookings, multiple staff, embeddable widget, analytics,
admin 2FA.

## 4. Data - What are we storing?

- `users` - the admin account
- `settings` - single row: timezone, slot_minutes, buffer_minutes,
  min_notice_hours, max_days_ahead
- `availability_rules` - weekday (0 = Sunday), start_time, end_time; several per day allowed
- `blocked_dates` - date (unique), reason
- `appointments` - start_at and end_at (UTC), status (`confirmed` / `cancelled`),
  name, email, notes, manage_token (private), created_by, slot_lock (unique while active)

Visitor names and emails are personal data; keep them out of logs and URLs.

## 5. Tech - What stack are we using?

- Laravel 12 (PHP 8.2+), Inertia.js 2, Vue 3 with TypeScript
- Tailwind CSS 3, shadcn-vue components (radix-vue), lucide-vue-next icons, Ziggy `route()` helper
- SQLite locally; MySQL or Postgres in production (switch via `.env` only)
- PHPUnit feature tests with an in-memory SQLite database
- Laravel Pint, Prettier, and ESLint for formatting and linting

Chosen over Next.js + Postgres and SvelteKit + PocketBase because it self-hosts
cleanly on a VPS and ships auth, queues, scheduler, and mail in the box.

## 6. Monetize - How will this make money?

Not decided. The app itself is a client deliverable. Paid bookings through
Stripe Checkout are on the Phase 3 list if the client wants to charge per
appointment.

## 7. UI/UX - How should this look and feel?

Clean and minimal, in the spirit of Calendly: a centered booking card with a
month calendar, a list of time buttons for the chosen day, and a short form. The
admin area uses the starter kit's sidebar layout and shadcn-vue components, with
light and dark appearance modes.

> TODO (confirm): client branding (logo, colors, business name) and whether the
> booking page needs any intro text or photo.

## 8. Deployment - Where and how will this ship?

- **Target:** a Linux VPS (provider not chosen; Hetzner or DigitalOcean are the
  leading options). Ubuntu LTS, Nginx, PHP 8.4-FPM, MySQL 8 or Postgres 16,
  Let's Encrypt TLS. Laravel Forge, Ploi, or Coolify may manage the server.
- **Build:** `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`
- **Release:** `php artisan migrate --force` (plus `--seed` on first deploy only), `php artisan optimize`
- **Workers:** Supervisor running `php artisan queue:work --tries=3` (emails)
- **Cron:** `* * * * * php artisan schedule:run` (reminders, Phase 2)
- **Env vars:** `APP_KEY`, `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`,
  `DB_*`, `MAIL_*`, `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `BOOKING_TIMEZONE`
- **Health check:** Laravel's built-in `/up`

> TODO (confirm): domain name and which mail provider the client will use.

## 9. Usage model and constraints (optional)

- Internet-facing: the booking page is public; `/admin` is for one trusted admin.
- Single tenant, single admin. Multiple staff is a possible Phase 3 item, not a
  current requirement.
- Visitors are untrusted input: validate every booking field, rate-limit the
  booking form, and guard against double booking at the database level.

> TODO (confirm): expected booking volume. Assumed small (dozens per week), which
> a single small VPS handles comfortably.
