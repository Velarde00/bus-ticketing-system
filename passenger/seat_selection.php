<?php
/**
 * passenger/seat_selection.php
 * Shows the bus seat map for one trip. Passengers pick seats, then the form
 * is sent to actions/create_booking.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

const MAX_SEATS_PER_BOOKING = 6;

$tripId = filter_var($_GET['trip_id'] ?? null, FILTER_VALIDATE_INT);
if (!$tripId) {
    set_flash('warning', 'Choose a trip first.');
    redirect('index.php');
}

$stmt = $pdo->prepare(
    "SELECT t.trip_id, t.fare, t.departure_time, t.arrival_time, t.status,
            b.bus_number, b.bus_type, b.total_seats, b.status AS bus_status,
            r.origin, r.destination, r.distance_km,
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
    <!-- Navigation back & Trip title -->
    <div class="mb-4">
        <a href="javascript:history.back()" class="text-muted text-decoration-none small d-inline-flex align-items-center gap-1 mb-2">
            <i class="bi bi-arrow-left"></i> Back to search results
        </a>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h1 class="h3 fw-bold mb-1 d-flex align-items-center gap-2">
                    <span><?= e($trip['origin']) ?></span>
                    <i class="bi bi-arrow-right text-primary opacity-75"></i>
                    <span><?= e($trip['destination']) ?></span>
                </h1>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill fw-semibold">
                        <i class="bi bi-calendar-event me-1"></i> Departure Date: <?= e(fmt_date($trip['departure_time'])) ?>
                    </span>
                    <span class="badge bg-warning-subtle text-dark border border-warning-subtle px-3 py-2 rounded-pill fw-semibold">
                        <i class="bi bi-clock-fill text-warning me-1"></i> Departure Time: <?= e(fmt_time($trip['departure_time'])) ?>
                    </span>
                    <span class="badge bg-light text-muted border px-3 py-2 rounded-pill small">
                        <i class="bi bi-bus-front me-1 text-primary"></i> Bus <?= e($trip['bus_number']) ?> (<?= e($trip['bus_type']) ?>)
                    </span>
                    <span class="badge bg-light text-muted border px-3 py-2 rounded-pill small">
                        <i class="bi bi-person-badge me-1 text-primary"></i> Driver: <?= e($trip['driver_name']) ?>
                    </span>
                </div>
            </div>
            <div class="text-end">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill fs-6 d-inline-block">
                    <?= money($trip['fare']) ?> <span class="fw-normal small text-muted">/ regular seat</span>
                </span>
                <div class="mt-1">
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill small" title="Mandated 20% discount for Students, Senior Citizens, and PWDs">
                        <i class="bi bi-tag-fill me-1"></i> Discounted: <?= money($trip['fare'] * 0.80) ?> (20% off)
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- 20% Discount Notice Banner for Online Bookers -->
    <div class="alert alert-success border-0 shadow-sm rounded-4 p-3 mb-4 d-flex align-items-center gap-3">
        <div class="p-2 bg-success text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
            <i class="bi bi-percent fs-5"></i>
        </div>
        <div class="flex-grow-1">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <strong class="text-success-emphasis">20% Special Discount Available!</strong>
                <span class="badge bg-success text-white rounded-pill small">Philippine Law Mandated</span>
            </div>
            <div class="small text-muted mt-1">
                Eligible <strong>Students</strong> (with valid School ID), <strong>Senior Citizens</strong> (OSCA / Gov ID), and <strong>Persons with Disability (PWD)</strong> receive a <strong>20% discount</strong> (pay only <strong><?= money($trip['fare'] * 0.80) ?></strong> per seat). Simply select the passenger type below after picking your seats.
            </div>
        </div>
    </div>

    <form method="post" action="<?= url('actions/create_booking.php') ?>" id="bookingForm"
          data-fare="<?= e($trip['fare']) ?>" data-max="<?= MAX_SEATS_PER_BOOKING ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="trip_id" value="<?= (int)$trip['trip_id'] ?>">

        <div class="row g-4">
            <!-- Left: Interactive Bus Seat Layout -->
            <div class="col-lg-7">
                <div class="panel p-4 shadow-sm bg-white rounded-4 border">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 fw-bold mb-0">Select Your Seats</h2>
                        <span class="text-muted small">Max <?= MAX_SEATS_PER_BOOKING ?> seats per booking</span>
                    </div>

                    <!-- Visual Legend -->
                    <div class="seat-legend-modern mb-4 p-2 bg-light rounded-pill d-flex justify-content-center gap-3 gap-md-4">
                        <div class="d-flex align-items-center gap-2 small">
                            <span class="legend-seat-sample legend-free"></span>
                            <span>Available</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 small">
                            <span class="legend-seat-sample legend-selected"></span>
                            <span>Selected</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 small">
                            <span class="legend-seat-sample legend-taken"></span>
                            <span>Occupied</span>
                        </div>
                    </div>

                    <!-- Bus Cabin Container -->
                    <div class="bus-exterior-shell mx-auto">
                        <!-- Front of Bus with Windshield Glass -->
                        <div class="bus-front-cabin">
                            <div class="windshield-glass"></div>
                            <div class="d-flex justify-content-between align-items-center px-3 pt-2 text-muted small">
                                <div class="d-flex align-items-center gap-1 driver-badge">
                                    <i class="bi bi-person-circle fs-5 text-dark"></i>
                                    <span class="fw-semibold">Driver</span>
                                </div>
                                <div class="bus-door-indicator">
                                    <i class="bi bi-box-arrow-in-left"></i> Entrance Door
                                </div>
                            </div>
                        </div>

                        <!-- Seat Grid with Aisle -->
                        <div class="bus-interior-aisle py-3">
                            <div class="seat-grid-container">
                                <?php for ($r = 0; $r < $rows; $r++): ?>
                                    <div class="seat-row-pair d-flex justify-content-center align-items-center gap-2 mb-2">
                                        <!-- Left pair (Seats A & B) -->
                                        <div class="d-flex gap-2">
                                            <?php for ($c = 0; $c < 2; $c++): ?>
                                                <?php
                                                $n = $r * 4 + $c + 1;
                                                if ($n > $total):
                                                ?>
                                                    <div class="seat-placeholder"></div>
                                                <?php else: ?>
                                                    <?php $isTaken = in_array($n, $takenSeats, true); ?>
                                                    <div class="seat-cell position-relative">
                                                        <input type="checkbox" class="seat-input" name="seats[]" value="<?= $n ?>" id="seat-<?= $n ?>" <?= $isTaken ? 'disabled' : '' ?>>
                                                        <label class="seat-cushion <?= $isTaken ? 'seat-occupied' : '' ?>" for="seat-<?= $n ?>" title="<?= $isTaken ? 'Seat ' . $n . ' is taken' : 'Seat ' . $n ?>">
                                                            <span class="seat-headrest"></span>
                                                            <span class="seat-num"><?= $n ?></span>
                                                        </label>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                        </div>

                                        <!-- Center Aisle Divider -->
                                        <div class="bus-aisle-divider">
                                            <span class="aisle-num"><?= $r + 1 ?></span>
                                        </div>

                                        <!-- Right pair (Seats C & D) -->
                                        <div class="d-flex gap-2">
                                            <?php for ($c = 2; $c < 4; $c++): ?>
                                                <?php
                                                $n = $r * 4 + $c + 1;
                                                if ($n > $total):
                                                ?>
                                                    <div class="seat-placeholder"></div>
                                                <?php else: ?>
                                                    <?php $isTaken = in_array($n, $takenSeats, true); ?>
                                                    <div class="seat-cell position-relative">
                                                        <input type="checkbox" class="seat-input" name="seats[]" value="<?= $n ?>" id="seat-<?= $n ?>" <?= $isTaken ? 'disabled' : '' ?>>
                                                        <label class="seat-cushion <?= $isTaken ? 'seat-occupied' : '' ?>" for="seat-<?= $n ?>" title="<?= $isTaken ? 'Seat ' . $n . ' is taken' : 'Seat ' . $n ?>">
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

                        <!-- Back of the bus -->
                        <div class="bus-rear-bumper text-center text-muted small py-2 border-top">
                            <i class="bi bi-shield-fill-check me-1 text-success"></i> Rear Safety Exit
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Sticky Order Summary & Payment Method -->
            <div class="col-lg-5">
                <div class="panel p-4 summary-card-modern shadow-sm bg-white rounded-4 border sticky-top" style="top: 1.5rem; z-index: 10;">
                    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-3">
                        <h2 class="h5 fw-bold mb-0">Reservation Summary</h2>
                        <span class="badge bg-light text-muted border">
                            <i class="bi bi-clock-history me-1"></i> Live
                        </span>
                    </div>

                    <!-- Route details cardlet with clear Departure Date & Time -->
                    <div class="bg-light p-3 rounded-3 mb-3 border">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-dark"><?= e($trip['origin']) ?> &rarr; <?= e($trip['destination']) ?></span>
                            <span class="badge bg-primary text-white"><?= e($trip['bus_type']) ?></span>
                        </div>
                        <div class="text-primary fw-bold small mt-2">
                            <i class="bi bi-calendar3 me-1"></i> <?= e(date('l, F j, Y', strtotime($trip['departure_time']))) ?>
                        </div>
                        <div class="fw-bold fs-5 text-dark mt-1">
                            <i class="bi bi-clock-fill text-warning me-1"></i> <?= e(fmt_time($trip['departure_time'])) ?>
                            <span class="text-muted fw-normal fs-6">&bull; Arrival: <?= e(fmt_time($trip['arrival_time'])) ?></span>
                        </div>
                        <div class="text-muted small mt-1">
                            Coach #<?= e($trip['bus_number']) ?> &bull; Driver: <?= e($trip['driver_name']) ?>
                        </div>
                    </div>

                    <!-- Selected Seats Chip Container -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="form-label mb-0 small text-muted">Selected Seats:</span>
                            <span class="badge bg-light text-muted border" id="seatCountBadge">0 seats</span>
                        </div>
                        <div id="seatChipsContainer" class="d-flex flex-wrap gap-1 min-h-36 p-2 bg-light rounded-3 border">
                            <span class="text-muted small my-auto fst-italic" id="noSeatsPlaceholder">Please click on the seats on the bus map to select.</span>
                        </div>
                    </div>

                    <!-- Per-seat Passenger Type Details (shown when seats are selected) -->
                    <div id="passengerDetailsSection" class="mb-3" style="display:none;">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="form-label mb-0 small fw-semibold text-dark">Passenger Details</span>
                            <span class="badge bg-info-subtle text-info border border-info-subtle small">20% discount for Student / Senior / PWD</span>
                        </div>
                        <div id="passengerDetailsRows" class="d-flex flex-column gap-2"></div>
                    </div>

                    <!-- Price Breakdown -->
                    <div class="price-breakdown-box py-3 border-top border-bottom mb-3">
                        <div class="d-flex justify-content-between text-muted small mb-1">
                            <span>Base fare per seat</span>
                            <span><?= money($trip['fare']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between text-muted small mb-2">
                            <span>Seats selected</span>
                            <span id="summarySeatCount">0</span>
                        </div>
                        <div class="d-flex justify-content-between text-muted small mb-2" id="discountRow" style="display:none!important;">
                            <span class="text-success"><i class="bi bi-tag-fill me-1"></i>Discount applied (20%)</span>
                            <span class="text-success fw-semibold" id="discountAmount">−₱0.00</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline pt-2 border-top">
                            <span class="fw-bold text-dark fs-6">Total Amount</span>
                            <span class="fw-bold fs-4 text-primary" id="totalFare">₱0.00</span>
                        </div>
                    </div>

                    <!-- Payment Method Selector — Online bookings: E-Wallet only -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold small mb-2">Payment Method</label>
                        <div class="alert alert-info border-0 py-2 px-3 small d-flex align-items-center gap-2 mb-2 rounded-3">
                            <i class="bi bi-info-circle-fill flex-shrink-0"></i>
                            <span>Online bookings require <strong>e-wallet payment</strong> (GCash / Maya). Walk-in passengers may pay cash or card at the terminal counter.</span>
                        </div>
                        <input type="hidden" name="payment_method" value="E_WALLET">
                        <label class="payment-method-card d-flex align-items-center p-3 rounded-3 border border-primary bg-primary-subtle">
                            <div class="d-flex align-items-center gap-2 w-100">
                                <span class="payment-icon bg-primary text-white"><i class="bi bi-phone-fill"></i></span>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small text-primary">E-Wallet (GCash / Maya)</div>
                                    <div class="text-muted" style="font-size: 0.75rem;">Your booking is reserved immediately. Present your reference number and e-wallet receipt at the terminal.</div>
                                </div>
                                <i class="bi bi-check-circle-fill text-primary fs-5"></i>
                            </div>
                        </label>
                    </div>

                    <!-- Confirm button -->
                    <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill shadow-sm d-flex align-items-center justify-content-center gap-2" id="confirmBtn" disabled>
                        <i class="bi bi-check-circle-fill"></i>
                        <span id="confirmBtnText">Select Seats to Continue</span>
                    </button>

                    <p class="small text-muted text-center mt-3 mb-0" style="font-size: 0.8rem;">
                        <i class="bi bi-info-circle me-1"></i> Your seats are reserved under your name upon submission. Tickets are issued after payment verification.
                    </p>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    var form           = document.getElementById('bookingForm');
    var fare           = parseFloat(form.dataset.fare);
    var max            = parseInt(form.dataset.max, 10);
    var boxes          = form.querySelectorAll('.seat-input');
    var chipsContainer = document.getElementById('seatChipsContainer');
    var placeholder    = document.getElementById('noSeatsPlaceholder');
    var seatBadge      = document.getElementById('seatCountBadge');
    var summaryCount   = document.getElementById('summarySeatCount');
    var discountRow    = document.getElementById('discountRow');
    var discountAmount = document.getElementById('discountAmount');
    var total          = document.getElementById('totalFare');
    var btn            = document.getElementById('confirmBtn');
    var btnText        = document.getElementById('confirmBtnText');
    var detailsSection = document.getElementById('passengerDetailsSection');
    var detailsRows    = document.getElementById('passengerDetailsRows');

    // Keep track of passenger types and ID numbers per seat
    var seatTypes = {};
    var seatIds   = {};

    function fmtMoney(val) {
        return '₱' + val.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function renderPassengerDetails(picked) {
        if (!detailsSection || !detailsRows) return;

        if (picked.length === 0) {
            detailsSection.style.display = 'none';
            detailsRows.innerHTML = '';
            return;
        }

        detailsSection.style.display = 'block';

        // Clean up unselected seats from cache
        Object.keys(seatTypes).forEach(function (sn) {
            if (picked.indexOf(parseInt(sn, 10)) === -1) {
                delete seatTypes[sn];
                delete seatIds[sn];
            }
        });

        // Re-render rows preserving current inputs
        detailsRows.innerHTML = '';
        picked.forEach(function (seatNum) {
            var currentType = seatTypes[seatNum] || 'REGULAR';
            var currentId   = seatIds[seatNum] || '';

            var card = document.createElement('div');
            card.className = 'p-3 bg-light rounded-3 border';

            var header = document.createElement('div');
            header.className = 'd-flex justify-content-between align-items-center mb-2';
            header.innerHTML = '<span class="badge bg-primary text-white px-2 py-1 rounded-pill fw-semibold">Seat ' + seatNum + '</span>' +
                '<span class="text-success small fw-semibold" id="seat-fare-tag-' + seatNum + '">' +
                (currentType === 'REGULAR' ? fmtMoney(fare) : fmtMoney(fare * 0.80) + ' (20% off)') +
                '</span>';
            card.appendChild(header);

            var formRow = document.createElement('div');
            formRow.className = 'row g-2 align-items-center';

            var colSelect = document.createElement('div');
            colSelect.className = 'col-sm-6';
            colSelect.innerHTML = '<label class="form-label small text-muted mb-1" for="ptype-' + seatNum + '">Passenger Type:</label>' +
                '<select class="form-select form-select-sm" name="passenger_type[' + seatNum + ']" id="ptype-' + seatNum + '">' +
                '<option value="REGULAR"' + (currentType === 'REGULAR' ? ' selected' : '') + '>Regular Passenger</option>' +
                '<option value="STUDENT"' + (currentType === 'STUDENT' ? ' selected' : '') + '>Student (20% Discount)</option>' +
                '<option value="SENIOR"' + (currentType === 'SENIOR' ? ' selected' : '') + '>Senior Citizen (20% Discount)</option>' +
                '<option value="PWD"' + (currentType === 'PWD' ? ' selected' : '') + '>PWD (20% Discount)</option>' +
                '</select>';

            var colId = document.createElement('div');
            colId.className = 'col-sm-6';
            colId.id = 'id-col-' + seatNum;
            colId.style.display = currentType === 'REGULAR' ? 'none' : 'block';
            colId.innerHTML = '<label class="form-label small text-muted mb-1" for="pid-' + seatNum + '">ID Number <span class="text-danger">*</span>:</label>' +
                '<input type="text" class="form-control form-control-sm" name="id_number[' + seatNum + ']" id="pid-' + seatNum + '" ' +
                'placeholder="Enter ID # (School / OSCA / PWD)" maxlength="50" value="' + (currentId.replace(/"/g, '&quot;')) + '"' +
                (currentType !== 'REGULAR' ? ' required' : '') + '>';

            formRow.appendChild(colSelect);
            formRow.appendChild(colId);
            card.appendChild(formRow);
            detailsRows.appendChild(card);

            // Hook up select changes
            var selectEl = colSelect.querySelector('select');
            var idInput = colId.querySelector('input');

            selectEl.addEventListener('change', function () {
                seatTypes[seatNum] = this.value;
                if (this.value !== 'REGULAR') {
                    colId.style.display = 'block';
                    idInput.required = true;
                } else {
                    colId.style.display = 'none';
                    idInput.required = false;
                }
                var tag = document.getElementById('seat-fare-tag-' + seatNum);
                if (tag) {
                    tag.textContent = (this.value === 'REGULAR') ? fmtMoney(fare) : fmtMoney(fare * 0.80) + ' (20% off)';
                }
                recalculatePrice(picked);
            });

            idInput.addEventListener('input', function () {
                seatIds[seatNum] = this.value;
            });
        });
    }

    function recalculatePrice(picked) {
        if (picked.length === 0) {
            summaryCount.textContent = '0';
            total.textContent = '₱0.00';
            if (discountRow) discountRow.style.setProperty('display', 'none', 'important');
            btn.disabled = true;
            btnText.textContent = 'Select Seats to Continue';
            return;
        }

        var totalDiscount = 0.0;
        var finalTotal = 0.0;

        picked.forEach(function (sn) {
            var type = seatTypes[sn] || 'REGULAR';
            if (type !== 'REGULAR') {
                var d = Math.round(fare * 0.20 * 100) / 100;
                totalDiscount += d;
                finalTotal += Math.max(0, fare - d);
            } else {
                finalTotal += fare;
            }
        });

        var formattedTotal = fmtMoney(finalTotal);
        seatBadge.textContent = picked.length + (picked.length === 1 ? ' seat' : ' seats');
        summaryCount.textContent = picked.length;
        total.textContent = formattedTotal;

        if (totalDiscount > 0 && discountRow && discountAmount) {
            discountRow.style.setProperty('display', 'flex', 'important');
            discountAmount.textContent = '−' + fmtMoney(totalDiscount);
        } else if (discountRow) {
            discountRow.style.setProperty('display', 'none', 'important');
        }

        btn.disabled = false;
        btnText.textContent = 'Confirm Reservation • ' + formattedTotal;
    }

    function update() {
        var picked = [];
        boxes.forEach(function (b) {
            if (b.checked) {
                picked.push(parseInt(b.value, 10));
            }
        });
        picked.sort(function (a, b) { return a - b; });

        // Update chips
        chipsContainer.innerHTML = '';
        if (picked.length === 0) {
            chipsContainer.appendChild(placeholder);
            placeholder.style.display = 'block';
            seatBadge.textContent = '0 seats';
            renderPassengerDetails(picked);
            recalculatePrice(picked);
        } else {
            placeholder.style.display = 'none';
            picked.forEach(function (seatNum) {
                var chip = document.createElement('span');
                chip.className = 'seat-selected-chip badge bg-primary text-white p-2 px-2 d-inline-flex align-items-center gap-1 rounded-pill';
                chip.innerHTML = '<i class="bi bi-check-circle-fill" style="font-size:0.75rem"></i> Seat ' + seatNum + ' <i class="bi bi-x-circle-fill cursor-pointer opacity-75 ms-1" title="Remove seat"></i>';
                chip.querySelector('.bi-x-circle-fill').addEventListener('click', function (e) {
                    e.stopPropagation();
                    var targetInput = document.getElementById('seat-' + seatNum);
                    if (targetInput) {
                        targetInput.checked = false;
                        update();
                    }
                });
                chipsContainer.appendChild(chip);
            });

            renderPassengerDetails(picked);
            recalculatePrice(picked);
        }
    }

    boxes.forEach(function (b) {
        b.addEventListener('change', function () {
            if (form.querySelectorAll('.seat-input:checked').length > max) {
                b.checked = false;
                alert('You can select a maximum of ' + max + ' seats per booking.');
            }
            update();
        });
    });

    update();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
