# Fix: Show the business name and a brand-initials mark, replacing the leftover starter-kit logo

**Type:** Fix
**Status:** verified
**Branch:** fix/admin-sidebar-shows-business-name

## The problem

1. `resources/js/components/AppLogo.vue` hard-codes the text "Laravel Starter Kit" next to a logo icon, a leftover from the starter kit this project was scaffolded from. It's used in the admin sidebar (`AppSidebar.vue`) and the admin mobile header (`AppHeader.vue`), so every admin page shows it. It was never wired to anything, not even `config('app.name')`.
2. The same starter-kit logo mark (`AppLogoIcon`, an SVG glyph) appears with no business identity at all on every auth page (login, forgot password, reset password, confirm password, verify email - all via the shared `AuthSimpleLayout.vue`), and the business name is nowhere on those pages either.

## The fix

- Share the business name (the same value the public booking page and emails already use: `Setting::current()->business_name ?: config('app.name')`) as a new global Inertia prop, `businessName`, from `App\Http\Middleware\HandleInertiaRequests::share()`, alongside the existing `name` prop. Add `businessName: string` to the `SharedData` type in `resources/js/types/index.ts`.
- Add `resources/js/components/BrandMark.vue`: a small circular badge showing the business name's initials, reusing the existing `getInitials()` composable (`resources/js/composables/useInitials.ts`, already used for user avatars, so "Northgate Consulting" becomes "NC" the same way a user's name does). Props: `name: string`, optional `class`.
- Replace the logo mark with `BrandMark` (initials in a circle) in the three places it currently appears as the old starter-kit icon:
  - `AppLogo.vue` (used in the admin sidebar and desktop header) - keep the existing business-name text label next to it
  - `AppHeader.vue`'s mobile navigation sheet header (currently a bare `AppLogoIcon`)
  - `AuthSimpleLayout.vue` (the shared layout for login and the other auth pages) - also add the business name as visible text next to the mark, replacing the current icon-only link whose only accessible label was the page's own title
- Must not change: the existing `name` shared prop (still `config('app.name')`, the app's own identity, used for browser tab titles), the public booking page/emails (already show the business name independently), and the two unused starter-kit auth layouts (`AuthSplitLayout.vue`, `AuthCardLayout.vue`) that aren't wired to any route.

## Build steps

- [x] **1. Share the business name and add `BrandMark`.** `HandleInertiaRequests`, the `SharedData` type, and the new component. Add a feature test asserting the shared prop and its `config('app.name')` fallback.
  - Done when: `php artisan test` passes; the prop is present on every page's Inertia response.
- [x] **2. Use `BrandMark` and the business name everywhere the old logo appeared.** Update `AppLogo.vue`, `AppHeader.vue`'s mobile sheet, and `AuthSimpleLayout.vue`.
  - Done when: `npm run build`, ESLint, and Prettier pass, and a screenshot (browser pane) shows: the admin sidebar with a circular initials badge and the business name; the login page with the same badge and the business name visible (not just accessible-only); both with no business name set (falls back to "Simplified Booking" → "S") and with one set (for example "Northgate Consulting" → "NC").

## Verify

- With no business name set, log in and confirm the sidebar shows a circle with "S" and the text "Simplified Booking"; open `/login` and confirm the same badge and name appear there too.
- Set a business name in `/settings/branding` (for example "Northgate Consulting"), reload `/admin` and `/login`, and confirm both now show a circle with "NC" and the new name.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":3775,"specSha256":"4365f0bd6555626b53c79a90f94a04acad864e32a71c1653a7532eade316a904","branch":"refs/heads/fix/admin-sidebar-shows-business-name","head":"52d491296d794cea1a84f49db1c845fb0d7c00aa","baseRef":"refs/heads/main","baseCommit":"a86c4f9a3233f2cf6205d738cc83093c38a43639","sourceTree":"4e12aa44b46592f3cf570543a70d6a62800eb646","absentOptional":[]} -->
