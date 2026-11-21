<?php
/**
 * admin/reservations/walk_in.php
 * Staff / Admin walk-in booking form.
 * Lets admin select a trip, pick seats, choose passenger type (with 20% discount),
 * and collect payment in CASH, CARD, or E-Wallet — all in one screen.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

/* ── 1. Load available scheduled trips ─────────────────────────────────── */
$trips = $pdo->query(
    "SELECT t.trip_id, t.fare, t.departure_time, t.arrival_time,
            r.origin, r.destination,
            b.bus_number, b.bus_type, b.total_seats
     FROM trips t
     JOIN routes r ON r.route_id = t.route_id
     JOIN buses  b ON b.bus_id   = t.bus_id
     WHERE t.status = 'SCHEDULED' AND b.status = 'ACTIVE'
       AND t.departure_time > NOW()
     ORDER BY t.departure_time ASC"
)->fetchAll();

/* ── 2. If a trip is selected, load its seats ──────────────────────────── */
$tripId   = filter_var($_GET['trip_id'] ?? null, FILTER_VALIDATE_INT);
$tripData = null;
$takenSeats = [];

if ($tripId) {
    foreach ($trips as $t) {
        if ((int)$t['trip_id'] === $tripId) {
            $tripData = $t;
            break;
        }
    }
    if ($tripData) {
        $taken = $pdo->prepare("SELECT seat_number FROM booking_seats WHERE trip_id = :id AND seat_status = 'BOOKED'");
        $taken->execute([':id' => $tripId]);
        $takenSeats = array_map('intval', $taken->fetchAll(PDO::FETCH_COLUMN));
    }
}

/* ── 3. Load registered passengers for autocomplete ───────────────────── */
$passengers = $pdo->query(
    "SELECT user_id, CONCAT(first_name, ' ', last_name) AS full_name, email, phone_number
     FROM users WHERE role = 'PASSENGER' ORDER BY first_name, last_name"
)->fetchAll();

$pageTitle  = 'Walk-in Booking';
$layout     = 'admin';
$activePage = 'reservations';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <a href="<?= url('admin/reservations/index.php') ?>" class="text-muted text-decoration-none small d-inline-flex align-items-center gap-1 mb-1">
            <i class="bi bi-arrow-left"></i> Back to Reservations
        </a>
        <h1 class="h3 fw-bold mb-0 text-dark"><i class="bi bi-person-walking me-2 text-primary"></i>Walk-in Booking</h1>
        <p class="text-muted small mb-0 mt-1">Create a reservation for a passenger at the terminal counter. You can collect payment immediately.</p>
    </div>
    <div class="d-flex gap-2">
        <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill">
            <i class="bi bi-cash-stack me-1"></i> Cash Accepted
        </span>
        <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-3 py-2 rounded-pill">
            <i class="bi bi-credit-card me-1"></i> Card Accepted
        </span>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill">
            <i class="bi bi-phone me-1"></i> E-Wallet Accepted
        </span>
    </div>
</div>

<!-- Step 1: Select Trip -->
<div class="panel bg-white rounded-4 border shadow-sm mb-4 overflow-hidden">
    <div class="panel-head p-3 px-4 border-bottom bg-light bg-opacity-50 d-flex align-items-center gap-2">
        <span class="badge bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:28px;height:28px;font-size:0.85rem;">1</span>
        <h2 class="h5 fw-bold mb-0">Select Trip</h2>
    </div>
    <div class="p-4">
        <form method="get" action="<?= url('admin/reservations/walk_in.php') ?>" class="row g-3 align-items-end">
            <div class="col-md-9">
                <label class="form-label small fw-semibold">Available Scheduled Trips</label>
                <select name="trip_id" class="form-select" onchange="this.form.submit()">
                    <option value="">— Select a trip to continue —</option>
                    <?php foreach ($trips as $t): ?>
                        <option value="<?= (int)$t['trip_id'] ?>" <?= $tripId === (int)$t['trip_id'] ? 'selected' : '' ?>>
                            <?= e($t['origin']) ?> → <?= e($t['destination']) ?>
                            | <?= e(date('D, d M Y · H:i', strtotime($t['departure_time']))) ?>
                            | Bus <?= e($t['bus_number']) ?> (<?= e($t['bus_type']) ?>)
                            | <?= money($t['fare']) ?>/seat
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary w-100 rounded-pill">
                    <i class="bi bi-arrow-right-circle me-1"></i> Load Seats
                </button>
            </div>
        </form>
        <?php if (!$trips): ?>
            <div class="alert alert-warning border-0 mt-3 py-2 small">
                <i class="bi bi-exclamation-triangle me-1"></i> No scheduled trips available right now. Add a trip first.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($tripData): ?>
<?php
    $totalSeats = (int)$tripData['total_seats'];
    $rows       = (int)ceil($totalSeats / 4);
    $freeCount  = $totalSeats - count($takenSeats);
?>

<!-- Steps 2–4: main booking form -->
<form method="post" action="<?= url('actions/admin_walk_in_booking.php') ?>" id="walkInForm"
      data-fare="<?= e($tripData['fare']) ?>" data-max="10">
    <?= csrf_field() ?>
    <input type="hidden" name="trip_id" value="<?= (int)$tripId ?>">

    <div class="row g-4">
        <!-- Left column: Seat Map -->
        <div class="col-lg-7">
            <div class="panel bg-white rounded-4 border shadow-sm overflow-hidden mb-4">
                <div class="panel-head p-3 px-4 border-bottom bg-light bg-opacity-50 d-flex align-items-center gap-2">
                    <span class="badge bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:28px;height:28px;font-size:0.85rem;">2</span>
                    <h2 class="h5 fw-bold mb-0">Select Seats</h2>
                    <span class="ms-auto badge bg-light text-muted border"><?= $freeCount ?> / <?= $totalSeats ?> available</span>
                </div>
                <div class="p-4">
                    <!-- Legend -->
                    <div class="seat-legend-modern mb-4 p-2 bg-light rounded-pill d-flex justify-content-center gap-3 gap-md-4">
                        <div class="d-flex align-items-center gap-2 small"><span class="legend-seat-sample legend-free"></span><span>Available</span></div>
                        <div class="d-flex align-items-center gap-2 small"><span class="legend-seat-sample legend-selected"></span><span>Selected</span></div>
                        <div class="d-flex align-items-center gap-2 small"><span class="legend-seat-sample legend-taken"></span><span>Occupied</span></div>
                    </div>

                    <!-- Bus Shell -->
                    <div class="bus-exterior-shell mx-auto">
                        <div class="bus-front-cabin">
                            <div class="windshield-glass"></div>
                            <div class="d-flex justify-content-between align-items-center px-3 pt-2 text-muted small">
                                <div class="d-flex align-items-center gap-1 driver-badge">
                                    <i class="bi bi-person-circle fs-5 text-dark"></i>
                                    <span class="fw-semibold">Driver</span>
                                </div>
                                <div class="bus-door-indicator"><i class="bi bi-box-arrow-in-left"></i> Entrance Door</div>
                            </div>
                        </div>
                        <div class="bus-interior-aisle py-3">
                            <div class="seat-grid-container">
                                <?php for ($r = 0; $r < $rows; $r++): ?>
                                    <div class="seat-row-pair d-flex justify-content-center align-items-center gap-2 mb-2">
                                        <div class="d-flex gap-2">
                                            <?php for ($c = 0; $c < 2; $c++): ?>
                                                <?php $n = $r * 4 + $c + 1; if ($n > $totalSeats): ?>
                                                    <div class="seat-placeholder"></div>
                                                <?php else: $isTaken = in_array($n, $takenSeats, true); ?>
                                                    <div class="seat-cell position-relative">
                                                        <input type="checkbox" class="seat-input" name="seats[]" value="<?= $n ?>" id="wi-seat-<?= $n ?>" <?= $isTaken ? 'disabled' : '' ?>>
                                                        <label class="seat-cushion <?= $isTaken ? 'seat-occupied' : '' ?>" for="wi-seat-<?= $n ?>" title="<?= $isTaken ? 'Seat ' . $n . ' is taken' : 'Seat ' . $n ?>">
                                                            <span class="seat-headrest"></span>
                                                            <span class="seat-num"><?= $n ?></span>
                                                        </label>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                        </div>
                                        <div class="bus-aisle-divider"><span class="aisle-num"><?= $r + 1 ?></span></div>
                                        <div class="d-flex gap-2">
                                            <?php for ($c = 2; $c < 4; $c++): ?>
                                                <?php $n = $r * 4 + $c + 1; if ($n > $totalSeats): ?>
                                                    <div class="seat-placeholder"></div>
                                                <?php else: $isTaken = in_array($n, $takenSeats, true); ?>
                                                    <div class="seat-cell position-relative">
                                                        <input type="checkbox" class="seat-input" name="seats[]" value="<?= $n ?>" id="wi-seat-<?= $n ?>" <?= $isTaken ? 'disabled' : '' ?>>
                                                        <label class="seat-cushion <?= $isTaken ? 'seat-occupied' : '' ?>" for="wi-seat-<?= $n ?>" title="<?= $isTaken ? 'Seat ' . $n . ' is taken' : 'Seat ' . $n ?>">
                                                            <span class="seat-headrest"></span>
                                                            <span class="seat-num"><?= $n ?></span>
                                                        </label>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="bus-rear-bumper text-center text-muted small py-2 border-top">
                            <i class="bi bi-shield-fill-check me-1 text-success"></i> Rear Safety Exit
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 3: Passenger Details -->
            <div class="panel bg-white rounded-4 border shadow-sm overflow-hidden mb-4">
                <div class="panel-head p-3 px-4 border-bottom bg-light bg-opacity-50 d-flex align-items-center gap-2">
                    <span class="badge bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:28px;height:28px;font-size:0.85rem;">3</span>
                    <h2 class="h5 fw-bold mb-0">Passenger Information</h2>
                </div>
                <div class="p-4">
                    <!-- Toggle: existing vs guest -->
                    <div class="d-flex gap-2 mb-4">
                        <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm" id="btnExisting" onclick="togglePassengerMode('existing')">
                            <i class="bi bi-person-check me-1"></i> Registered Passenger
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" id="btnGuest" onclick="togglePassengerMode('guest')">
                            <i class="bi bi-person-plus me-1"></i> New Walk-in Guest
                        </button>
                    </div>

                    <!-- Existing passenger lookup -->
                    <div id="modeExisting">
                        <label class="form-label small fw-semibold">Search Registered Passenger</label>
                        <select name="user_id" id="passengerSelect" class="form-select">
                            <option value="">— Search by name or email —</option>
                            <?php foreach ($passengers as $p): ?>
                                <option value="<?= (int)$p['user_id'] ?>"
                                        data-email="<?= e($p['email']) ?>"
                                        data-phone="<?= e($p['phone_number']) ?>">
                                    <?= e($p['full_name']) ?> (<?= e($p['email']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="passengerInfo" class="mt-2 p-2 rounded-3 bg-light border small text-muted" style="display:none;"></div>
                    </div>

                    <!-- Guest details -->
                    <div id="modeGuest" style="display:none;">
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="guest_first" class="form-control" placeholder="e.g. Juan" maxlength="60">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="guest_last" class="form-control" placeholder="e.g. dela Cruz" maxlength="60">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small fw-semibold">Mobile Number</label>
                                <input type="text" name="guest_phone" class="form-control" placeholder="09xxxxxxxxx" maxlength="30">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small fw-semibold">Email <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="email" name="guest_email" class="form-control" placeholder="email@example.com" maxlength="120">
                            </div>
                        </div>
                    </div>

                    <!-- Per-seat passenger type (dynamic, rendered by JS) -->
                    <div id="wiPassengerDetailsSection" class="mt-4" style="display:none;">
                        <hr class="my-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="form-label mb-0 small fw-semibold text-dark">Passenger Type per Seat</span>
                            <span class="badge bg-success-subtle text-success border border-success-subtle small">20% discount for Student / Senior / PWD</span>
                        </div>
                        <div id="wiPassengerDetailsRows" class="d-flex flex-column gap-2"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right column: Summary + Payment -->
        <div class="col-lg-5">
            <div class="panel bg-white rounded-4 border shadow-sm p-4 sticky-top" style="top: 1.5rem; z-index: 10;">
                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-3">
                    <h2 class="h5 fw-bold mb-0">Booking Summary</h2>
                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Walk-in</span>
                </div>

                <!-- Trip card -->
                <div class="bg-light p-3 rounded-3 mb-3 border">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fw-bold text-dark"><?= e($tripData['origin']) ?> &rarr; <?= e($tripData['destination']) ?></span>
                        <span class="badge bg-primary text-white"><?= e($tripData['bus_type']) ?></span>
                    </div>
                    <div class="text-primary fw-bold small mt-2">
                        <i class="bi bi-calendar3 me-1"></i> <?= e(date('l, F j, Y', strtotime($tripData['departure_time']))) ?>
                    </div>
                    <div class="fw-bold fs-5 text-dark mt-1">
                        <i class="bi bi-clock-fill text-warning me-1"></i> <?= e(date('h:i A', strtotime($tripData['departure_time']))) ?>
                        <span class="text-muted fw-normal fs-6">&bull; Arrival: <?= e(date('h:i A', strtotime($tripData['arrival_time']))) ?></span>
                    </div>
                    <div class="text-muted small mt-1">Bus #<?= e($tripData['bus_number']) ?></div>
                </div>

                <!-- Selected seats chips -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="form-label mb-0 small text-muted">Selected Seats:</span>
                        <span class="badge bg-light text-muted border" id="wiSeatCountBadge">0 seats</span>
                    </div>
                    <div id="wiSeatChips" class="d-flex flex-wrap gap-1 p-2 bg-light rounded-3 border" style="min-height:40px;">
                        <span class="text-muted small my-auto fst-italic" id="wiNoSeats">Click seats on the map to select.</span>
                    </div>
                </div>

                <!-- Price breakdown -->
                <div class="price-breakdown-box py-3 border-top border-bottom mb-3">
                    <div class="d-flex justify-content-between text-muted small mb-1">
                        <span>Base fare per seat</span>
                        <span><?= money($tripData['fare']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-muted small mb-2">
                        <span>Seats selected</span>
                        <span id="wiSeatCount">0</span>
                    </div>
                    <div class="d-flex justify-content-between text-muted small mb-2" id="wiDiscountRow" style="display:none!important;">
                        <span class="text-success"><i class="bi bi-tag-fill me-1"></i>Discount (20%)</span>
                        <span class="text-success fw-semibold" id="wiDiscountAmt">−₱0.00</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-baseline pt-2 border-top">
                        <span class="fw-bold text-dark">Total Amount</span>
                        <span class="fw-bold fs-4 text-primary" id="wiTotalFare">₱0.00</span>
                    </div>
                </div>

                <!-- Step 4: Payment -->
                <div class="panel-head border-bottom pb-2 mb-3 d-flex align-items-center gap-2">
                    <span class="badge bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:24px;height:24px;font-size:0.8rem;">4</span>
                    <span class="fw-semibold small">Payment Details</span>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold mb-2">Payment Method</label>
                    <div class="d-flex flex-column gap-2">
                        <label class="payment-method-card d-flex align-items-center p-2 rounded-3 border">
                            <input type="radio" name="payment_method" value="CASH" checked class="form-check-input me-3">
                            <span class="payment-icon bg-success-subtle text-success me-2"><i class="bi bi-cash-stack"></i></span>
                            <div>
                                <div class="fw-semibold small">Cash</div>
                                <div class="text-muted" style="font-size:0.72rem;">Collected at the counter</div>
                            </div>
                        </label>
                        <label class="payment-method-card d-flex align-items-center p-2 rounded-3 border">
                            <input type="radio" name="payment_method" value="CARD" class="form-check-input me-3">
                            <span class="payment-icon bg-warning-subtle text-warning me-2"><i class="bi bi-credit-card"></i></span>
                            <div>
                                <div class="fw-semibold small">Debit / Credit Card</div>
                                <div class="text-muted" style="font-size:0.72rem;">Swipe or tap at POS terminal</div>
                            </div>
                        </label>
                        <label class="payment-method-card d-flex align-items-center p-2 rounded-3 border">
                            <input type="radio" name="payment_method" value="E_WALLET" class="form-check-input me-3">
                            <span class="payment-icon bg-primary-subtle text-primary me-2"><i class="bi bi-phone"></i></span>
                            <div>
                                <div class="fw-semibold small">E-Wallet (GCash / Maya)</div>
                                <div class="text-muted" style="font-size:0.72rem;">Show QR code or ref number</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold mb-2">Payment Status</label>
                    <div class="p-3 bg-success-subtle text-success border border-success-subtle rounded-3 d-flex align-items-center gap-2">
                        <i class="bi bi-check-circle-fill fs-5"></i>
                        <div>
                            <div class="fw-semibold small">Automatic Status: Paid &amp; Confirmed</div>
                            <div class="text-success-emphasis" style="font-size: 0.72rem;">Payment is recorded as paid immediately. Tickets and boarding passes are automatically generated.</div>
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill shadow-sm d-flex align-items-center justify-content-center gap-2" id="wiConfirmBtn" disabled>
                    <i class="bi bi-check-circle-fill"></i>
                    <span id="wiConfirmText">Select Seats to Continue</span>
                </button>
                <p class="small text-muted text-center mt-3 mb-0" style="font-size:0.8rem;">
                    <i class="bi bi-info-circle me-1"></i> Walk-in references start with <strong>WI-</strong>. Online bookings start with <strong>BT-</strong>.
                </p>
            </div>
        </div>
    </div><!-- /row -->
</form>

<script>
(function () {
    var form      = document.getElementById('walkInForm');
    var fare      = parseFloat(form.dataset.fare);
    var max       = parseInt(form.dataset.max, 10);
    var boxes     = form.querySelectorAll('.seat-input');
    var chips     = document.getElementById('wiSeatChips');
    var noSeats   = document.getElementById('wiNoSeats');
    var badge     = document.getElementById('wiSeatCountBadge');
    var cntEl     = document.getElementById('wiSeatCount');
    var totalEl   = document.getElementById('wiTotalFare');
    var discRow   = document.getElementById('wiDiscountRow');
    var discAmt   = document.getElementById('wiDiscountAmt');
    var btn       = document.getElementById('wiConfirmBtn');
    var btnTxt    = document.getElementById('wiConfirmText');
    var detSec    = document.getElementById('wiPassengerDetailsSection');
    var detRows   = document.getElementById('wiPassengerDetailsRows');

    // Track passenger type per seat (populated from selects)
    var seatTypes = {};

    function fmt(val) {
        return '₱' + val.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function recalc(picked) {
        var total = 0, discount = 0;
        picked.forEach(function (sn) {
            var pType = seatTypes[sn] || 'REGULAR';
            var seatFare = (pType === 'REGULAR') ? fare : fare * 0.80;
            if (pType !== 'REGULAR') discount += fare * 0.20;
            total += seatFare;
        });
        totalEl.textContent = fmt(total);
        cntEl.textContent   = picked.length;
        if (discount > 0) {
            discRow.style.setProperty('display', 'flex', 'important');
            discAmt.textContent = '−' + fmt(discount);
        } else {
            discRow.style.setProperty('display', 'none', 'important');
        }
        btn.disabled = picked.length === 0;
        if (picked.length > 0) {
            btnTxt.textContent = 'Create Walk-in Booking • ' + fmt(total);
        } else {
            btnTxt.textContent = 'Select Seats to Continue';
        }
    }

    function renderPassengerRows(picked) {
        detRows.innerHTML = '';
        if (picked.length === 0) {
            detSec.style.display = 'none';
            return;
        }
        detSec.style.display = 'block';

        picked.forEach(function (sn) {
            var row = document.createElement('div');
            row.className = 'row g-2 align-items-center p-2 bg-light rounded-3 border';

            var label = document.createElement('div');
            label.className = 'col-auto';
            label.innerHTML = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill fw-bold">Seat ' + sn + '</span>';

            var selWrap = document.createElement('div');
            selWrap.className = 'col-sm-5';
            var sel = document.createElement('select');
            sel.className = 'form-select form-select-sm';
            sel.name = 'passenger_type[' + sn + ']';
            [
                ['REGULAR', 'Regular'],
                ['STUDENT', 'Student (20% off)'],
                ['SENIOR',  'Senior Citizen (20% off)'],
                ['PWD',     'PWD (20% off)']
            ].forEach(function (opt) {
                var o = document.createElement('option');
                o.value = opt[0];
                o.textContent = opt[1];
                if ((seatTypes[sn] || 'REGULAR') === opt[0]) o.selected = true;
                sel.appendChild(o);
            });
            sel.addEventListener('change', function () {
                seatTypes[sn] = sel.value;
                // Show/hide ID field
                var idWrap = row.querySelector('.id-wrap');
                if (sel.value !== 'REGULAR') {
                    idWrap.style.display = '';
                    idWrap.querySelector('input').required = true;
                } else {
                    idWrap.style.display = 'none';
                    idWrap.querySelector('input').required = false;
                    idWrap.querySelector('input').value = '';
                }
                var picked = getChecked();
                recalc(picked);
            });
            selWrap.appendChild(sel);

            var idWrap = document.createElement('div');
            idWrap.className = 'col id-wrap';
            idWrap.style.display = (seatTypes[sn] && seatTypes[sn] !== 'REGULAR') ? '' : 'none';
            var idInput = document.createElement('input');
            idInput.type = 'text';
            idInput.className = 'form-control form-control-sm';
            idInput.name = 'id_number[' + sn + ']';
            idInput.placeholder = 'ID number';
            idInput.maxLength = 50;
            idWrap.appendChild(idInput);

            row.appendChild(label);
            row.appendChild(selWrap);
            row.appendChild(idWrap);
            detRows.appendChild(row);
        });
    }

    function getChecked() {
        var picked = [];
        boxes.forEach(function (b) { if (b.checked) picked.push(parseInt(b.value, 10)); });
        picked.sort(function (a, b) { return a - b; });
        return picked;
    }

    function update() {
        var picked = getChecked();

        // Chips
        chips.innerHTML = '';
        badge.textContent = picked.length + (picked.length === 1 ? ' seat' : ' seats');
        if (picked.length === 0) {
            chips.appendChild(noSeats);
            noSeats.style.display = 'block';
        } else {
            noSeats.style.display = 'none';
            picked.forEach(function (sn) {
                var chip = document.createElement('span');
                chip.className = 'seat-selected-chip badge bg-primary text-white p-2 px-2 d-inline-flex align-items-center gap-1 rounded-pill';
                chip.innerHTML = '<i class="bi bi-check-circle-fill" style="font-size:0.75rem"></i> Seat ' + sn +
                    ' <i class="bi bi-x-circle-fill cursor-pointer opacity-75 ms-1" title="Remove"></i>';
                chip.querySelector('.bi-x-circle-fill').addEventListener('click', function () {
                    var inp = document.getElementById('wi-seat-' + sn);
                    if (inp) { inp.checked = false; delete seatTypes[sn]; update(); }
                });
                chips.appendChild(chip);
            });
        }

        // Clean up seatTypes for removed seats
        Object.keys(seatTypes).forEach(function (k) {
            if (picked.indexOf(parseInt(k, 10)) === -1) delete seatTypes[k];
        });

        renderPassengerRows(picked);
        recalc(picked);
    }

    boxes.forEach(function (b) {
        b.addEventListener('change', function () {
            if (form.querySelectorAll('.seat-input:checked').length > max) {
                b.checked = false;
                alert('Walk-in bookings are limited to ' + max + ' seats.');
            }
            update();
        });
    });

    update();
})();

/* Passenger mode toggle */
function togglePassengerMode(mode) {
    var ex = document.getElementById('modeExisting');
    var gs = document.getElementById('modeGuest');
    var btnEx = document.getElementById('btnExisting');
    var btnGs = document.getElementById('btnGuest');
    var sel = document.getElementById('passengerSelect');

    if (mode === 'existing') {
        ex.style.display = '';
        gs.style.display = 'none';
        btnEx.classList.replace('btn-outline-secondary', 'btn-primary');
        btnEx.classList.add('shadow-sm');
        btnGs.classList.replace('btn-primary', 'btn-outline-secondary');
        btnGs.classList.remove('shadow-sm');
        sel.name = 'user_id';
        document.querySelectorAll('[name="guest_first"],[name="guest_last"],[name="guest_phone"],[name="guest_email"]')
            .forEach(function (el) { el.name = '_' + el.name; });
    } else {
        ex.style.display = 'none';
        gs.style.display = '';
        btnGs.classList.replace('btn-outline-secondary', 'btn-primary');
        btnGs.classList.add('shadow-sm');
        btnEx.classList.replace('btn-primary', 'btn-outline-secondary');
        btnEx.classList.remove('shadow-sm');
        sel.name = '_user_id';
        document.querySelectorAll('[name="_guest_first"],[name="_guest_last"],[name="_guest_phone"],[name="_guest_email"]')
            .forEach(function (el) { el.name = el.name.substring(1); });
    }
}

/* Passenger info preview */
document.getElementById('passengerSelect').addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    var info = document.getElementById('passengerInfo');
    if (this.value) {
        info.style.display = '';
        info.innerHTML = '<i class="bi bi-person-check text-success me-1"></i>'
            + '<strong>' + opt.text.split(' (')[0] + '</strong>'
            + ' &bull; ' + opt.dataset.email
            + (opt.dataset.phone ? ' &bull; ' + opt.dataset.phone : '');
    } else {
        info.style.display = 'none';
    }
});
</script>

<?php else: ?>
    <?php if ($tripId && !$tripData): ?>
        <div class="alert alert-warning border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> That trip was not found or is no longer available. Please select another.
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
