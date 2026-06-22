# Resource Reserve — server setup

This folder holds the MySQL schema and the PHP API that the Android app talks to.

## 1. Create the database

Run the schema with the MySQL client ( it creates the `resource_reserve`
database, all nine tables, and some seed data ):

```bash
mysql -u root -p < database/schema.sql
```

Then create a database user that the API will use and grant it access:

```sql
CREATE USER 'resource_reserve_user'@'localhost' IDENTIFIED BY 'change_this_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON resource_reserve.* TO 'resource_reserve_user'@'localhost';
FLUSH PRIVILEGES;
```

### Tables

| Table         | Purpose                                                                 |
| ------------- | ----------------------------------------------------------------------- |
| `user`        | Accounts. Email must be verified before sign in. Holds the access token. |
| `resource`    | Bookable resources with a maximum reservable period ( day or hour ).     |
| `reservation` | Bookings with a begin / end datetime, a description and a review status. |
| `setting`     | Key / value configuration, e.g. the reservation limit per user.          |
| `administrator_resource` | Which resources each administrator may approve or reject ( and so who is an administrator at all ). |
| `message`     | Per-resource conversation messages between users and the resource's administrators. |
| `notification`| Queue of notifications ( new reservation / new message ) the app polls and shows. |
| `log`         | Append only record of events ( registration, login, reservations, reviews ). |
| `rate_limit`  | Sliding-window counters that throttle sensitive endpoints ( see Abuse prevention ). |

## 2. Deploy the API

Copy the `api/` folder so it is served by PHP ( for example
`http://your-host/resource_reserve/api` ). PHP 8.0 or newer with the PDO MySQL
extension is required.

Open `api/config.php` and adjust:

- `DATABASE_HOST`, `DATABASE_NAME`, `DATABASE_USER`, `DATABASE_PASSWORD`
- `API_PUBLIC_BASE_URL` — the public address of the `api/` folder. It is used
  to build the email verification link, so it must be reachable from the user's
  mail client.

### Endpoints

| Method | Path                      | Auth   | Purpose                                   |
| ------ | ------------------------- | ------ | ----------------------------------------- |
| GET    | `/ping.php`               | no     | Health check used by the app's **Test connection** button: confirms PHP runs this API and whether its database is reachable |
| GET/POST | `/delete_account.php`   | no     | Web page where a user deletes their account and data ( see section 7 ) |
| POST   | `/register.php`           | no     | Create an account, send verification mail |
| GET    | `/verify_email.php?token=`| no     | Open from email to verify the account     |
| POST   | `/login.php`              | no     | Sign in, returns an access token          |
| GET    | `/resources.php`          | bearer | List active resources                     |
| GET    | `/reservations.php`       | bearer | List reservations ( filters below )       |
| POST   | `/create_reservation.php` | bearer | Create a pending reservation              |
| POST   | `/update_reservation.php` | bearer | Change an own, still pending reservation  |
| GET    | `/admin_reservations.php` | admin  | List every reservation awaiting review    |
| POST   | `/admin_review_reservation.php` | admin | Confirm or reject a pending reservation |
| GET    | `/messages.php`           | bearer | List messages the user takes part in      |
| POST   | `/send_message.php`       | bearer | Post a message about a resource            |
| GET    | `/notifications.php`      | bearer | Fetch undelivered notifications, mark them delivered |
| GET    | `/inbox.php`              | bearer | List the full notification history ( read only )   |
| GET    | `/unread_count.php`       | bearer | Count of unread notifications, for the menu badge  |
| POST   | `/mark_inbox_read.php`    | bearer | Mark all of the user's notifications as read       |

There is no administrator flag on the account. A user is an administrator only
when they have at least one row in the `administrator_resource` table, and they
may review just the resources listed there ( see section 4 ). An administrator
endpoint returns 403 for a user with no assignments, and
`admin_review_reservation.php` returns 403 when the reservation's resource is not
one of theirs. `admin_review_reservation.php` expects a body of
`{ "reservation_id": <id>, "decision": "confirmed" | "rejected" }`.

Every notable event ( registration, login, reservation create / update, and
administrator approval / rejection ) is written to the `log` table.

`send_message.php` expects a body of `{ "resource_id": <id>, "body": "..." }`.
A message belongs to a resource; everyone who administers that resource and
everyone who has already posted on it ( other than the sender ) can see it and
receives a notification. `messages.php` returns each message with an `is_sent`
flag so the app can mark it as sent or received; it also accepts an optional
`resource_id` query parameter that narrows the result to one resource ( the
Messages screen uses it to show the conversation for the chosen resource ).

Authenticated requests send the token in the header:
`Authorization: Bearer <access_token>`.

`reservations.php` accepts the query parameters `resource_id` ( optional ),
`only_own` ( `0` / `1` ) and `month_range` ( `1`, `2` or `3` months ahead ).

Reservations are stored to the minute: `create_reservation.php` and
`update_reservation.php` expect `begin_datetime` and `end_datetime` in the
`year-month-day hour:minute` format. The booked length must fit inside the
resource's maximum period, which is read in its own unit ( for example 8 hours
for an hourly resource, or 3 days for a daily one ).

Both endpoints also reject an overlapping timeframe: a resource can hold only
one active ( `pending` or `confirmed` ) reservation at any given moment. The
check ( `assert_no_overlapping_reservation` in `reservation_helpers.php` )
ignores `rejected` reservations and ones that have already ended, and, when
editing, ignores the reservation being changed. Two reservations that merely
touch ( one ends exactly when the next begins ) are allowed. A clash returns
HTTP `409`; the app shows the message and also warns about a visible clash
before sending the request.

#### Reservation window rules

`validate_reservation_timeframe` in `reservation_helpers.php` enforces, in
addition to the maximum period:

- **Lead time** – a reservation must begin at least so many minutes from now,
  from the `reservation_minimum_lead_minutes` setting ( seeded at `60` ).
- **Horizon** – a reservation may not begin more than so many months from now,
  from the `reservation_maximum_horizon_months` setting ( seeded at `3` ).

Both rules live in the `setting` table ( read through
`read_reservation_lead_minutes` / `read_reservation_horizon_months` ), so they
can be changed without touching the code. `resources.php` returns the current
values ( as `minimum_lead_minutes` and `maximum_horizon_months` ) alongside the
resource list, so the Android app reads the rules from the server instead of
hardcoding them: it constrains the date picker and shows the same messages,
while the server stays the final authority.

The per-user limit ( `maximum_active_reservations_per_user` ) and the overlap
check both count only active reservations: `count_active_reservations` and the
overlap query select `status IN ( 'pending', 'confirmed' )` with
`end_datetime >= NOW()`, so expired and rejected reservations never count
against a user or block a timeframe.

#### Resource availability ( active hours and working days )

Each resource has an `availability_mode`:

- **`always`** – bookable 24 hours a day, 7 days a week. The schedule columns are
  ignored.
- **`scheduled`** – bookable only on the `working_days` ( a `SET` of weekday
  names ) and, on each day it touches, only between `active_start_time` and
  `active_end_time`. An `active_end_time` of `00:00:00` means the end of the day,
  so `00:00:00`–`00:00:00` is "all day".

`validate_resource_schedule` walks the reservation one calendar day at a time, so
the rule works for both single day bookings ( for example the hourly microscope,
weekdays `08:00`–`18:00` ) and multi day bookings ( for example a car that is
free all day but only on weekdays ). A booking that would run through hours
outside the active window ( such as overnight on an `08:00`–`18:00` resource ) is
rejected. `resources.php` returns these fields so the Android app shows the same
messages before sending the request.

### A note on email

`register.php` calls PHP's `mail()` function. On a development machine without a
mail transport no message is delivered, so during development the response also
returns the `verification_link` and the app shows it after registering. This is
controlled by the `DEVELOPMENT_MODE` constant in `config.php`: set it to `false`
in production so the link is delivered only by email and is never exposed in the
API response.

### Abuse prevention ( rate limiting )

Sensitive endpoints are throttled with a sliding window backed by the
`rate_limit` table, so the API resists brute-force sign in, automated mass
registration, email flooding and notification spam. Current limits per window:

| Action                    | Limit                          |
| ------------------------- | ------------------------------ |
| Sign in                   | 10 per 15 min, per IP and per email |
| Registration              | 5 per hour per IP, 3 per hour per email |
| Send message              | 20 per 5 min, per user         |
| Create reservation        | 30 per 10 min, per user        |
| Health check ( ping )     | 30 per minute, per IP          |
| Account deletion request  | 5 per 15 min, per IP and per email |

Tune the numbers in the matching `enforce_rate_limit( ... )` calls. The client
address comes from `REMOTE_ADDR` only; `X-Forwarded-For` is **not** trusted
because it is attacker-controlled. If the API runs behind a reverse proxy,
resolve the real client address in the proxy. The limiter fails open: if the
`rate_limit` table is unavailable the request is allowed rather than the whole
API going down.

Other hardening: PHP error display is turned off ( errors go to the server log,
not to clients ), responses send `X-Content-Type-Options: nosniff`, sign in and
account deletion run a constant-time password check so a missing account cannot
be told apart by timing, and request fields ( email, password, message and
description lengths ) are bounded.

## 3. Point the Android app at the API

The server address is no longer compiled into the app. It is entered on the
**login screen** in the **Server address** field, for example
`https://your-host/resource_reserve/api` ( the public address of the `api/`
folder, with no trailing slash ). The address is saved per device and reused on
the next sign in, so it only has to be typed once.

- **HTTPS only.** The login screen rejects any address that is not `https://`
  ( the entered URL is validated before it is saved ), and cleartext HTTP is
  disabled by default on the Android versions the app supports. Serve the API
  over TLS, including for local testing ( a self-signed certificate trusted by
  the device, or a tunnel such as `https://…` from your reverse proxy ).
- **Test connection.** The login screen has a **Test connection** button. It
  calls `GET /ping.php` and only reports success when that endpoint returns this
  service's identifier, so it verifies the PHP API is actually deployed there —
  not merely that the host answers. It also reports when the API is reachable but
  its database is not.
- **Switching servers.** Entering a different address and signing in clears the
  saved session, because the access token issued by the previous server is not
  valid on the new one.

## 4. Making an account an administrator

There is **no** administrator flag on the account. A user becomes an
administrator simply by being assigned one or more resources in the
`administrator_resource` table, and they may review only those resources. A user
with no rows there is a regular user.

Assign the resources after the account has registered and verified:

```sql
-- Assign the specific resources this administrator may review. Adding at least
-- one row is what turns the account into an administrator.
INSERT INTO administrator_resource ( user_id, resource_id )
SELECT user.user_id, resource.resource_id
FROM user, resource
WHERE user.email = 'admin@example.com'
	AND resource.resource_name IN ( 'Meeting Room A', 'Company Car' );
```

To narrow what an administrator can review, delete the matching row from
`administrator_resource`; removing the last row makes them a regular user again.
The endpoints enforce these assignments on every call: `admin_reservations.php`
only returns pending reservations for assigned resources, and
`admin_review_reservation.php` refuses a decision on any other resource with a
403 error.

The app decides whether to show the **Review reservations** screen from the sign
in response, so the administrator should sign in again after the change.

## 5. Language

The app ships in English and Romanian. The language is chosen inside the app
from the **Settings** screen ( the menu in the top right of the reservation
list ). The choice is applied immediately and remembered across restarts.

## 6. Messages and notifications

The app has a **Messages** screen ( in the reservation list menu ). A user picks
a resource and writes a message; the resource's administrators and the other
participants on that resource then see it. Each row is marked with an icon for
sent or received.

### How notifications work

There is no Firebase or external push service. Notifications are a small queue
that the app polls:

1. The server adds a row to the `notification` table whenever something happens
   that a user should hear about:
   - a **new reservation** → one notification for every administrator of that
     resource ( except the person who booked it );
   - a **new message** → one notification for every administrator and
     participant of that resource ( except the sender ).
2. The Android app asks `GET /notifications.php` for the rows that are not yet
   delivered, shows each one as a system notification, and the endpoint marks
   exactly those rows as delivered so they appear only once.
3. The app polls in two situations:
   - **While open** — every time the reservation list screen is resumed.
   - **In the background** — a `WorkManager` job runs about every 15 minutes
     ( the shortest interval Android allows ), so an administrator is still
     notified when the app is closed.

The app also keeps an **Inbox** screen ( in the reservation list menu ) that
lists the full notification history through `GET /inbox.php`, including the ones
that were already shown as system notifications. The inbox is read only and does
not change the delivered flag.

The reservation list menu shows an unread count next to **Inbox**, read from
`GET /unread_count.php` ( the `notification.is_read` flag ). Opening the inbox
calls `POST /mark_inbox_read.php`, which marks the user's notifications read and
clears the badge. Tapping a system notification opens the relevant screen: a
message opens **Messages**, a reservation opens the administrator review screen.

### Setup notes

- No server configuration is needed beyond importing the schema ( which creates
  the `notification` and `message` tables ).
- On the device, Android 13+ asks for the **notifications** permission the first
  time the reservation list opens. If the user declines, notifications are
  simply not shown; everything else keeps working.
- Background delivery is near real time only down to the ~15 minute polling
  interval. For instant push you would add Firebase Cloud Messaging, which is
  out of scope here; the polling design keeps the project self-hosted with no
  third-party account.

## 7. Account and data deletion

`delete_account.php` is a self-contained web page ( no app required ) that lets a
user delete their account and associated data. Google Play requires such a URL
for any app that lets people create an account.

- Opening it ( `GET` ) shows a form asking for the account email, password, and a
  confirmation checkbox.
- Submitting it ( `POST` ) verifies the email and password, then deletes the
  account. The foreign keys cascade the deletion to the user's reservations,
  messages, notifications and administrator assignments, and the user's own
  `log` rows are removed as well. A single anonymous `account_deleted` log row is
  written so an operator can see the action happened, with nothing that
  identifies the person.

Requiring the password means only the account owner can trigger the deletion,
which also protects the form against cross-site request forgery. The page is
rate limited ( see "Abuse prevention" above ), reports failures with one generic
message, and sends anti-clickjacking and content-type headers. Serve it over
HTTPS like the rest of the API.

The public URL — for example
`https://your-host/resource_reserve/api/delete_account.php` — is what you enter
in Play Console under **App content → Data deletion**, and it is linked from the
app's privacy policy.

Note one residual: a notification sent to *other* users may mention this user's
email in its text ( for example "user@example.com reserved Meeting Room A" ).
Those rows belong to the recipients and are not removed by this deletion. If you
need to scrub them too, delete from `notification` where the `body` contains the
address before deleting the account.