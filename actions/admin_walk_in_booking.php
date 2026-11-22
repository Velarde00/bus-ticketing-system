<?php
/**
 * actions/admin_walk_in_booking.php
 * Walk-in booking handler for admin/staff.
 * Allows CASH, CARD, or E_WALLET payment.
 * Admin can assign seats to any registered passenger (by user_id) OR create a
 * guest entry in the users table (first_name, last_name, phone_number only).
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('ADMIN');

const WALK_IN_MAX_SEATS = 999;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/reservations/index.php');
}

function wi_fail(string $msg, string $back): void
{
    set_flash('danger', $msg);
    redirect($back);
}

if (!csrf_verify()) {
    wi_fail('Session expired. Please try again.', 'admin/reservations/walk_in.php');
}

$tripId = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT);
$back   = $tripId
    ? 'admin/reservations/walk_in.php?trip_id=' . $tripId
    : 'admin/reservations/walk_in.php';

if (!$tripId) {
    wi_fail('Invalid trip selected.', $back);
}

// Payment method — all three are valid for walk-in
$method = $_POST['payment_method'] ?? '';
if (!in_array($method, ['CASH', 'E_WALLET', 'CARD'], true)) {
    wi_fail('Select a valid payment method.', $back);
}

// Payment status — Walk-in payments at the terminal counter are automatically PAID
$payStatus = 'PAID';

// Seats
$rawSeats = $_POST['seats'] ?? [];
if (!is_array($rawSeats)) {
    $rawSeats = [];
}
$seats = [];
foreach ($rawSeats as $s) {
    $n = filter_var($s, FILTER_VALIDATE_INT);
    if ($n === false || $n < 1) {
        wi_fail('Invalid seat selection.', $back);
    }
    $seats[$n] = $n;
}
$seats = array_values($seats);
sort($seats);

if (count($seats) < 1) {
    wi_fail('Select at least one seat.', $back);
}
if (count($seats) > WALK_IN_MAX_SEATS) {
    wi_fail('Walk-in bookings are limited to ' . WALK_IN_MAX_SEATS . ' seats.', $back);
}

// Resolve the user to book under
// Option A: existing user_id
// Option B: guest details (first_name, last_name, phone, [email optional])
$userId   = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
$guestFirst = trim(substr((string)($_POST['guest_first'] ?? ''), 0, 60));
$guestLast  = trim(substr((string)($_POST['guest_last']  ?? ''), 0, 60));
$guestPhone = trim(substr((string)($_POST['guest_phone'] ?? ''), 0, 30));
$guestEmail = trim(substr((string)($_POST['guest_email'] ?? ''), 0, 120));

try {
    $pdo->beginTransaction();

    // --- Resolve passenger user ---
    if ($userId) {
        // Verify the user exists
        $uStmt = $pdo->prepare('SELECT user_id FROM users WHERE user_id = :id AND role = :r');
        $uStmt->execute([':id' => $userId, ':r' => 'PASSENGER']);
        if (!$uStmt->fetchColumn()) {
            throw new RuntimeException('Selected passenger not found in the system.');
        }
        $resolvedUserId = $userId;
    } elseif ($guestFirst !== '' && $guestLast !== '') {
        // Create a guest / walk-in account with a placeholder password
        $guestToken    = bin2hex(random_bytes(6));
        $guestPw       = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $guestEmailVal = ($guestEmail !== '' && filter_var($guestEmail, FILTER_VALIDATE_EMAIL))
            ? $guestEmail
            : ('walkin_' . $guestToken . '@walkin.local');

        $ins = $pdo->prepare(
            "INSERT INTO users (first_name, last_name, email, phone_number, password_hash, role)
             VALUES (:fn, :ln, :e, :ph, :p, 'PASSENGER')"
        );
        $ins->execute([
            ':fn' => $guestFirst,
            ':ln' => $guestLast,
            ':e'  => $guestEmailVal,
            ':ph' => $guestPhone ?: 'N/A',
            ':p'  => $guestPw,
        ]);
        $resolvedUserId = (int)$pdo->lastInsertId();
    } else {
        throw new RuntimeException('Provide either an existing passenger or guest name details.');
    }

    // --- Lock the trip row ---
    $stmt = $pdo->prepare(
        "SELECT t.fare, t.status, t.departure_time, b.total_seats, b.status AS bus_status
         FROM trips t JOIN buses b ON b.bus_id = t.bus_id
         WHERE t.trip_id = :id FOR UPDATE"
    );
    $stmt->execute([':id' => $tripId]);
    $trip = $stmt->fetch();

    if (!$trip || $trip['status'] !== 'SCHEDULED' || $trip['bus_status'] !== 'ACTIVE' || strtotime($trip['departure_time']) <= time()) {
        throw new RuntimeException('That trip is not available for booking.');
    }

    foreach ($seats as $n) {
        if ($n > (int)$trip['total_seats']) {
            throw new RuntimeException('Seat ' . $n . ' does not exist on this bus.');
        }
    }

    // Check taken seats
    $marks = implode(',', array_fill(0, count($seats), '?'));
    $check = $pdo->prepare(
        "SELECT seat_number FROM booking_seats
         WHERE trip_id = ? AND seat_status = 'BOOKED' AND seat_number IN ($marks)"
    );
    $check->execute(array_merge([$tripId], $seats));
    $already = $check->fetchAll(PDO::FETCH_COLUMN);
    if ($already) {
        throw new RuntimeException('Seat(s) ' . implode(', ', $already) . ' are already taken. Please choose other seats.');
    }

    // --- Discount logic per seat ---
    $baseFare      = (float)$trip['fare'];
    $validTypes    = ['REGULAR', 'STUDENT', 'SENIOR', 'PWD'];
    $postTypes     = $_POST['passenger_type'] ?? [];
    $postIds       = $_POST['id_number'] ?? [];
    $seatDetails   = [];
    $totalFare     = 0.0;
    $totalDiscount = 0.0;

    foreach ($seats as $n) {
        $pType = 'REGULAR';
        if (is_array($postTypes) && isset($postTypes[$n])) {
            $val = strtoupper(trim((string)$postTypes[$n]));
            if (in_array($val, $validTypes, true)) {
                $pType = $val;
            }
        }

        $idNum = null;
        if (is_array($postIds) && isset($postIds[$n])) {
            $idNum = trim(substr((string)$postIds[$n], 0, 50)) ?: null;
        }

        $seatDiscount = 0.0;
        if (in_array($pType, ['STUDENT', 'SENIOR', 'PWD'], true)) {
            $seatDiscount = round($baseFare * 0.20, 2);
        }
        $seatPrice = max(0.0, $baseFare - $seatDiscount);

        $seatDetails[$n] = [
            'type'     => $pType,
            'price'    => $seatPrice,
            'discount' => $seatDiscount,
            'id_num'   => $idNum,
        ];

        $totalFare     += $seatPrice;
        $totalDiscount += $seatDiscount;
    }

    // --- Insert booking ---
    $insert = $pdo->prepare(
        "INSERT INTO bookings (user_id, trip_id, booking_reference, total_discount, status)
         VALUES (:uid, :trip, :ref, :discount, 'PENDING')"
    );
    $insert->execute([
        ':uid'      => $resolvedUserId,
        ':trip'     => $tripId,
        ':ref'      => 'TMP-' . bin2hex(random_bytes(7)),
        ':discount' => $totalDiscount,
    ]);
    $bookingId = (int)$pdo->lastInsertId();

    $reference = sprintf('WI-%s-%06d', date('Y'), $bookingId);
    $pdo->prepare('UPDATE bookings SET booking_reference = :ref WHERE booking_id = :id')
        ->execute([':ref' => $reference, ':id' => $bookingId]);

    // --- Insert seats ---
    $seatInsert = $pdo->prepare(
        "INSERT INTO booking_seats (booking_id, trip_id, seat_number, passenger_type, price, discount_amount, id_number, seat_status)
         VALUES (:booking, :trip, :seat, :ptype, :price, :discount, :id_num, 'BOOKED')"
    );
    foreach ($seats as $n) {
        $d = $seatDetails[$n];
        $seatInsert->execute([
            ':booking'  => $bookingId,
            ':trip'     => $tripId,
            ':seat'     => $n,
            ':ptype'    => $d['type'],
            ':price'    => $d['price'],
            ':discount' => $d['discount'],
            ':id_num'   => $d['id_num'],
        ]);
    }

    // --- Insert payment record ---
    $pdo->prepare(
        "INSERT INTO payments (booking_id, amount, payment_method, payment_status)
         VALUES (:booking, :amount, :method, :status)"
    )->execute([
        ':booking' => $bookingId,
        ':amount'  => $totalFare,
        ':method'  => $method,
        ':status'  => $payStatus,
    ]);

    // --- If admin marked as PAID immediately, auto-confirm and issue tickets ---
    if ($payStatus === 'PAID') {
        $pdo->prepare("UPDATE bookings SET status = 'CONFIRMED' WHERE booking_id = :id")
            ->execute([':id' => $bookingId]);

        $seatRows = $pdo->prepare(
            "SELECT bs.booking_seat_id FROM booking_seats bs
             LEFT JOIN tickets tk ON tk.booking_seat_id = bs.booking_seat_id
             WHERE bs.booking_id = :id AND bs.seat_status = 'BOOKED' AND tk.ticket_id IS NULL"
        );
        $seatRows->execute([':id' => $bookingId]);
        $ticketInsert = $pdo->prepare('INSERT INTO tickets (booking_seat_id, ticket_number) VALUES (:seat, :number)');
        $ticketUpdate = $pdo->prepare('UPDATE tickets SET ticket_number = :number WHERE ticket_id = :id');
        foreach ($seatRows->fetchAll(PDO::FETCH_COLUMN) as $seatId) {
            $ticketInsert->execute([':seat' => $seatId, ':number' => 'TMP-' . bin2hex(random_bytes(8))]);
            $ticketId = (int)$pdo->lastInsertId();
            $ticketUpdate->execute([':number' => sprintf('TKT-%s-%06d', date('Y'), $ticketId), ':id' => $ticketId]);
        }
    }

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        wi_fail('A seat conflict occurred. Please try again.', $back);
    }
    error_log('admin_walk_in_booking: ' . $e->getMessage());
    wi_fail('Database error. Please try again.', $back);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    wi_fail($e->getMessage(), $back);
}

$flashMsg = 'Walk-in booking saved — reference: ' . $reference . '.';
if ($payStatus === 'PAID') {
    $flashMsg .= ' Booking confirmed and tickets issued automatically.';
}
set_flash('success', $flashMsg);
redirect('admin/reservations/view.php?id=' . $bookingId);
