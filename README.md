# BusTicket

Bus ticketing and reservation system built with PHP and MySQL for XAMPP.

## Setup

1. Start Apache and MySQL in XAMPP.
2. Import `database/bus_ticketing_db.sql` in phpMyAdmin to create the database and tables.
3. Open the project in your browser to sign in to the administrator portal.
4. Set up an administrator password before using the system.
5. Add buses, drivers, routes, and trips from the Admin menu. Passengers can then register and book trips.

For an existing installation, run `database/migrate_super_admin.sql`, `database/migrate_bus_type_options.sql`, `database/migrate_cancelled_payment_status.sql`, `database/migrate_online_only_trip_cancellations.sql`, `database/migrate_online_payment_options.sql`, `database/migrate_manual_transfers.sql`, `database/migrate_payment_qr_images.sql`, `database/migrate_online_refunds.sql`, and `database/migrate_notifications.sql` in phpMyAdmin, in that order. These update the administrator role, bus type field, payment/cancellation, QR, and notification schema without dropping operational data.

### Manual transfers

Passengers choose GCash, Maya, MariBank, or online bank transfer during seat reservation. Configure the receiving account details and upload the matching QR from each payment app under Admin > Account settings. Passengers can scan the QR instead of typing the account number, then submit the transaction reference; an administrator verifies the incoming payment and confirms the reservation to issue tickets. Payment status is not automatically verified without an online payment provider. Administrators can inspect trip seat maps and available-seat counts. Passengers can cancel unpaid bookings at least 2 days before departure. If an operator cancels a trip, affected passengers can request a refund only for paid reservations made at least 2 days before departure, provide their online refund destination, or choose to rebook another available trip. The administrator sends the refund using online banking or a wallet outside the application, then records the transfer reference in Manage Refunds. The app tracks the refund but does not initiate outgoing transfers because no payout provider is connected. Each issued ticket includes a QR code with its ticket number, booking reference, and payment method.

## Admin workflows

- Add, edit, and remove buses, drivers, routes, and trips. Records already used by other records cannot be deleted; mark them inactive instead.
- Manage passenger account access.
- Review transfer references in Manage payments and verify receipts before confirming bookings and issuing tickets. Configure receiving accounts under Account settings. Trip seat maps show reserved and available seats. Cancelling a trip cancels its reservations and releases seats. Eligible refund requests appear in Manage Refunds with the passenger's payout destination; send transfers externally and record the outgoing reference to complete them.
- Run sales summaries for a selected date range. Only paid payments count toward sales.
- Change your password under Account settings.
- Super admins can create admins and disable or re-enable admin accounts. Super-admin accounts are protected from this screen.
