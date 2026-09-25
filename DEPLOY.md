# Deploying Simplified Booking

A checklist for a first deploy to a Linux VPS (Ubuntu LTS, Nginx, PHP 8.4-FPM,
MySQL 8 or Postgres 16, Let's Encrypt). This is provider-agnostic; a managed
panel (Forge, Ploi, Coolify) can automate most of the steps below.

## 1. Server prerequisites

- Ubuntu LTS with Nginx, PHP 8.4-FPM (with `pdo_mysql` or `pdo_pgsql`, `mbstring`,
  `xml`, `curl`, `zip`, `sqlite3` extensions), and either MySQL 8 or Postgres 16.
- Composer 2 and Node.js 20+ (Node is only needed at build time, not at runtime).
- A TLS certificate via Let's Encrypt (`certbot`), and Nginx configured to
  redirect HTTP to HTTPS.
- Supervisor, for running the queue worker (see step 4).

## 2. Environment

Copy [`.env.production.example`](.env.production.example) to `.env` on the
server and fill in every value: `APP_KEY` (generate with
`php artisan key:generate --show`), `APP_URL`, the `DB_*` credentials for the
database you provisioned, `MAIL_*` for your mail provider, and
`ADMIN_NAME`/`ADMIN_EMAIL`/`ADMIN_PASSWORD`/`BOOKING_TIMEZONE` for the seeded
admin account. Confirm `APP_ENV=production`, `APP_DEBUG=false`, and
`SESSION_SECURE_COOKIE=true` are set — these are the production defaults in the
template and must not be reverted.

## 3. Build

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

## 4. Release

```bash
php artisan migrate --force
php artisan db:seed --force   # first deploy only, creates the admin account
php artisan optimize
```

## 5. Queue worker

The app queues confirmation emails (`App\Mail\AppointmentMailable` and its
subclasses), so a worker must run continuously. Add a Supervisor program, for
example `/etc/supervisor/conf.d/simplified-booking-worker.conf`:

```ini
[program:simplified-booking-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/app/artisan queue:work --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/app/storage/logs/worker.log
```

Then `supervisorctl reread && supervisorctl update && supervisorctl start simplified-booking-worker:*`.

## 6. Cron

Add one line to the deploy user's crontab (`crontab -e`) so Laravel's scheduler
runs every minute:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Nothing is scheduled yet, so this is a no-op today — it's ready for later
features (for example reminder emails) that register scheduled jobs.

## 7. Smoke test

- Visit `https://your-domain.example/up` and confirm it returns a 200 (this is
  Laravel's built-in health check route).
- Log in at `/login` with the seeded admin account and confirm `/admin` loads.
- Make a real booking on `/` and confirm the confirmation email arrives with
  the `.ics` attachment, proving the queue worker is running.
