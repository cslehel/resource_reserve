# Resource Reserve — server setup

This folder holds the MySQL schema and the PHP API that the Android app talks to.

## 1. Create the database

resource_reserve, YMRocothpet034^*

Run the schema with the MySQL client ( it creates the `resource_reserve`
database, all four tables, and some seed data ):

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
flag so the app can mark it as sent or received.

Authenticated requests send the token in the header:
`Authorization: Bearer <access_token>`.

`reservations.php` accepts the query parameters `resource_id` ( optional ),
`only_own` ( `0` / `1` ) and `month_range` ( `1`, `2` or `3` months ahead ).

Reservations are stored to the minute: `create_reservation.php` and
`update_reservation.php` expect `begin_datetime` and `end_datetime` in the
`year-month-day hour:minute` format. The booked length must fit inside the
resource's maximum period, which is read in its own unit ( for example 8 hours
for an hourly resource, or 3 days for a daily one ).

### A note on email

`register.php` calls PHP's `mail()` function. On a development machine without a
mail transport no message is delivered, so the response also returns the
`verification_link`. The app shows that link after registering so the flow can
be completed during testing. Remove that field from the response before going to
production.

## 3. Point the Android app at the API

The server address is no longer compiled into the app. It is entered on the
**login screen** in the **Server address** field, for example
`https://your-host/resource_reserve/api` ( the public address of the `api/`
folder, with no trailing slash ). The address is saved per device and reused on
the next sign in, so it only has to be typed once.

- **HTTPS only.** The app rejects any address that is not `https://`, and the
  Android manifest no longer allows cleartext traffic. Serve the API over TLS,
  including for local testing ( a self-signed certificate trusted by the device,
  or a tunnel such as `https://…` from your reverse proxy ).
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