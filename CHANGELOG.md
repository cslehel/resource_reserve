# Changelog

## 2026-06-22

### Reservation rules (PHP + Android)

- **Overlap prevention** — a resource can hold only one active (`pending` /
  `confirmed`) reservation per timeframe. Server-authoritative check in
  `assert_no_overlapping_reservation`; the app also warns immediately and shows
  the message.
- **Past / far-future limits** — a reservation must begin at least **1 hour**
  from now and at most **3 months** ahead. Enforced server-side and pre-checked
  in the app, with a date picker constrained to that window.
- **Expired & rejected excluded** — the per-user limit and the overlap check
  count only active, not-yet-ended reservations.
- **Active hours & working days** — resources are either `always` (24/7) or
  `scheduled` (bookable only on set working days within an active-hours window).
  A per-day validation supports single-day, multi-day, and all-day cases. New
  `resource` columns: `availability_mode`, `active_start_time`,
  `active_end_time`, `working_days`.

### Configuration moved to the database

- The 1-hour lead time and 3-month horizon are now `setting` rows
  (`reservation_minimum_lead_minutes`, `reservation_maximum_horizon_months`),
  returned by `resources.php` so the app reads them from the server instead of
  hardcoding them.
- **Removed hardcoded duplicates in the app** — reservation status values
  (`pending` / `confirmed` / `rejected`) and availability modes (`always` /
  `scheduled`) are now centralized as named constants mirroring the DB enums.

### Messages screen

- The page loads messages **on open** and **reloads when the resource is
  changed** (new optional `resource_id` filter on `messages.php`; spinner-driven
  loading).
- Added a "no resources available" message when there is nothing to message
  about.
- Confirmed new-message notifications already reach the Inbox (no change
  needed).

### Documentation

- Updated `README.md` for all of the above.
- Removed the database upgrade / `ALTER` sections (schema is treated as a fresh
  install).
- Corrected factual inaccuracies: table count (4 → 9), added the `rate_limit`
  table, fixed the HTTPS / manifest claim, and removed the dead `privacy.html`
  reference.

All changes verified: `php -l` clean across the API, and
`:app:compileDebugJavaWithJavac` builds successfully.
