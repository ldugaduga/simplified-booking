# Coding Standards

> Tuned by `/adopt` to the real stack: Laravel 12 + Inertia 2 + Vue 3 (TypeScript)
> + Tailwind 3 + shadcn-vue, SQLite locally and MySQL/Postgres in production.
> These describe what the code already does; follow them for new work.

## PHP and Laravel

- PHP 8.2+ syntax; typed parameters and return types on every method
- Format with Laravel Pint (default Laravel preset); run it before committing
- Follow the starter-kit docblock style: a one-line `/** ... */` summary on
  controller actions and model methods, `@var list<string>` on `$fillable` and
  `$hidden`, `@return` generics on relations
- Models declare casts in a `casts(): array` method, not a `$casts` property
- Use backed enums in `app/Enums/` for fixed value sets (e.g. `AppointmentStatus`)
  and cast model attributes to them
- Put reusable query conditions in model scopes (`scopeActive`, `scopeOverlapping`)
- Model lifecycle defaults (tokens, derived columns) go in `booted()` hooks
- Read configuration through `config()` with a file in `config/`
  (e.g. `config/booking.php`); never call `env()` outside `config/`, because it
  returns null after `config:cache`

## Controllers and Routes

- Controllers live in `app/Http/Controllers/{Area}/` (`Auth`, `Settings`, and
  `Admin` for new admin screens) and return `Inertia\Response` or `RedirectResponse`
- Validate input with Form Requests in `app/Http/Requests/{Area}/` for anything
  beyond a couple of fields; small settings actions may use `$request->validate()`
- After a successful mutation, redirect (`back()` or `to_route()`), never return JSON to Inertia pages
- Name every route and reference it by name (`route('dashboard')`) in PHP and Vue
- Admin routes sit in the `auth` + `verified` group under the `admin` prefix in
  `routes/web.php`; public routes stay outside it
- Rate-limit public write endpoints with the `throttle` middleware

## Data and Time

- Schema changes only through new migrations; never edit a migration that has run in production
- Store all appointment times in UTC; convert to `settings.timezone` for working
  hours and to the visitor's browser timezone for display
- Open slots are computed on request, never stored
- Double booking is prevented by the unique `appointments.slot_lock` column plus an
  overlap check inside a database transaction; keep both when changing booking code
- Cancel by changing `status`, never by deleting appointments
- Code must run on SQLite, MySQL, and Postgres: no driver-specific SQL unless guarded
- Never put visitor names, emails, or `manage_token` in logs or query strings
  (the token belongs only in the private `/manage/{token}` path)

## Vue and TypeScript

- Single-file components with `<script setup lang="ts">`
- Declare props with a TypeScript `interface Props` and `defineProps<Props>()`
- Shared types live in `resources/js/types/`
- Use Inertia's `useForm` for forms and `route()` (Ziggy) for URLs
- Pages: `resources/js/pages/{area}/PascalCase.vue` (lowercase area folder), rendered
  by the same path in `Inertia::render('area/Page')`
- Shared components: `resources/js/components/PascalCase.vue`; layouts in
  `resources/js/layouts/`; composables in `resources/js/composables/useThing.ts`
- Format with Prettier and lint with ESLint (`npm run format`, `npm run lint`)

## Styling

- Tailwind CSS 3 utility classes (config in `tailwind.config.js`); no inline styles
- Use the shadcn-vue components in `resources/js/components/ui/` before building new ones
- Use theme tokens (`bg-background`, `text-muted-foreground`, `border`) so light and
  dark appearance both work
- Icons from `lucide-vue-next`

## Naming

- PHP classes, Vue components, enums: PascalCase
- PHP methods and variables, TS functions: camelCase
- Database tables and columns: snake_case, plural table names
- Route names: dot-separated lowercase (`profile.edit`, `admin.appointments.index`)

## Error Handling

- Let validation exceptions flow back to the form; show them with `InputError`
- Throw exceptions for impossible states; catch only where there is a real
  recovery (e.g. a `UniqueConstraintViolationException` on `slot_lock` becomes a
  "slot just taken" validation error)
- Show user-friendly messages; never expose stack traces (`APP_DEBUG=false` in production)

## Testing

A test runner is configured: PHPUnit 11, run with `php artisan test` (declared in
the Commands section of `AGENTS.md`). **The opt-in switch is one signal: a `test`
command in the Commands section of `AGENTS.md`.** Because it is declared, tests are
a gate for logic-bearing steps. This is the single definition of the switch; the
skills and `ai-interaction.md` only point back here.

- **What to test (the scope rule):** logic where a wrong answer is possible - slot
  calculation, timezone conversion, booking and double-booking rules, validation,
  access rules (guest vs admin), seeders, and model hooks.
- **What not to test:** Vue component rendering and styling. Verify those with a
  screenshot and `npm run build`.
- **The gate:** a build step that adds in-scope logic must ship a passing test in
  the same reviewable diff. `php artisan test` must be green before the step is
  approved, before any checkpoint commit, and before `/complete` merges.
- **When it's named:** the `/feature` spec's Testing section predicts the coverage,
  `/implement` writes the test with the step, and if a step surfaces logic the spec
  didn't foresee, add a focused test then.
- An empty suite should fail, not pass, so "no tests ran" never looks like "passed".
- Tests are class-based PHPUnit tests (not Pest): HTTP and database behavior in
  `tests/Feature/`, pure logic in `tests/Unit/`, named `ThingTest.php` with
  `test_snake_case_description()` methods and `RefreshDatabase` where the database is used
- Tests run against in-memory SQLite (`phpunit.xml`); feature tests that render
  Inertia pages need built assets (`npm run build`) first
- Use model factories for test data and `$this->travelTo()` for time-dependent logic

## Browser Verification

For UI and integration behavior, prefer real browser evidence over reading the
code and assuming it works.

- Browser automation is separately opt-in through `/tests browser`. That setup
  reuses a compatible runner or prefers Playwright for supported projects, then
  documents the exact command as `Browser tests` in `AGENTS.md`.
- When `Browser tests` is declared, add focused coverage for stable behavioral
  done-whens when it is proportionate, and run the documented command during
  `/check`. Do not assume it proves visual fidelity, real authenticated-profile
  behavior, browser chrome, or another claim the test does not observe.
- If no Browser tests command is declared, do not add a runner silently in the
  middle of an unrelated feature. Use the available dev server, browser
  screenshots, build output, API output, or manual evidence instead.
- Browser tests are not part of the default Verify command or CI unless the user
  separately chooses that slower gate.
- Browser evidence is especially important for flows that click, type, submit,
  navigate, download files, render complex layouts, or depend on client-side
  state.

## Code Quality

- No commented-out code unless specified
- No unused imports or variables
- Keep functions under 50 lines when possible

## Comments

Write code that explains itself; comment only what the code cannot say.
Over-commenting is a common AI tell, so resist it.

- Comment the **why**, not the **what**. Delete any comment that restates the code.
- No banner/header blocks, section dividers, or step-by-step narration of obvious
  code. A file does not need a comment announcing each region.
- A comment earns its place only when it captures something the code can't: a
  non-obvious decision, a gotcha or workaround, why a value is what it is, or a
  link to a spec or issue.
- Prefer self-documenting names and small functions over explanatory comments.
- Keep doc comments minimal: a one-line purpose on an exported type or function is
  plenty; don't write JSDoc that just repeats the signature.
- When in doubt, leave the comment out.

## Writing

- No em dashes (U+2014) in generated content: docs, comments, commit messages,
  READMEs, specs. They read as AI-generated.
- Use a hyphen for `term - description` separators; rephrase prose with commas,
  parentheses, or a colon. Avoid en dashes and the ellipsis character too.
