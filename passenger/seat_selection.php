<?php
/**
 * passenger/seat_selection.php
 * Shows the bus seat map for one trip. Passengers pick seats, then the form
 * is sent to actions/create_booking.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$tripId = filter_var($_GET['trip_id'] ?? null, FILTER_VALIDATE_INT);
if (!$tripId) {
    set_flash('warning', 'Choose a trip first.');
    redirect('index.php');
}

$stmt = $pdo->prepare(
    "SELECT t.trip_id, t.fare, t.departure_time, t.arrival_time, t.status,
            b.bus_number, b.bus_type, b.total_seats, b.status AS bus_status,
            r.origin, r.destination,
            CONCAT(d.first_name, ' ', d.last_name) AS driver_name
     FROM trips t
     JOIN buses   b ON b.bus_id    = t.bus_id
     JOIN routes  r ON r.route_id  = t.route_id
     JOIN drivers d ON d.driver_id = t.driver_id
     WHERE t.trip_id = :id"
);
$stmt->execute([':id' => $tripId]);
$trip = $stmt->fetch();

if (!$trip || $trip['status'] !== 'SCHEDULED' || $trip['bus_status'] !== 'ACTIVE' || strtotime($trip['departure_time']) <= time()) {
    set_flash('warning', 'That trip is not available for booking.');
    redirect('index.php');
}

// Seats already taken on this trip
$taken = $pdo->prepare("SELECT seat_number FROM booking_seats WHERE trip_id = :id AND seat_status = 'BOOKED'");
$taken->execute([':id' => $tripId]);
$takenSeats = array_map('intval', $taken->fetchAll(PDO::FETCH_COLUMN));

$total   = (int)$trip['total_seats'];
$rows    = (int)ceil($total / 4);
$freeNow = $total - count($takenSeats);

$pageTitle = 'Choose your seats';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <a href="javascript:history.back()" class="small"><i class="bi bi-arrow-left"></i> Back to results</a>
    <h1 class="h3 mt-2"><?= e($trip['origin']) ?> <i class="bi bi-arrow-right"></i> <?= e($trip['destination']) ?></h1>
    <p class="text-muted">
        <?= e(fmt_datetime($trip['departure_time'])) ?> &middot; Bus <?= e($trip['bus_number']) ?> (<?= e($trip['bus_type']) ?>)
        &middot; Driver <?= e($trip['driver_name']) ?> &middot; <?= money($trip['fare']) ?> per seat
    </p>

        <form method="post" action="<?= url('actions/create_booking.php') ?>" id="bookingForm"
            data-fare="<?= e($trip['fare']) ?>" data-max="<?= $freeNow ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="trip_id" value="<?= (int)$trip['trip_id'] ?>">
            <input type="hidden" name="payment_method" value="MANUAL_TRANSFER">

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="panel p-4">
                    <div class="seat-legend">
                        <span><i class="legend-box legend-free"></i> Available</span>
                        <span><i class="legend-box legend-selected"></i> Selected</span>
                        <span><i class="legend-box legend-taken"></i> Occupied</span>
                    </div>

                    <div class="bus-shell">
                        <div class="bus-front"><i class="bi bi-person-badge"></i> Driver</div>
                        <div class="seat-grid">
                            <?php for ($r = 0; $r < $rows; $r++): ?>
                                <?php for ($c = 0; $c < 4; $c++): ?>
                                    <?php
                                    $n = $r * 4 + $c + 1;
                                    if ($c === 2) {
                                        echo '<div class="seat-aisle"></div>';
                                    }
                                    if ($n > $total) {
                                        echo '<div></div>';
                                        continue;
                                    }
                                    $isTaken = in_array($n, $takenSeats, true);
                                    ?>
                                    <div class="seat-cell">
                                        <input type="checkbox" class="seat-input" name="seats[]" value="<?= $n ?>" id="seat-<?= $n ?>" <?= $isTaken ? 'disabled' : '' ?>>
                                        <label class="seat" for="seat-<?= $n ?>" title="<?= $isTaken ? 'Seat ' . $n . ' is occupied' : 'Seat ' . $n ?>"><?= $n ?></label>
                                    </div>
                                <?php endfor; ?>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="panel p-4 summary-box">
                    <h2 class="h5">Your reservation</h2>
                    <dl class="summary-list">
                        <dt>Seats left</dt><dd><?= $freeNow ?> of <?= $total ?></dd>
                        <dt>Selected seats</dt><dd id="selectedSeats">None yet</dd>
                        <dt>Fare per seat</dt><dd><?= money($trip['fare']) ?></dd>
                        <dt>Total</dt><dd class="summary-total" id="totalFare">₱0.00</dd>
                    </dl>

                    <fieldset class="mb-3">
                        <legend class="form-label">Choose a transfer method</legend>
                        <div class="payment-option-list">
                            <label class="form-check payment-option">
                                <input class="form-check-input payment-option-input" type="radio" name="online_payment_option" value="GCASH" required>
                                <span><strong>GCash</strong><small class="d-block text-muted">Pay through GCash</small></span>
                            </label>
                            <label class="form-check payment-option">
                                <input class="form-check-input payment-option-input" type="radio" name="online_payment_option" value="MAYA">
                                <span><strong>Maya</strong><small class="d-block text-muted">Pay through Maya</small></span>
                            </label>
                            <label class="form-check payment-option">
                                <input class="form-check-input payment-option-input" type="radio" name="online_payment_option" value="MARIBANK">
                                <span><strong>MariBank</strong><small class="d-block text-muted">Transfer to the configured MariBank account</small></span>
                            </label>
                            <label class="form-check payment-option">
                                <input class="form-check-input payment-option-input" type="radio" name="online_payment_option" value="BANK_TRANSFER">
                                <span><strong>Online bank transfer</strong><small class="d-block text-muted">BPI or UnionBank</small></span>
                            </label>
                        </div>
                    </fieldset>

                    <div class="alert alert-info"><i class="bi bi-shield-check" aria-hidden="true"></i> Reserve your seats, transfer the fare using your selected method, then submit the transfer reference for verification.</div>

                    <button type="submit" class="btn btn-primary w-100" id="confirmBtn" disabled>Reserve selected seats</button>
                    <p class="small text-muted mt-2 mb-0">Select one or more available seats. They’ll be included in this reservation.</p>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    var form  = document.getElementById('bookingForm');
    var fare  = parseFloat(form.dataset.fare);
    var max   = parseInt(form.dataset.max, 10);
    var boxes = form.querySelectorAll('.seat-input');
    var paymentOptions = form.querySelectorAll('.payment-option-input');
    var list  = document.getElementById('selectedSeats');
    var total = document.getElementById('totalFare');
    var btn   = document.getElementById('confirmBtn');

    function update() {
        var picked = [];
        boxes.forEach(function (b) { if (b.checked) { picked.push(parseInt(b.value, 10)); } });
        picked.sort(function (a, b) { return a - b; });
        list.textContent = picked.length ? picked.join(', ') : 'None yet';
        total.textContent = '₱' + (picked.length * fare).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        var methodPicked = Array.from(paymentOptions).some(function (option) { return option.checked; });
        btn.disabled = picked.length === 0 || !methodPicked;
    }

    boxes.forEach(function (b) {
        b.addEventListener('change', function () {
            if (form.querySelectorAll('.seat-input:checked').length > max) {
                b.checked = false;
                alert('You can book up to ' + max + ' seats at a time.');
            }
            update();
        });
    });
    paymentOptions.forEach(function (option) {
        option.addEventListener('change', update);
    });
    update();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
