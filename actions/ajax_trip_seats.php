<?php
/**
 * actions/ajax_trip_seats.php
 * Returns JSON data or HTML snippet for an admin trip's live seat layout.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('ADMIN');

header('Content-Type: application/json; charset=UTF-8');

$tripId = filter_var($_GET['trip_id'] ?? null, FILTER_VALIDATE_INT);
if (!$tripId) {
    echo json_encode(['success' => false, 'message' => 'Invalid trip ID.']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT t.trip_id, t.departure_time, t.arrival_time, t.fare, t.status,
            b.bus_number, b.bus_type, b.total_seats,
            r.origin, r.destination,
            CONCAT(d.first_name, ' ', d.last_name) AS driver_name
     FROM trips t
     JOIN buses b ON b.bus_id = t.bus_id
     JOIN routes r ON r.route_id = t.route_id
     JOIN drivers d ON d.driver_id = t.driver_id
     WHERE t.trip_id = :id"
);
$stmt->execute([':id' => $tripId]);
$trip = $stmt->fetch();

if (!$trip) {
    echo json_encode(['success' => false, 'message' => 'Trip not found.']);
    exit;
}

$seatsStmt = $pdo->prepare(
    "SELECT bs.seat_number, bk.booking_id, bk.booking_reference, bk.status AS booking_status,
            CONCAT(u.first_name, ' ', u.last_name) AS passenger, u.phone_number
     FROM booking_seats bs
     JOIN bookings bk ON bk.booking_id = bs.booking_id
     JOIN users u ON u.user_id = bk.user_id
     WHERE bs.trip_id = :id AND bs.seat_status = 'BOOKED'
     ORDER BY bs.seat_number"
);
$seatsStmt->execute([':id' => $tripId]);
$bookedSeats = [];
foreach ($seatsStmt->fetchAll() as $s) {
    $bookedSeats[(int)$s['seat_number']] = $s;
}

$totalSeats = (int)$trip['total_seats'];
$availableCount = $totalSeats - count($bookedSeats);

echo json_encode([
    'success' => true,
    'trip' => [
        'id' => (int)$trip['trip_id'],
        'route' => $trip['origin'] . ' to ' . $trip['destination'],
        'bus_number' => $trip['bus_number'],
        'bus_type' => $trip['bus_type'],
        'driver' => $trip['driver_name'],
        'departure' => fmt_datetime($trip['departure_time']),
        'total_seats' => $totalSeats,
        'available_seats' => $availableCount,
        'booked_count' => count($bookedSeats),
        'status' => $trip['status'],
        'fare' => money($trip['fare']),
    ],
    'booked_seats' => $bookedSeats,
]);
