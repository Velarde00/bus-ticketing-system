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
        'list' => "SELECT t.trip_id AS id, CONCAT(r.origin, ' to ', r.destination) AS route_name, b.bus_number, CONCAT(d.first_name, ' ', d.last_name) AS driver_name, t.departure_time, t.arrival_time, t.fare, t.status,
                  (SELECT COUNT(*) FROM booking_seats bs WHERE bs.trip_id = t.trip_id AND bs.seat_status = 'BOOKED') AS reserved_seats,
                  b.total_seats - (SELECT COUNT(*) FROM booking_seats bs WHERE bs.trip_id = t.trip_id AND bs.seat_status = 'BOOKED') AS available_seats
               FROM trips t JOIN routes r ON r.route_id = t.route_id JOIN buses b ON b.bus_id = t.bus_id JOIN drivers d ON d.driver_id = t.driver_id ORDER BY t.departure_time DESC",
        'columns' => ['Route' => 'route_name', 'Bus' => 'bus_number', 'Driver' => 'driver_name', 'Departure' => 'departure_time', 'Arrival' => 'arrival_time', 'Fare' => 'fare', 'Reserved seats' => 'reserved_seats', 'Available seats' => 'available_seats', 'Status' => 'status'],
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
                $validIds = $pdo->query($definition[2])->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array((int)$value, array_map('intval', $validIds), true)) {
                    $errors[] = 'Choose a valid ' . strtolower($label) . '.';
                } else {
                    $value = (int)$value;
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
                    $oldTripStatus = null;
                    if ($type === 'trips') {
                        $pdo->beginTransaction();
                        $oldStatusStmt = $pdo->prepare('SELECT status FROM trips WHERE trip_id = :id FOR UPDATE');
                        $oldStatusStmt->execute([':id' => $id]);
                        $oldTripStatus = $oldStatusStmt->fetchColumn();
                    }
                    $sets = [];
                    foreach (array_keys($values) as $name) {
                        $sets[] = $name . ' = :' . $name;
                    }
                    $values['id'] = $id;
                    $stmt = $pdo->prepare("UPDATE {$config['table']} SET " . implode(', ', $sets) . " WHERE {$config['key']} = :id");
                    $stmt->execute($values);
                    if ($type === 'trips' && $values['status'] === 'CANCELLED' && $oldTripStatus !== 'CANCELLED') {
                        $pdo->prepare("UPDATE bookings SET status = 'CANCELLED' WHERE trip_id = :id")
                            ->execute([':id' => $id]);
                        $pdo->prepare("UPDATE booking_seats SET seat_status = 'CANCELLED' WHERE trip_id = :id")
                            ->execute([':id' => $id]);
                        $pdo->prepare(
                            "UPDATE payments SET payment_status = 'CANCELLED', payment_date = NOW()
                             WHERE payment_status IN ('PENDING', 'FAILED')
                               AND booking_id IN (SELECT booking_id FROM bookings WHERE trip_id = :id)"
                        )->execute([':id' => $id]);
                    }
                    if ($type === 'trips') {
                        $pdo->commit();
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
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('admin catalog save: ' . $e->getMessage());
                $errors[] = 'Could not save this record. Check that unique values are not already in use and trip resources are available.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
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
    <div><h1 class="h3 mb-0"><?= e($config['title']) ?></h1><p class="text-muted mb-0">Add, update, and maintain your <?= e($type) ?>.</p></div>
    <a class="btn btn-primary" href="<?= url($basePath) ?>"><i class="bi bi-plus-lg"></i> New <?= e($config['singular']) ?></a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-4">
        <form method="post" class="panel p-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($record[$config['key']] ?? $editId ?? 0) ?>">
            <h2 class="h5 mb-3"><?= $record ? 'Edit record' : 'Add record' ?></h2>
            <?php foreach ($config['fields'] as $name => $definition): ?>
                <?php
                    $kind = $definition[1];
                    $value = $_POST[$name] ?? ($record[$name] ?? '');
                    if ($kind === 'datetime' && $value !== '') {
                        $value = str_replace(' ', 'T', substr((string)$value, 0, 16));
                    }
                ?>
                <div class="mb-3">
                    <label class="form-label" for="<?= e($name) ?>"><?= e($definition[0]) ?></label>
                    <?php if ($kind === 'select' || $kind === 'grouped_select' || $kind === 'options'): ?>
                        <?php
                            $isGrouped = $kind === 'grouped_select';
                            $optionGroups = $isGrouped ? $definition[2] : [
                                '' => ($kind === 'select' ? array_combine($definition[2], $definition[2]) : $pdo->query($definition[2])->fetchAll(PDO::FETCH_KEY_PAIR)),
                            ];
                        ?>
                        <select class="form-select" id="<?= e($name) ?>" name="<?= e($name) ?>" required>
                            <option value="">Choose...</option>
                            <?php foreach ($optionGroups as $group => $options): ?>
                                <?php if ($group !== ''): ?><optgroup label="<?= e($group) ?>"><?php endif; ?>
                                <?php foreach ($options as $optionValue => $label): ?>
                                    <?php if ($isGrouped && is_int($optionValue)): $optionValue = $label; endif; ?>
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
            <button type="submit" class="btn btn-primary"><?= $record ? 'Save changes' : 'Add record' ?></button>
            <?php if ($record): ?><a href="<?= url($basePath) ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
        </form>
    </div>
    <div class="col-xl-8">
        <div class="panel">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><?php foreach ($config['columns'] as $label => $_): ?><th><?= e($label) ?></th><?php endforeach; ?><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($config['columns'] as $field): ?>
                                <td><?= in_array($field, ['status'], true) ? status_badge((string)$row[$field]) : e($row[$field]) ?></td>
                            <?php endforeach; ?>
                            <td class="text-end text-nowrap">
                                <?php if ($type === 'trips'): ?><a class="btn btn-outline-dark btn-sm" href="<?= url('admin/trips/seats.php?trip_id=' . (int)$row['id']) ?>">Seats</a><?php endif; ?>
                                <a class="btn btn-outline-dark btn-sm" href="<?= url($basePath . '?id=' . (int)$row['id']) ?>">Edit</a>
                                <form method="post" class="d-inline" data-confirm="Delete this record? Records in use cannot be deleted.">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="<?= count($config['columns']) + 1 ?>" class="text-center text-muted py-4">No records yet. Add the first one using the form.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
