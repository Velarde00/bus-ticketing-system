<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('ADMIN');

$catalogs = [
    'buses' => [
        'title' => 'Manage buses', 'singular' => 'bus', 'page' => 'buses', 'table' => 'buses', 'key' => 'bus_id',
        'fields' => [
            'bus_number' => ['Bus number', 'text', 20],
            'bus_type' => ['Bus type', 'grouped_select', [
                'By Cabin Configuration & Comfort' => [
                    'Standard Regular Coach',
                    'Deluxe / First Class Coach',
                    'Sleeper / Super Deluxe ("Lazy Boy") Bus',
                    'Ordinary Bus (Non-Aircon)',
                ],
                'By Operations & Route Type' => [
                    'Point-to-Point (P2P) Bus',
                    'City Commuter Bus',
                    'Provincial Intercity Bus',
                ],
                'By Chassis Design & Deck Level' => [
                    'Single-Decker High-Floor Coach',
                    'Low-Floor / Low-Entry Urban Bus',
                    'Double-Decker Bus',
                    'Tri-Axle Bus',
                ],
            ]],
            'total_seats' => ['Total seats', 'number', 80, 1],
            'status' => ['Status', 'select', ['ACTIVE', 'MAINTENANCE', 'INACTIVE']],
        ],
        'list' => "SELECT bus_id AS id, bus_number, bus_type, total_seats, status FROM buses ORDER BY bus_number",
        'columns' => ['Bus number' => 'bus_number', 'Type' => 'bus_type', 'Seats' => 'total_seats', 'Status' => 'status'],
    ],
    'drivers' => [
        'title' => 'Manage drivers', 'singular' => 'driver', 'page' => 'drivers', 'table' => 'drivers', 'key' => 'driver_id',
        'fields' => [
            'first_name' => ['First name', 'text', 50],
            'last_name' => ['Last name', 'text', 50],
            'license_number' => ['License number', 'text', 30],
            'phone_number' => ['Phone number', 'text', 20],
            'license_expiry' => ['License expiry', 'date'],
            'status' => ['Status', 'select', ['ACTIVE', 'INACTIVE']],
        ],
        'list' => "SELECT driver_id AS id, CONCAT(first_name, ' ', last_name) AS driver_name, license_number, phone_number, license_expiry, status FROM drivers ORDER BY last_name, first_name",
        'columns' => ['Driver' => 'driver_name', 'License' => 'license_number', 'Phone' => 'phone_number', 'License expiry' => 'license_expiry', 'Status' => 'status'],
    ],
    'routes' => [
        'title' => 'Manage routes', 'singular' => 'route', 'page' => 'routes', 'table' => 'routes', 'key' => 'route_id',
        'fields' => [
            'origin' => ['Origin', 'text', 60],
            'destination' => ['Destination', 'text', 60],
            'distance_km' => ['Distance (km)', 'decimal', 7, 2, 0.01],
            'status' => ['Status', 'select', ['ACTIVE', 'INACTIVE']],
        ],
        'list' => "SELECT route_id AS id, origin, destination, distance_km, status FROM routes ORDER BY origin, destination",
        'columns' => ['Origin' => 'origin', 'Destination' => 'destination', 'Distance (km)' => 'distance_km', 'Status' => 'status'],
    ],
    'trips' => [
        'title' => 'Manage trips', 'singular' => 'trip', 'page' => 'trips', 'table' => 'trips', 'key' => 'trip_id',
        'fields' => [
            'bus_id' => ['Bus', 'options', 'SELECT bus_id AS id, bus_number AS label FROM buses ORDER BY bus_number'],
            'driver_id' => ['Driver', 'options', "SELECT driver_id AS id, CONCAT(first_name, ' ', last_name) AS label FROM drivers ORDER BY last_name, first_name"],
            'route_id' => ['Route', 'options', "SELECT route_id AS id, CONCAT(origin, ' to ', destination) AS label FROM routes ORDER BY origin, destination"],
            'departure_time' => ['Departure', 'datetime'],
            'arrival_time' => ['Arrival', 'datetime'],
            'fare' => ['Fare', 'decimal', 10, 2, 0],
            'status' => ['Status', 'select', ['SCHEDULED', 'COMPLETED', 'CANCELLED']],
        ],
        'list' => "SELECT t.trip_id AS id, CONCAT(r.origin, ' to ', r.destination) AS route_name, b.bus_number, CONCAT(d.first_name, ' ', d.last_name) AS driver_name, t.departure_time, t.arrival_time, t.fare,
                    (CASE
                        WHEN t.status = 'CANCELLED' THEN 'CANCELLED'
                        WHEN t.status = 'COMPLETED' THEN 'COMPLETED'
                        WHEN GREATEST(0, b.total_seats - (SELECT COUNT(*) FROM booking_seats bs WHERE bs.trip_id = t.trip_id AND bs.seat_status = 'BOOKED')) = 0 THEN 'SOLD OUT'
                        ELSE t.status
                    END) AS status,
                    CONCAT(GREATEST(0, b.total_seats - (SELECT COUNT(*) FROM booking_seats bs WHERE bs.trip_id = t.trip_id AND bs.seat_status = 'BOOKED')), ' / ', b.total_seats) AS available_seats
             FROM trips t JOIN routes r ON r.route_id = t.route_id JOIN buses b ON b.bus_id = t.bus_id JOIN drivers d ON d.driver_id = t.driver_id ORDER BY t.departure_time DESC",
        'columns' => ['Route' => 'route_name', 'Bus' => 'bus_number', 'Driver' => 'driver_name', 'Seats Avail' => 'available_seats', 'Departure' => 'departure_time', 'Arrival' => 'arrival_time', 'Fare' => 'fare', 'Status' => 'status'],
    ],
];

$type = $catalogType ?? '';
if (!isset($catalogs[$type])) {
    http_response_code(404);
    exit('Catalog not found.');
}
$config = $catalogs[$type];
$basePath = 'admin/' . $type . '/index.php';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawId = $_POST['id'] ?? '';
    $id = filter_var(is_scalar($rawId) ? $rawId : '', FILTER_VALIDATE_INT);
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'delete') {
        if (!$id) {
            $errors[] = 'Choose a valid record to delete.';
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM {$config['table']} WHERE {$config['key']} = :id");
                $stmt->execute([':id' => $id]);
                set_flash('success', 'Record deleted.');
                redirect($basePath);
            } catch (PDOException $e) {
                error_log('admin catalog delete: ' . $e->getMessage());
                $errors[] = 'This record cannot be deleted because it is in use. Change its status to inactive instead.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'save') {
        $values = [];
        foreach ($config['fields'] as $name => $definition) {
            $label = $definition[0];
            $kind = $definition[1];
            $rawValue = $_POST[$name] ?? '';
            $value = is_scalar($rawValue) ? trim((string)$rawValue) : '';
            if ($value === '') {
                $errors[] = $label . ' is required.';
                continue;
            }
            if ($kind === 'select' || $kind === 'grouped_select') {
                $allowedValues = $kind === 'select' ? $definition[2] : array_merge(...array_values($definition[2]));
                if (!in_array($value, $allowedValues, true)) {
                    $errors[] = 'Choose a valid ' . strtolower($label) . '.';
                }
            } elseif ($kind === 'options') {
                $trimmedVal = trim((string)$value);
                // Check if numeric and exists in database
                $resolvedId = null;
                if (ctype_digit($trimmedVal)) {
                    $validIds = $pdo->query($definition[2])->fetchAll(PDO::FETCH_COLUMN);
                    if (in_array((int)$trimmedVal, array_map('intval', $validIds), true)) {
                        $resolvedId = (int)$trimmedVal;
                    }
                }

                // If not found by ID or user typed any custom string/name, auto-create or match for presentation
                if ($resolvedId === null && $trimmedVal !== '') {
                    if ($name === 'bus_id') {
                        // Check if bus_number matches existing
                        $bCheck = $pdo->prepare("SELECT bus_id FROM buses WHERE bus_number = :num LIMIT 1");
                        $bCheck->execute([':num' => $trimmedVal]);
                        $foundBusId = $bCheck->fetchColumn();
                        if ($foundBusId) {
                            $resolvedId = (int)$foundBusId;
                        } else {
                            // Auto create bus with this number
                            $bIns = $pdo->prepare("INSERT INTO buses (bus_number, bus_type, total_seats, status) VALUES (:num, 'Standard Regular Coach', 45, 'ACTIVE')");
                            $bIns->execute([':num' => $trimmedVal]);
                            $resolvedId = (int)$pdo->lastInsertId();
                        }
                    } elseif ($name === 'driver_id') {
                        // Check if driver name matches existing
                        $dParts = preg_split('/\s+/', $trimmedVal, 2);
                        $dFirst = $dParts[0] ?? 'Driver';
                        $dLast = $dParts[1] ?? 'Demo';
                        $dCheck = $pdo->prepare("SELECT driver_id FROM drivers WHERE first_name = :fn AND last_name = :ln LIMIT 1");
                        $dCheck->execute([':fn' => $dFirst, ':ln' => $dLast]);
                        $foundDriverId = $dCheck->fetchColumn();
                        if ($foundDriverId) {
                            $resolvedId = (int)$foundDriverId;
                        } else {
                            // Auto create driver
                            $dIns = $pdo->prepare("INSERT INTO drivers (first_name, last_name, license_number, phone_number, license_expiry, status) VALUES (:fn, :ln, :lic, '09123456789', DATE_ADD(CURDATE(), INTERVAL 3 YEAR), 'ACTIVE')");
                            $dIns->execute([':fn' => $dFirst, ':ln' => $dLast, ':lic' => 'LIC-' . strtoupper(substr(md5($trimmedVal), 0, 8))]);
                            $resolvedId = (int)$pdo->lastInsertId();
                        }
                    } elseif ($name === 'route_id') {
                        // Check if origin/destination is provided like "Origin to Destination" or just one name
                        $rParts = preg_split('/\s+(?:to|-|->|–)\s+/i', $trimmedVal, 2);
                        $rOrig = trim($rParts[0] ?? 'Terminal A');
                        $rDest = trim($rParts[1] ?? ($rOrig . ' City'));
                        if ($rOrig === $rDest) {
                            $rDest = $rOrig . ' Destination';
                        }
                        $rCheck = $pdo->prepare("SELECT route_id FROM routes WHERE origin = :orig AND destination = :dest LIMIT 1");
                        $rCheck->execute([':orig' => $rOrig, ':dest' => $rDest]);
                        $foundRouteId = $rCheck->fetchColumn();
                        if ($foundRouteId) {
                            $resolvedId = (int)$foundRouteId;
                        } else {
                            $rIns = $pdo->prepare("INSERT INTO routes (origin, destination, distance_km, status) VALUES (:orig, :dest, 120.00, 'ACTIVE')");
                            $rIns->execute([':orig' => $rOrig, ':dest' => $rDest]);
                            $resolvedId = (int)$pdo->lastInsertId();
                        }
                    }
                }

                if ($resolvedId !== null) {
                    $value = $resolvedId;
                } else {
                    $errors[] = 'Choose a valid ' . strtolower($label) . '.';
                }
            } elseif ($kind === 'number') {
                $number = filter_var($value, FILTER_VALIDATE_INT);
                if ($number === false || $number < $definition[3] || $number > $definition[2]) {
                    $errors[] = $label . ' must be between ' . $definition[3] . ' and ' . $definition[2] . '.';
                } else {
                    $value = $number;
                }
            } elseif ($kind === 'decimal') {
                $maxValue = pow(10, $definition[2] - $definition[3]);
                $decimalPattern = '/^\d+(?:\.\d{1,' . $definition[3] . '})?$/';
                if (!preg_match($decimalPattern, $value) || (float)$value < $definition[4] || (float)$value >= $maxValue) {
                    $errors[] = 'Enter a valid ' . strtolower($label) . '.';
                } else {
                    $value = number_format((float)$value, 2, '.', '');
                }
            } elseif ($kind === 'date') {
                $date = DateTime::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    $errors[] = 'Enter a valid ' . strtolower($label) . '.';
                }
            } elseif ($kind === 'datetime') {
                $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);
                if (!$date || $date->format('Y-m-d\TH:i') !== $value) {
                    $errors[] = 'Enter a valid ' . strtolower($label) . '.';
                } else {
                    $value = $date->format('Y-m-d H:i:s');
                }
            } elseif (strlen($value) > $definition[2]) {
                $errors[] = $label . ' is too long.';
            }
            $values[$name] = $value;
        }

        if ($type === 'routes' && strcasecmp($values['origin'] ?? '', $values['destination'] ?? '') === 0) {
            $errors[] = 'Origin and destination must be different.';
        }
        if ($type === 'trips' && !empty($values['departure_time']) && !empty($values['arrival_time']) && $values['arrival_time'] <= $values['departure_time']) {
            $errors[] = 'Arrival must be after departure.';
        }

        if (!$errors) {
            try {
                if ($id) {
                    $sets = [];
                    foreach (array_keys($values) as $name) {
                        $sets[] = $name . ' = :' . $name;
                    }
                    $values['id'] = $id;
                    $stmt = $pdo->prepare("UPDATE {$config['table']} SET " . implode(', ', $sets) . " WHERE {$config['key']} = :id");
                    $stmt->execute($values);

                    // If a trip status is changed to CANCELLED by admin, cancel all bookings and notify passengers
                    if ($type === 'trips' && ($values['status'] ?? '') === 'CANCELLED') {
                        // 1. Fetch trip details & affected bookings
                        $tripInfoStmt = $pdo->prepare(
                            "SELECT t.departure_time, r.origin, r.destination, b.bus_number
                             FROM trips t
                             JOIN routes r ON r.route_id = t.route_id
                             JOIN buses b ON b.bus_id = t.bus_id
                             WHERE t.trip_id = :tid"
                        );
                        $tripInfoStmt->execute([':tid' => $id]);
                        $tInfo = $tripInfoStmt->fetch();

                        $affectedBookingsStmt = $pdo->prepare(
                            "SELECT bk.booking_id, bk.booking_reference, bk.user_id, u.email AS user_email
                             FROM bookings bk
                             JOIN users u ON u.user_id = bk.user_id
                             WHERE bk.trip_id = :tid AND bk.status <> 'CANCELLED'"
                        );
                        $affectedBookingsStmt->execute([':tid' => $id]);
                        $affectedBookings = $affectedBookingsStmt->fetchAll();

                        // 2. Mark bookings and seats as CANCELLED
                        $pdo->prepare(
                            "UPDATE bookings SET status = 'CANCELLED', cancelled_by = 'ADMIN'
                             WHERE trip_id = :tid AND status <> 'CANCELLED'"
                        )->execute([':tid' => $id]);

                        $pdo->prepare(
                            "UPDATE booking_seats bs
                             JOIN bookings bk ON bk.booking_id = bs.booking_id
                             SET bs.seat_status = 'CANCELLED'
                             WHERE bk.trip_id = :tid"
                        )->execute([':tid' => $id]);

                        // 3. Notify each affected passenger
                        $originDest = ($tInfo['origin'] ?? 'Origin') . ' → ' . ($tInfo['destination'] ?? 'Destination');
                        $depFormatted = !empty($tInfo['departure_time']) ? fmt_datetime($tInfo['departure_time']) : 'scheduled departure';

                        foreach ($affectedBookings as $ab) {
                            $notifTitle = 'Trip Cancelled by Operator';
                            $notifMsg = "Your trip ({$originDest}) scheduled for {$depFormatted} (Ref: {$ab['booking_reference']}) was cancelled by bus terminal management. You can rebook another trip or request a full refund.";
                            $notifLink = 'passenger/booking_confirmation.php?booking_id=' . (int)$ab['booking_id'];

                            send_user_notification(
                                $pdo,
                                (int)$ab['user_id'],
                                $notifTitle,
                                $notifMsg,
                                $notifLink,
                                $ab['user_email'] ?? null
                            );
                        }
                    }
                } else {
                    $names = array_keys($values);
                    $stmt = $pdo->prepare(
                        "INSERT INTO {$config['table']} (" . implode(', ', $names) . ")
                         VALUES (:" . implode(', :', $names) . ")"
                    );
                    $stmt->execute($values);
                }
                set_flash('success', $config['title'] . ' saved.');
                redirect($basePath);
            } catch (PDOException $e) {
                error_log('admin catalog save: ' . $e->getMessage());
                $errors[] = 'Could not save this record. Check that unique values are not already in use and trip resources are available.';
            }
        }
    } else {
        $errors[] = 'Choose a valid action.';
    }
}

$record = null;
$rawEditId = $_GET['id'] ?? ($_POST['id'] ?? '');
$editId = filter_var(is_scalar($rawEditId) ? $rawEditId : '', FILTER_VALIDATE_INT);
if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM {$config['table']} WHERE {$config['key']} = :id");
    $stmt->execute([':id' => $editId]);
    $record = $stmt->fetch();
}
$rows = $pdo->query($config['list'])->fetchAll();

$pageTitle = $config['title'];
$layout = 'admin';
$activePage = $config['page'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1 text-dark"><?= e($config['title']) ?></h1>
        <p class="text-muted small mb-0">Add, update, and maintain records for <?= e($type) ?>.</p>
    </div>
    <button type="button" class="btn btn-primary btn-sm rounded-pill shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#catalogModal" id="btnOpenAddModal">
        <i class="bi bi-plus-lg me-1"></i> New <?= e($config['singular']) ?>
    </button>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger py-2 border-0 shadow-sm small mb-4">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="panel bg-white rounded-4 border shadow-sm overflow-hidden mb-4">
    <div class="panel-head p-3 px-4 border-bottom d-flex justify-content-between align-items-center bg-light bg-opacity-50">
        <h2 class="h6 fw-bold mb-0 text-uppercase letter-spacing-1 text-muted">Current <?= e(ucfirst($type)) ?> Database</h2>
        <span class="badge bg-light text-muted border"><?= count($rows) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 custom-modern-table">
            <thead>
                <tr>
                    <?php foreach ($config['columns'] as $label => $_): ?>
                        <th class="ps-3"><?= e($label) ?></th>
                    <?php endforeach; ?>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($config['columns'] as $field): ?>
                        <td class="ps-3"><?= in_array($field, ['status'], true) ? status_badge((string)$row[$field]) : e($row[$field]) ?></td>
                    <?php endforeach; ?>
                    <td class="text-end text-nowrap pe-3">
                        <div class="dropdown d-inline-block">
                            <button class="btn btn-light btn-sm rounded-circle p-2 shadow-xs border" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Actions" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 py-1">
                                <li>
                                    <a class="dropdown-item py-2 px-3 small d-flex align-items-center gap-2" href="<?= url($basePath . '?id=' . (int)$row['id']) ?>">
                                        <i class="bi bi-pencil text-primary"></i> Edit <?= e(ucfirst($config['singular'])) ?>
                                    </a>
                                </li>
                                <?php if ($type === 'trips'): ?>
                                    <li>
                                        <button type="button" class="dropdown-item py-2 px-3 small d-flex align-items-center gap-2 btn-open-seatmap-modal"
                                                data-trip-id="<?= (int)$row['id'] ?>">
                                            <i class="bi bi-grid-3x3 text-info"></i> View Seat Map
                                        </button>
                                    </li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li>
                                    <form method="post" data-confirm="Delete this record? Records in use cannot be deleted.">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button class="dropdown-item py-2 px-3 small text-danger d-flex align-items-center gap-2" type="submit">
                                            <i class="bi bi-trash3 text-danger"></i> Delete
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= count($config['columns']) + 1 ?>" class="text-center text-muted py-5">No records found. Click <strong>New <?= e($config['singular']) ?></strong> to create one.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Form for Add / Edit -->
<div class="modal fade" id="catalogModal" tabindex="-1" aria-labelledby="catalogModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered <?= $type === 'trips' ? 'modal-lg' : '' ?>">
        <div class="modal-content rounded-4 border-0 shadow">
            <form method="post" id="catalogModalForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($record['id'] ?? $editId ?? 0) ?>">

                <div class="modal-header border-bottom px-4 pt-4 pb-3">
                    <div class="d-flex align-items-center gap-2">
                        <span class="stat-icon-wrapper bg-primary-subtle text-primary">
                            <i class="bi bi-<?= $record ? 'pencil-square' : 'plus-circle' ?>"></i>
                        </span>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="catalogModalLabel">
                            <?= $record ? 'Edit ' . e(ucfirst($config['singular'])) : 'Add New ' . e(ucfirst($config['singular'])) ?>
                        </h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body px-4 py-3">
                    <div class="row g-3">
                    <?php foreach ($config['fields'] as $name => $definition): ?>
                        <?php
                            $kind = $definition[1];
                            $value = $_POST[$name] ?? ($record[$name] ?? '');
                            if ($kind === 'datetime' && $value !== '') {
                                $value = str_replace(' ', 'T', substr((string)$value, 0, 16));
                            }
                            $colClass = ($type === 'trips' && in_array($name, ['bus_id', 'driver_id', 'departure_time', 'arrival_time', 'fare', 'status'], true)) ? 'col-md-6' : 'col-12';
                        ?>
                        <div class="<?= $colClass ?>">
                            <label class="form-label small fw-semibold" for="<?= e($name) ?>"><?= e($definition[0]) ?></label>
                            <?php if ($kind === 'options'): ?>
                                <?php
                                    $optRows = $pdo->query($definition[2])->fetchAll(PDO::FETCH_KEY_PAIR);
                                ?>
                                <input class="form-control" list="<?= e($name) ?>_list" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" placeholder="Select or type any custom <?= strtolower(e($definition[0])) ?>..." required autocomplete="off">
                                <datalist id="<?= e($name) ?>_list">
                                    <?php foreach ($optRows as $optionValue => $label): ?>
                                        <option value="<?= e($optionValue) ?>"><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </datalist>
                            <?php elseif ($kind === 'select' || $kind === 'grouped_select'): ?>
                                <?php
                                    $isGrouped = $kind === 'grouped_select';
                                    $optionGroups = $isGrouped ? $definition[2] : [
                                        '' => array_combine($definition[2], $definition[2]),
                                    ];
                                ?>
                                <select class="form-select custom-select-styled" id="<?= e($name) ?>" name="<?= e($name) ?>" required>
                                    <option value="">Choose <?= strtolower(e($definition[0])) ?>...</option>
                                    <?php foreach ($optionGroups as $group => $options): ?>
                                        <?php if ($group !== ''): ?><optgroup label="<?= e($group) ?>"><?php endif; ?>
                                        <?php foreach ($options as $optionValue => $label): ?>
                                            <?php if (is_int($optionValue)): $optionValue = $label; endif; ?>
                                            <option value="<?= e($optionValue) ?>" <?= (string)$value === (string)$optionValue ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $label)) ?></option>
                                        <?php endforeach; ?>
                                        <?php if ($group !== ''): ?></optgroup><?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <?php
                                    $inputType = ['number' => 'number', 'decimal' => 'number', 'date' => 'date', 'datetime' => 'datetime-local'][$kind] ?? 'text';
                                    $step = $kind === 'decimal' ? '0.01' : ($kind === 'number' ? '1' : null);
                                    $maxLength = $kind === 'text' ? $definition[2] : null;
                                    $min = $kind === 'number' ? $definition[3] : ($kind === 'decimal' ? $definition[4] : null);
                                    $max = $kind === 'number' ? $definition[2] : ($kind === 'decimal' ? pow(10, $definition[2] - $definition[3]) - pow(10, -$definition[3]) : null);
                                ?>
                                <input class="form-control" type="<?= e($inputType) ?>" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" <?= $step ? 'step="' . e($step) . '"' : '' ?> <?= $min !== null ? 'min="' . e($min) . '"' : '' ?> <?= $max !== null ? 'max="' . e($max) . '"' : '' ?> <?= $maxLength ? 'maxlength="' . e($maxLength) . '"' : '' ?> required>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>

                <div class="modal-footer border-top px-4 py-3 bg-light bg-opacity-50">
                    <?php if ($record): ?>
                        <a href="<?= url($basePath) ?>" class="btn btn-outline-secondary rounded-pill px-3">Cancel</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary rounded-pill shadow-sm px-4">
                        <i class="bi bi-check-lg me-1"></i> <?= $record ? 'Save Changes' : 'Save Record' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($type === 'trips'): ?>
<!-- Modal for Quick Seat Map View -->
<div class="modal fade" id="tripSeatMapModal" tabindex="-1" aria-labelledby="tripSeatMapModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom px-4 pt-4 pb-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="stat-icon-wrapper bg-info-subtle text-info">
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="tripSeatMapModalLabel">Live Bus Seat Map</h5>
                        <small class="text-muted" id="seatmapTripSubtitle">Loading trip details...</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="seatmapModalBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="text-muted small mt-2">Loading seat layout...</div>
                </div>
            </div>
            <div class="modal-footer border-top px-4 py-3 bg-light bg-opacity-50 justify-content-between">
                <div class="d-flex align-items-center gap-3 small">
                    <span class="d-flex align-items-center gap-1"><span class="badge bg-light border text-dark" style="width:16px;height:16px;display:inline-block;padding:0;"></span> Available</span>
                    <span class="d-flex align-items-center gap-1"><span class="badge bg-danger text-white" style="width:16px;height:16px;display:inline-block;padding:0;"></span> Booked</span>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($record || (!empty($errors) && ($_POST['action'] ?? '') === 'save')): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('catalogModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        var myModal = new bootstrap.Modal(modalEl);
        myModal.show();
    }
});
</script>
<?php endif; ?>

<?php if ($type === 'trips'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('tripSeatMapModal');
    const modalBody = document.getElementById('seatmapModalBody');
    const subtitle = document.getElementById('seatmapTripSubtitle');
    let bsModal = null;
    if (modalEl && typeof bootstrap !== 'undefined') {
        bsModal = new bootstrap.Modal(modalEl);
    }

    document.querySelectorAll('.btn-open-seatmap-modal').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const tripId = this.dataset.tripId;
            if (!tripId || !bsModal) return;

            modalBody.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="text-muted small mt-2">Loading seat layout...</div>
                </div>`;
            subtitle.textContent = `Trip #${tripId}`;
            bsModal.show();

            fetch('<?= url("actions/ajax_trip_seats.php") ?>?trip_id=' + encodeURIComponent(tripId))
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        modalBody.innerHTML = `<div class="alert alert-danger py-2 small">${data.message || 'Could not load seat map.'}</div>`;
                        return;
                    }

                    const t = data.trip;
                    const booked = data.booked_seats;
                    subtitle.textContent = `${t.route} • Bus #${t.bus_number} (${t.bus_type}) • Departs: ${t.departure}`;

                    const total = t.total_seats;
                    const rows = Math.ceil(total / 4);

                    let seatsHtml = '';
                    for (let r = 0; r < rows; r++) {
                        seatsHtml += `<div class="d-flex justify-content-center align-items-center gap-2 mb-2">`;
                        for (let c = 0; c < 4; c++) {
                            if (c === 2) {
                                seatsHtml += `<div style="width: 28px; text-align: center;" class="text-muted small fw-bold"><i class="bi bi-arrows-vertical opacity-25"></i></div>`;
                            }
                            const num = r * 4 + c + 1;
                            if (num > total) {
                                seatsHtml += `<div style="width: 44px; height: 44px;"></div>`;
                                continue;
                            }

                            const isBooked = booked[num];
                            if (isBooked) {
                                seatsHtml += `
                                    <div class="seat-badge-booked bg-danger text-white rounded-3 shadow-xs d-flex flex-column align-items-center justify-content-center fw-bold"
                                         style="width: 44px; height: 44px; font-size: 0.85rem;"
                                         title="Seat ${num}: Booked by ${isBooked.passenger} (${isBooked.booking_reference})">
                                        <span>${num}</span>
                                        <i class="bi bi-person-fill" style="font-size: 0.65rem; line-height: 1;"></i>
                                    </div>`;
                            } else {
                                seatsHtml += `
                                    <div class="seat-badge-open bg-white border border-2 border-secondary border-opacity-25 text-dark rounded-3 shadow-xs d-flex flex-column align-items-center justify-content-center fw-bold"
                                         style="width: 44px; height: 44px; font-size: 0.85rem;"
                                         title="Seat ${num}: Available">
                                        <span>${num}</span>
                                        <span class="small opacity-50" style="font-size: 0.6rem; line-height: 1;">Open</span>
                                    </div>`;
                            }
                        }
                        seatsHtml += `</div>`;
                    }

                    let passengerTableHtml = '';
                    const bookedEntries = Object.entries(booked);
                    if (bookedEntries.length > 0) {
                        passengerTableHtml = `
                            <div class="mt-4 pt-3 border-top">
                                <h6 class="fw-bold text-dark mb-2"><i class="bi bi-people-fill text-primary me-1"></i> Booked Passengers (${bookedEntries.length})</h6>
                                <div class="table-responsive" style="max-height: 200px; overflow-y: auto;">
                                    <table class="table table-sm table-hover align-middle mb-0 small">
                                        <thead class="table-light sticky-top">
                                            <tr><th>Seat</th><th>Passenger</th><th>Mobile</th><th>Reference</th><th>Status</th></tr>
                                        </thead>
                                        <tbody>
                                            ${bookedEntries.map(([sNum, sInfo]) => `
                                                <tr>
                                                    <td class="fw-bold text-danger">Seat ${sNum}</td>
                                                    <td class="fw-semibold text-dark">${sInfo.passenger}</td>
                                                    <td class="text-muted font-monospace">${sInfo.phone_number || '—'}</td>
                                                    <td class="font-monospace text-muted">${sInfo.booking_reference}</td>
                                                    <td><span class="badge bg-success-subtle text-success border border-success-subtle">${sInfo.booking_status}</span></td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            </div>`;
                    }

                    modalBody.innerHTML = `
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 bg-light rounded-3 border">
                            <div>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill fw-semibold">
                                    <i class="bi bi-check2-circle me-1"></i> ${t.available_seats} Seats Available
                                </span>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1 rounded-pill fw-semibold ms-1">
                                    <i class="bi bi-person-fill me-1"></i> ${t.booked_count} Booked
                                </span>
                            </div>
                            <div class="text-muted small">
                                Driver: <strong class="text-dark">${t.driver}</strong> &bull; Fare: <strong class="text-primary">${t.fare}</strong>
                            </div>
                        </div>

                        <div class="bus-cabin-preview p-4 bg-light rounded-4 border mx-auto" style="max-width: 320px;">
                            <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom text-muted small fw-bold">
                                <span><i class="bi bi-door-open me-1"></i> Entrance</span>
                                <span><i class="bi bi-steering-wheel me-1"></i> Driver</span>
                            </div>
                            ${seatsHtml}
                        </div>

                        ${passengerTableHtml}
                    `;
                })
                .catch(err => {
                    modalBody.innerHTML = `<div class="alert alert-danger py-2 small">Error contacting server. Please try again.</div>`;
                });
        });
    });
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
