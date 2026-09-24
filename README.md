# Simplified Booking

A small, self-hosted booking app in the style of Calendly. Visitors choose an open time from a public calendar and book it without an account. A single admin logs in to manage availability and appointments.

> **Status:** Milestone 1 of 5 is done (project setup, admin-only login, database schema). The next milestones are in [PLAN.md](PLAN.md).

## Features

**Available now**
- Admin-only login: no public sign-up, and login attempts are rate-limited
- Protected admin area at `/admin`
- Database tables for booking settings, weekly hours, blocked dates and appointments
- Double-booking protection built into the database

**Planned** (details in [PLAN.md](PLAN.md))
- Admin: set working hours and blocked dates; create, reschedule and cancel appointments
- Public: month calendar, then pick a time, then fill in a short form; confirmation email with a calendar invite (`.ics`)
- Later: reminders, visitors managing their own bookings, multiple meeting types, calendar sync, payments

## Tech stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12 (PHP 8.2+) |
| Frontend | Inertia.js + Vue 3 + TypeScript |
| UI | Tailwind CSS + shadcn-vue |
| Database | SQLite (local); MySQL or Postgres (production) |
| Tests | PHPUnit |

## Requirements

- PHP 8.2 or newer, with the `pdo_sqlite` extension (or `pdo_mysql` / `pdo_pgsql` in production)
- Composer 2
- Node.js 20 or newer, with npm

## Getting started

Install dependencies:

```bash
composer install
```

```bash
npm install
```

Create your environment file and app key (skip this if `.env` already exists):

```bash
cp .env.example .env
```

```bash
php artisan key:generate
```

Open `.env` and set the admin account and your timezone:

```
ADMIN_NAME="Your Name"
ADMIN_EMAIL=you@example.com
ADMIN_PASSWORD=choose-a-strong-password
BOOKING_TIMEZONE=Asia/Manila
```

Create the database, tables, admin account and default working hours (Mon-Fri, 9:00-17:00, 30-minute slots):

```bash
touch database/database.sqlite
```

```bash
php artisan migrate --seed
```

Start the app. This runs the web server, queue worker, log viewer and Vite together:

```bash
composer run dev
```

Then open:
- http://localhost:8000: the public booking page
- http://localhost:8000/login: admin login (redirects to `/admin`)

## The database

Locally the whole database is one file, `database/database.sqlite`. Git ignores it.

Open an interactive shell on it:

```bash
php artisan db
```

List the tables and their sizes:

```bash
php artisan db:show
```

Start over with a fresh database. **This deletes all local data** and then re-seeds it:

```bash
php artisan migrate:fresh --seed
```

To use MySQL or Postgres instead, change the `DB_*` values in `.env` and run `php artisan migrate`. No code changes are needed.

## Running tests

```bash
php artisan test
```

Tests use a separate in-memory SQLite database, so your local data is left alone.

Format the code before committing:

```bash
./vendor/bin/pint
```

```bash
npm run format
```

## Project structure

```
app/
  Enums/AppointmentStatus.php   confirmed / cancelled
  Models/
    Appointment.php             sets manage_token and slot_lock automatically
    AvailabilityRule.php        weekly working hours
    BlockedDate.php             days off
    Setting.php                 booking rules (one row), Setting::current()
config/booking.php              admin account and timezone, read from .env
database/
  migrations/                   database schema
  seeders/DatabaseSeeder.php    admin account and default hours
resources/js/pages/
  Welcome.vue                   public booking page
  Dashboard.vue                 admin dashboard
  auth/, settings/              login, password reset, profile
routes/
  web.php                       public and /admin routes
  auth.php                      login and password reset (no registration)
tests/Feature/                  tests for access rules, seeder and appointments
```

## Environment variables

| Variable | Purpose | Default |
|---|---|---|
| `ADMIN_NAME` | Admin display name | `Admin` |
| `ADMIN_EMAIL` | Admin login email | `admin@example.com` |
| `ADMIN_PASSWORD` | Admin password. Required in production; if it's empty locally, the seeder generates one and prints it. | *(empty)* |
| `BOOKING_TIMEZONE` | Timezone your working hours are set in | `UTC` |
| `DB_CONNECTION` | `sqlite`, `mysql` or `pgsql` | `sqlite` |
| `MAIL_MAILER` | How emails are sent; `log` writes them to `storage/logs` instead | `log` |

## Deploying

This app is meant to run on a Linux VPS (for example Hetzner or DigitalOcean) with Nginx, PHP-FPM and MySQL or Postgres. The full checklist is in [PLAN.md → Deployment](PLAN.md#10-deployment-vps). A typical deploy:

```bash
composer install --no-dev --optimize-autoloader && npm ci && npm run build && php artisan migrate --force && php artisan optimize
```
