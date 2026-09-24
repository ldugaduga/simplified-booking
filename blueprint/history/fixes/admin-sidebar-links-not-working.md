# Fix: Admin sidebar links not working

**Type:** Fix
**Status:** verified
**Branch:** fix/admin-sidebar-links-not-working

## The problem

Clicking **Appointments** or **Availability** in the admin sidebar does nothing, and neither item is shown as the current page.

`resources/js/components/NavMain.vue` declares its own local `NavItem` interface with a `url` field, then renders `<Link :href="item.url">` and `:is-active="item.url === page.url"`. The caller, `resources/js/components/AppSidebar.vue`, passes items typed with the shared `NavItem` from `resources/js/types/index.ts`, which uses `href`. So `item.url` is always `undefined`:
- every sidebar link has no destination
- the active check never matches

This is a mismatch inherited from the starter kit. It went unnoticed because pages were opened by URL.

## The fix

- In `NavMain.vue`, drop the local interface and use the shared `NavItem` type (`import { type NavItem } from '@/types'`), matching `NavFooter.vue`. Render `<Link :href="item.href">`.
- Mark an item active when the current URL's path (`page.url` without the query string) equals `item.href`, so `/admin?tab=past` still highlights **Appointments**.
- Render the icon only when present (`icon` is optional in the shared type).
- Must not break: the header nav (`AppHeader.vue`, which already uses `href`), the logo link, or collapsed-sidebar tooltips.
- No new dependency or abstraction.

## Build steps

- [x] **1. Use `href` in NavMain.** Apply the fix above.
  - Done when:
    - `npm run build`, ESLint and Prettier pass, and `php artisan test` stays green
    - in the logged-in admin, clicking **Appointments** goes to `/admin` and clicking **Availability** goes to `/admin/availability`
    - the current item is highlighted, including on `/admin?tab=past`

## Verify

- Log in, open http://localhost:8000/admin/availability, then click **Appointments** in the sidebar. It navigates to `/admin` and that item is highlighted.
- Click **Availability**. It navigates back, and that item is highlighted.
- Filter the dashboard (for example the **Past** tab). **Appointments** stays highlighted.
- Collapse the sidebar. The icon links still navigate.


<!-- blueprint:completion {"schemaVersion":1,"specBytes":2183,"specSha256":"c67ceb62b3624211ed492132a4363e7ef49c76d8429e73f218b91e965046b17c","branch":"refs/heads/fix/admin-sidebar-links-not-working","head":"d0b15f4e8bcfed7ad481b0b084d16c68aee16c73","baseRef":"refs/heads/main","baseCommit":"d0b15f4e8bcfed7ad481b0b084d16c68aee16c73","sourceTree":"527c1dee78407a6b72bbcfade533ba0082c04a33","absentOptional":[]} -->
