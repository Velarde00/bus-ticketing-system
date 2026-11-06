# BusTicket

Bus ticketing and reservation system built with PHP and MySQL for XAMPP.

## Setup

1. Start Apache and MySQL in XAMPP.
2. Import `database/bus_ticketing_db.sql` in phpMyAdmin to create the database and tables.
3. Open the project in your browser and sign in as the initial super administrator:
   - Email: `admin@busticket.ph`
   - Password: `Admin@123`
4. Change the initial administrator password before using the system with real passenger data.
5. Add buses, drivers, routes, and trips from the Admin menu. Passengers can then register and book trips.

For an existing installation, run `database/migrate_super_admin.sql`, `database/migrate_bus_type_options.sql`, `database/migrate_paymongo_online_payment.sql`, and `database/migrate_cancelled_payment_status.sql` in phpMyAdmin. These update the administrator role, bus type field, and payment schema without dropping operational data.

### Online payments

Online checkout uses PayMongo-hosted checkout, which can offer QR Ph, GCash, Maya, and cards when enabled for your PayMongo account. Set the following environment variables for PHP, then restart Apache:

- `PAYMONGO_SECRET_KEY`: a PayMongo test key (`sk_test_...`) for development; use a live key only after production setup.
- `PAYMONGO_WEBHOOK_SECRET`: the signing secret for the PayMongo webhook configured for this environment.
- `APP_PUBLIC_URL`: the absolute public URL of this app (for example, `https://example.com/bus-ticketing-system`). Plain HTTP is accepted only for localhost development.

Never put PayMongo secret keys or webhook secrets in source control or browser code. Configure PayMongo to send `checkout_session.payment.paid` events to `https://your-public-host/bus-ticketing-system/actions/paymongo_webhook.php`. The signed webhook marks matching online payment records paid automatically; a browser redirect alone never proves payment. Administrators still confirm the reservation before tickets are issued. Cash, card, and e-wallet selections are paid in person and are not marked paid in the system; administrators can confirm those reservations while the fare remains due in person. Passengers can cancel bookings only at least 2 days before departure; cancelling an unpaid reservation marks its payment cancelled. Paid reservations cannot be cancelled, and refunds are not supported. Each issued ticket includes a QR code with its ticket number, booking reference, and payment method.

The database import creates no sample buses, drivers, routes, trips, passengers, reservations, payments, or tickets. It does create one administrator account so the admin pages are accessible.

**Warning:** `database/bus_ticketing_db.sql` begins by dropping and recreating `bus_ticketing_db`. Importing it again deletes all data currently in that database.

## Admin workflows

- Add, edit, and remove buses, drivers, routes, and trips. Records already used by other records cannot be deleted; mark them inactive instead.
- Manage passenger account access.
- Review reservations, manage offline payment records, and confirm online-paid or offline-payable reservations. PayMongo webhooks update online payment statuses automatically. Confirmation issues one ticket for each booked seat. Passengers can cancel only at least 2 days before departure; cancellation releases seats and automatically marks unpaid payments cancelled. Paid reservations cannot be cancelled; refunds are not supported.
- Run sales summaries for a selected date range. Only paid payments count toward sales.
- Change your password under Account settings.
- Super admins can create admins and disable or re-enable admin accounts. Super-admin accounts are protected from this screen.