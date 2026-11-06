<?php
/**
 * actions/create_booking.php
 * Saves a reservation. Everything happens inside ONE database transaction:
 * the booking, its seats, and the payment record are saved together or not at all.
 * The server re-checks everything. It never trusts prices or seat numbers from the browser.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_role('PASSENGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$uid    = (int)$_SESSION['user_id'];
$tripId = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT);
$back   = $tripId ? 'passenger/seat_selection.php?trip_id=' . $tripId : 'index.php';

function fail(string $message, string $back): void
{
    set_flash('danger', $message);
    redirect($back);
}

if (!csrf_verify()) {
    fail('Your session expired. Please try again.', $back);
}
if (!$tripId) {
    fail('Invalid trip.', 'index.php');
}

// Payment method must be one of the allowed values
$method = $_POST['payment_method'] ?? '';
if (!in_array($method, ['CASH', 'E_WALLET', 'CARD', 'ONLINE'], true)) {
    fail('Choose a payment method.', $back);
}
if ($method === 'ONLINE' && !paymongo_is_configured()) {
    fail('Online checkout is not configured yet. Choose another payment method or contact the administrator.', $back);
}

// Clean the seat list: whole numbers only, no duplicates
$rawSeats = $_POST['seats'] ?? [];
if (!is_array($rawSeats)) {
    $rawSeats = [];
}
$seats = [];
foreach ($rawSeats as $s) {
    $n = filter_var($s, FILTER_VALIDATE_INT);
    if ($n === false || $n < 1) {
        fail('Invalid seat selection.', $back);
    }
    $seats[$n] = $n;
}
$seats = array_values($seats);
sort($seats);

if (count($seats) < 1) {
    fail('Select at least one seat.', $back);
}
try {
    $pdo->beginTransaction();

    // Lock this trip's row so two people booking at once are handled one after the other
    $stmt = $pdo->prepare(
        "SELECT t.fare, t.status, t.departure_time, b.total_seats, b.status AS bus_status
         FROM trips t JOIN buses b ON b.bus_id = t.bus_id
         WHERE t.trip_id = :id FOR UPDATE"
    );
    $stmt->execute([':id' => $tripId]);
    $trip = $stmt->fetch();

    if (!$trip || $trip['status'] !== 'SCHEDULED' || $trip['bus_status'] !== 'ACTIVE' || strtotime($trip['departure_time']) <= time()) {
        throw new RuntimeException('That trip is no longer available for booking.');
    }

    foreach ($seats as $n) {
        if ($n > (int)$trip['total_seats']) {
            throw new RuntimeException('Seat ' . $n . ' does not exist on this bus.');
        }
    }
    if (count($seats) > (int)$trip['total_seats']) {
        throw new RuntimeException('Choose only seats available on this bus.');
    }

    // Friendly check: are any chosen seats already taken?
    $marks = implode(',', array_fill(0, count($seats), '?'));
    $check = $pdo->prepare(
        "SELECT seat_number FROM booking_seats
         WHERE trip_id = ? AND seat_status = 'BOOKED' AND seat_number IN ($marks)"
    );
    $check->execute(array_merge([$tripId], $seats));
    $already = $check->fetchAll(PDO::FETCH_COLUMN);
    if ($already) {
        throw new RuntimeException('Seat ' . implode(', ', $already) . ' was just taken. Please choose another seat.');
    }

    // Price comes from the database, not from the form
    $fare  = (float)$trip['fare'];
    $total = $fare * count($seats);

    // 1) booking (temporary reference first, because the real one uses the booking_id)
    $insert = $pdo->prepare(
        "INSERT INTO bookings (user_id, trip_id, booking_reference, status)
         VALUES (:uid, :trip, :ref, 'PENDING')"
    );
    $insert->execute([':uid' => $uid, ':trip' => $tripId, ':ref' => 'TMP-' . bin2hex(random_bytes(7))]);
    $bookingId = (int)$pdo->lastInsertId();

    $reference = sprintf('BT-%s-%06d', date('Y'), $bookingId);
    $pdo->prepare('UPDATE bookings SET booking_reference = :ref WHERE booking_id = :id')
        ->execute([':ref' => $reference, ':id' => $bookingId]);

    // 2) one row per seat
    $seatInsert = $pdo->prepare(
        "INSERT INTO booking_seats (booking_id, trip_id, seat_number, price, seat_status)
         VALUES (:booking, :trip, :seat, :price, 'BOOKED')"
    );
    foreach ($seats as $n) {
        $seatInsert->execute([':booking' => $bookingId, ':trip' => $tripId, ':seat' => $n, ':price' => $fare]);
    }

    // 3) Online payments stay pending until verified; offline payment is due in person.
    $pdo->prepare(
        "INSERT INTO payments (booking_id, amount, payment_method, payment_status)
         VALUES (:booking, :amount, :method, 'PENDING')"
    )->execute([':booking' => $bookingId, ':amount' => $total, ':method' => $method]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // 1062 = duplicate seat (the database's safety net caught a race)
    if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        fail('One of those seats was just taken by someone else. Please choose another seat.', $back);
    }
    error_log('create_booking: ' . $e->getMessage());
    fail('We could not save your booking. Please try again.', $back);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail($e->getMessage(), $back);
}

set_flash('success', 'Reservation saved. Your booking reference is ' . $reference . '.');
if ($method === 'ONLINE') {
    redirect('actions/start_online_payment.php?booking_id=' . $bookingId);
}
redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
