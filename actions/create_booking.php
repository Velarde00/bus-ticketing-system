<?php
/**
 * actions/create_booking.php
 * Saves a reservation. Everything happens inside ONE database transaction:
 * the booking, its seats, and the payment record are saved together or not at all.
 * The server re-checks everything. It never trusts prices or seat numbers from the browser.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

const MAX_SEATS = 6;

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

// Online passenger bookings must use E-Wallet — Cash and Card are walk-in only
$method = 'E_WALLET';

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
if (count($seats) > MAX_SEATS) {
    fail('You can book up to ' . MAX_SEATS . ' seats at a time.', $back);
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
    $baseFare = (float)$trip['fare'];

    // Process discounts for Student, Senior Citizen, and PWD (20% discount under Philippine Law)
    $validTypes = ['REGULAR', 'STUDENT', 'SENIOR', 'PWD'];
    $postTypes  = $_POST['passenger_type'] ?? [];
    $postIds    = $_POST['id_number'] ?? [];

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
        } elseif (is_string($postTypes)) {
            $val = strtoupper(trim($postTypes));
            if (in_array($val, $validTypes, true)) {
                $pType = $val;
            }
        }

        $idNum = null;
        if (is_array($postIds) && isset($postIds[$n])) {
            $idNum = trim(substr((string)$postIds[$n], 0, 50)) ?: null;
        } elseif (is_string($postIds)) {
            $idNum = trim(substr($postIds, 0, 50)) ?: null;
        }

        $seatDiscount = 0.0;
        if (in_array($pType, ['STUDENT', 'SENIOR', 'PWD'], true)) {
            // 20% discount rounded to 2 decimals
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

    // 1) booking (starts as PENDING awaiting online OTP payment authorization)
    $insert = $pdo->prepare(
        "INSERT INTO bookings (user_id, trip_id, booking_reference, total_discount, status)
         VALUES (:uid, :trip, :ref, :discount, 'PENDING')"
    );
    $insert->execute([
        ':uid'      => $uid,
        ':trip'     => $tripId,
        ':ref'      => 'TMP-' . bin2hex(random_bytes(7)),
        ':discount' => $totalDiscount,
    ]);
    $bookingId = (int)$pdo->lastInsertId();

    $reference = sprintf('BT-%s-%06d', date('Y'), $bookingId);
    $pdo->prepare('UPDATE bookings SET booking_reference = :ref WHERE booking_id = :id')
        ->execute([':ref' => $reference, ':id' => $bookingId]);

    // 2) one row per seat with passenger type, discounted price, and ID number
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

    $walletChoice = strtoupper(trim((string)($_POST['online_wallet'] ?? 'GCASH')));
    if (!in_array($walletChoice, ['GCASH', 'MAYA'], true)) {
        $walletChoice = 'GCASH';
    }

    // 3) payment record (starts as PENDING for OTP verification)
    $pdo->prepare(
        "INSERT INTO payments (booking_id, amount, payment_method, online_payment_option, payment_status)
         VALUES (:booking, :amount, :method, :opt, 'PENDING')"
    )->execute([
        ':booking' => $bookingId,
        ':amount'  => $totalFare,
        ':method'  => $method,
        ':opt'     => $walletChoice
    ]);

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

// Fetch user email and phone to send Payment OTP
$uStmt = $pdo->prepare('SELECT email, phone_number FROM users WHERE user_id = :id');
$uStmt->execute([':id' => $uid]);
$userData = $uStmt->fetch();

$userEmail = $userData['email'] ?? '';
$userPhone = $userData['phone_number'] ?? '';

$otpData = create_and_dispatch_otp($pdo, $userEmail, $userPhone, 'PAYMENT', $uid, $bookingId);

$_SESSION['pending_payment_otp'] = [
    'booking_id'   => $bookingId,
    'wallet'       => $walletChoice,
    'amount'       => $totalFare,
    'email'        => $userEmail,
    'phone'        => $userPhone,
    'masked_email' => $otpData['masked_email'],
    'masked_phone' => $otpData['masked_phone'],
    'code'         => $otpData['code'],
    'expires_at'   => $otpData['expires_at'],
];

redirect('passenger/verify_payment.php?booking_id=' . $bookingId);
