# Build Plan

Seeded by `/adopt`. Checked items already exist in the code; unchecked items
follow the milestone order in `PLAN.md`. Run `/feature` to spec the next
unchecked item, or `/feature 3` to pick one. Do not renumber completed features;
their archived specs refer to those IDs.

## MVP

- [x] 1. **Admin-only authentication** - login, password reset, profile and password settings; public registration and self-delete removed; `/admin` protected; login rate-limited
- [x] 2. **Booking data model** - settings, availability rules, blocked dates, and appointments tables with models, seeder (admin account and Mon-Fri 9-17 defaults), and the `slot_lock` double-booking guard
- [x] 3. **Availability management** - admin page for weekly hours, blocked dates, and booking rules, plus a tested `SlotService` that computes open slots across timezones
- [ ] 4. **Public booking flow** - month calendar, open times in the visitor's timezone, booking form, confirmation page; transactional slot check, rate limit, and honeypot field
- [ ] 5. **Admin appointments** - dashboard list with date and status filters; admin create, reschedule, and cancel
- [ ] 6. **Confirmation email** - queued email to the visitor with an `.ics` invite, sent on booking, reschedule, and cancel
- [ ] 7. **Production readiness** - production `.env` template, security settings (HTTPS, secure cookies, debug off), and a VPS deploy checklist with queue worker and cron

## Phase 2 - quality of life

- [ ] 8. **Visitor self-service** - private `/manage/{token}` link to cancel or reschedule
- [ ] 9. **Reminder emails** - scheduled reminders 24 hours and 1 hour before
- [ ] 10. **Admin new-booking alert** - email the admin when someone books
- [ ] 11. **Multiple meeting types** - each with its own name, duration, and booking link
- [ ] 12. **Custom booking questions** - admin-defined extra form fields
- [ ] 13. **Dashboard calendar view** - week and month views of appointments

## Phase 3 - growth (not yet committed)

- [ ] 14. **Calendar sync** - Google and Outlook busy times block slots; bookings added to the admin's calendar
- [ ] 15. **Automatic video links** - Google Meet or Zoom link per appointment
- [ ] 16. **Paid bookings** - Stripe Checkout before confirming
- [ ] 17. **Admin two-factor login** - TOTP
- [ ] 18. **Embeddable widget** - booking page embeddable on other sites
- [ ] 19. **Booking analytics** - volume, no-shows, busiest hours
- [ ] 20. **Multiple staff** - per-person availability, optional round-robin
