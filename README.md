# BusTicket

Bus ticketing and reservation system built with PHP and MySQL for XAMPP.

## Setup

1. Start Apache and MySQL in XAMPP.
2. Import `database/bus_ticketing_db.sql` in phpMyAdmin to create the database and tables.
3. Open the project in your browser to sign in to the administrator portal.
4. Set up an administrator password before using the system.
5. Add buses, drivers, routes, and trips from the Admin menu. Passengers can then register and book trips.

For an existing installation, run `database/migrate_super_admin.sql` and `database/migrate_bus_type_options.sql` in phpMyAdmin. These update the administrator role and bus type field without dropping operational data.

## Admin workflows

- Add, edit, and remove buses, drivers, routes, and trips. Records already used by other records cannot be deleted; mark them inactive instead.
- Manage passenger account access.
- Review reservations, update payment records, and confirm paid reservations. Confirmation issues one ticket for each booked seat. Cancelling a reservation releases its seats; refunds are recorded after cancellation.
- Run sales summaries for a selected date range. Only paid payments count toward sales.
- Change your password under Account settings.
- Super admins can create admins and disable or re-enable admin accounts. Super-admin accounts are protected from this screen.
