<?php
/**
 * index.php  Homepage with the trip search form, scheduled available bus timetable,
 * and built-in sliding Login/Register portal.
 */
require_once __DIR__ . '/includes/auth.php';

// Handle Direct Login / Register actions from the landing page
$authErrors = [];
$authSuccess = '';
$activeAuthTab = $_GET['tab'] ?? 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify()) {
        $authErrors[] = 'Your session expired. Please try again.';
    } elseif ($_POST['action'] === 'landing_login') {
        $activeAuthTab = 'login';
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $authErrors[] = 'Enter your email and password.';
        } else {
            $stmt = $pdo->prepare('SELECT user_id, first_name, last_name, email, phone_number, password_hash, role, account_status FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $u = $stmt->fetch();

            if (!$u || !password_verify($password, $u['password_hash'])) {
                $authErrors[] = 'Incorrect email or password.';
            } elseif ($u['account_status'] !== 'ACTIVE') {
                $authErrors[] = 'This account is inactive. Contact the administrator.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id']   = (int)$u['user_id'];
                $_SESSION['user_name'] = $u['first_name'] . ' ' . $u['last_name'];
                $_SESSION['role']      = $u['role'];
                redirect(in_array($u['role'], ['ADMIN', 'SUPER_ADMIN'], true) ? 'admin/dashboard.php' : 'passenger/dashboard.php');
            }
        }
    } elseif ($_POST['action'] === 'landing_register') {
        $activeAuthTab = 'register';
        $first    = trim($_POST['first_name'] ?? '');
        $last     = trim($_POST['last_name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $phone    = preg_replace('/[\s-]/', '', $_POST['phone_number'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (!preg_match("/^[\p{L}][\p{L} .'-]{1,49}$/u", $first)) $authErrors[] = 'Enter a valid first name (2 to 50 letters).';
        if (!preg_match("/^[\p{L}][\p{L} .'-]{1,49}$/u", $last))  $authErrors[] = 'Enter a valid last name (2 to 50 letters).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $authErrors[] = 'Enter a valid email address.';
        if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)) $authErrors[] = 'Enter a Philippine mobile number, like 09171234567.';
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) $authErrors[] = 'Password must be at least 8 characters with a letter & number.';
        if ($password !== $confirm) $authErrors[] = 'Passwords do not match.';

        if (!$authErrors) {
            $chk = $pdo->prepare('SELECT 1 FROM users WHERE email = :email');
            $chk->execute([':email' => $email]);
            if ($chk->fetch()) {
                $authErrors[] = 'That email is already registered. Please sign in.';
            }
        }

        if (!$authErrors) {
            $otpInfo = create_and_dispatch_otp($pdo, $email, $phone, 'REGISTRATION');
            $_SESSION['pending_registration'] = [
                'first_name'    => $first,
                'last_name'     => $last,
                'email'         => $email,
                'phone_number'  => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'masked_email'  => $otpInfo['masked_email'],
                'masked_phone'  => $otpInfo['masked_phone'],
                'code'          => $otpInfo['code'],
                'expires_at'    => $otpInfo['expires_at'],
            ];
            redirect('auth/verify_otp.php');
        }
    }
}

// Cities for the dropdowns (only routes that are ACTIVE)
$origins = $pdo->query("SELECT DISTINCT origin FROM routes WHERE status = 'ACTIVE' ORDER BY origin")->fetchAll(PDO::FETCH_COLUMN);
$destinations = $pdo->query("SELECT DISTINCT destination FROM routes WHERE status = 'ACTIVE' ORDER BY destination")->fetchAll(PDO::FETCH_COLUMN);

// All upcoming scheduled trips with date, time, bus info, and available seats
$upcomingTrips = $pdo->query(
    "SELECT t.trip_id, t.departure_time, t.arrival_time, t.fare,
            r.origin, r.destination, r.distance_km,
            b.bus_number, b.bus_type, b.total_seats,
            b.total_seats - (SELECT COUNT(*) FROM booking_seats bs WHERE bs.trip_id = t.trip_id AND bs.seat_status = 'BOOKED') AS available_seats
     FROM trips t
     JOIN routes r ON r.route_id = t.route_id
     JOIN buses b ON b.bus_id = t.bus_id
     WHERE t.status = 'SCHEDULED'
       AND t.departure_time > NOW()
       AND r.status = 'ACTIVE'
       AND b.status = 'ACTIVE'
     ORDER BY t.departure_time ASC
     LIMIT 8"
)->fetchAll();

// Routes with their lowest upcoming fare and next departure date/time
$routes = $pdo->query(
    "SELECT r.route_id, r.origin, r.destination, r.distance_km,
            MIN(t.fare) AS from_fare,
            COUNT(t.trip_id) AS upcoming_trips,
            MIN(t.departure_time) AS next_departure
     FROM routes r
     LEFT JOIN trips t ON t.route_id = r.route_id AND t.status = 'SCHEDULED' AND t.departure_time > NOW()
     WHERE r.status = 'ACTIVE'
     GROUP BY r.route_id, r.origin, r.destination, r.distance_km
     ORDER BY r.origin, r.destination"
)->fetchAll();

$selOrigin = $_GET['origin'] ?? '';
$selDest   = $_GET['destination'] ?? '';
$earliestTripDate = !empty($upcomingTrips) ? date('Y-m-d', strtotime($upcomingTrips[0]['departure_time'])) : date('Y-m-d');
$selDate   = $_GET['date'] ?? $earliestTripDate;

$pageTitle = 'Book your bus seat';
$layout    = 'public';
require __DIR__ . '/includes/header.php';
?>
<!-- Hero Section with Integrated Sliding Auth Portal -->
<section class="hero hero-elevated position-relative overflow-hidden" id="hero-portal">
    <div class="hero-ambient-glow"></div>
    <div class="container position-relative z-1 py-4">
        <div class="text-center text-white mb-4">
            <div class="hero-badge mb-3 d-inline-flex align-items-center gap-2">
                <span class="badge-dot"></span>
                <span>Official Philippine Bus Booking Portal</span>
            </div>
            <h1 class="hero-title mb-2">
                Fast &amp; Reliable Intercity Bus Travel
            </h1>
            <p class="hero-sub mb-0 text-white-50 mx-auto" style="max-width: 650px;">
                Sign in or register below to reserve your seats, select coach layouts, and download instant boarding passes.
            </p>
        </div>

        <!-- Integrated Sliding Login and Register Container in place of Find a Trip -->
        <?php if (!is_logged_in()): ?>
            <div class="d-flex justify-content-center my-3">
                <div class="auth-container-sliding <?= $activeAuthTab === 'register' ? 'right-panel-active' : '' ?>" id="authContainerLanding">
                    <!-- Register Form Box -->
                    <div class="form-container sign-up-container">
                        <form method="post" action="<?= url('index.php#hero-portal') ?>" class="auth-inner-form" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="landing_register">
                            <h2 class="h4 fw-bold text-dark mb-1">Create Passenger Account</h2>
                            <p class="text-muted small mb-3">Join in one minute to book seats &amp; travel nationwide.</p>

                            <?php if ($activeAuthTab === 'register' && $authErrors): ?>
                                <div class="alert alert-danger py-1 px-2 border-0 shadow-sm small mb-2 w-100 text-start">
                                    <ul class="mb-0 ps-3">
                                        <?php foreach ($authErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <div class="row g-2 w-100 mb-2">
                                <div class="col-6 text-start">
                                    <label class="form-label small fw-semibold mb-1">First Name</label>
                                    <input type="text" class="form-control form-control-sm" name="first_name" value="<?= e($_POST['first_name'] ?? '') ?>" placeholder="Juan" required>
                                </div>
                                <div class="col-6 text-start">
                                    <label class="form-label small fw-semibold mb-1">Last Name</label>
                                    <input type="text" class="form-control form-control-sm" name="last_name" value="<?= e($_POST['last_name'] ?? '') ?>" placeholder="Dela Cruz" required>
                                </div>
                            </div>

                            <div class="w-100 text-start mb-2">
                                <label class="form-label small fw-semibold mb-1">Email Address</label>
                                <input type="email" class="form-control form-control-sm" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="juan@example.com" required>
                            </div>

                            <div class="w-100 text-start mb-2">
                                <label class="form-label small fw-semibold mb-1">Mobile Number</label>
                                <input type="tel" class="form-control form-control-sm" name="phone_number" value="<?= e($_POST['phone_number'] ?? '') ?>" placeholder="09171234567" required>
                            </div>

                            <div class="row g-2 w-100 mb-3">
                                <div class="col-6 text-start">
                                    <label class="form-label small fw-semibold mb-1">Password</label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" class="form-control" id="reg_pw_home" name="password" placeholder="Min. 8 chars" required>
                                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="reg_pw_home"><i class="bi bi-eye"></i></button>
                                    </div>
                                </div>
                                <div class="col-6 text-start">
                                    <label class="form-label small fw-semibold mb-1">Confirm</label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" class="form-control" id="reg_cpw_home" name="confirm_password" placeholder="Repeat" required>
                                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="reg_cpw_home"><i class="bi bi-eye"></i></button>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary rounded-pill w-100 py-2 fw-bold shadow-sm">
                                <i class="bi bi-person-check-fill me-1"></i> Register &amp; Verify OTP
                            </button>
                            <div class="d-block d-md-none mt-3">
                                <a href="javascript:void(0)" onclick="toggleLandingToLogin()" class="text-primary small fw-semibold">Already registered? Sign In</a>
                            </div>
                        </form>
                    </div>

                    <!-- Login Form Box -->
                    <div class="form-container sign-in-container">
                        <form method="post" action="<?= url('index.php#hero-portal') ?>" class="auth-inner-form" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="landing_login">

                            <div class="brand-icon-wrapper mb-2" style="width: 52px; height: 52px; font-size: 1.5rem; background: var(--primary-subtle, #ecfdf5); color: var(--primary, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-bus-front-fill"></i>
                            </div>
                            <h2 class="h3 fw-bold text-dark mb-1">Welcome Back</h2>
                            <p class="text-muted small mb-4">Sign in to your account with your email &amp; password.</p>

                            <?php if ($activeAuthTab === 'login' && $authErrors): ?>
                                <div class="alert alert-danger py-2 px-3 border-0 shadow-sm small mb-3 w-100 text-start">
                                    <ul class="mb-0 ps-3">
                                        <?php foreach ($authErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <div class="w-100 text-start mb-3">
                                <label class="form-label small fw-semibold">Email address</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                                    <input type="email" class="form-control border-start-0 ps-0" name="email" value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email" placeholder="name@example.com" required>
                                </div>
                            </div>

                            <div class="w-100 text-start mb-4">
                                <label class="form-label small fw-semibold">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                                    <input type="password" class="form-control border-start-0 border-end-0 ps-0" id="login_pw_home" name="password" autocomplete="current-password" placeholder="Enter password" required>
                                    <button class="btn btn-outline-secondary border-start-0" type="button" data-password-toggle="login_pw_home"><i class="bi bi-eye"></i></button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary rounded-pill w-100 py-3 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-box-arrow-in-right"></i> Sign In to Account
                            </button>
                            <div class="d-block d-md-none mt-3">
                                <a href="javascript:void(0)" onclick="toggleLandingToRegister()" class="text-primary small fw-semibold">Need an account? Sign Up</a>
                            </div>
                        </form>
                    </div>

                    <!-- Sliding Cover Layer -->
                    <div class="overlay-container">
                        <div class="overlay">
                            <div class="overlay-panel overlay-left">
                                <span class="badge bg-white bg-opacity-20 text-white rounded-pill px-3 py-1 small mb-3">Philippine Intercity Express</span>
                                <h2 class="h2 fw-bold text-white mb-2">Welcome Back!</h2>
                                <p class="text-white-50 px-4 small mb-4">Sign in with your email &amp; password to view your booked trips, digital boarding passes, and ticket receipts.</p>
                                <button type="button" class="btn btn-outline-light rounded-pill px-5 py-2 fw-bold shadow-sm" onclick="toggleLandingToLogin()">
                                    <i class="bi bi-arrow-left me-1"></i> Sign In
                                </button>
                            </div>

                            <div class="overlay-panel overlay-right">
                                <span class="badge bg-white bg-opacity-20 text-white rounded-pill px-3 py-1 small mb-3">Instant Online Booking</span>
                                <h2 class="h2 fw-bold text-white mb-2">Hello, Traveler!</h2>
                                <p class="text-white-50 px-4 small mb-4">Register your passenger account today to select preferred bus seats, receive e-wallet tickets, and enjoy 20% discounts.</p>
                                <button type="button" class="btn btn-outline-light rounded-pill px-5 py-2 fw-bold shadow-sm" onclick="toggleLandingToRegister()">
                                    Sign Up <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <div class="p-4 bg-white bg-opacity-10 rounded-4 border border-white border-opacity-20 max-w-600 mx-auto">
                    <h3 class="h4 text-white fw-bold mb-2">You are currently logged in</h3>
                    <p class="text-white-50 small mb-3">Go to your dashboard to manage your bookings and reservations.</p>
                    <a href="<?= url(in_array($_SESSION['role'], ['ADMIN', 'SUPER_ADMIN'], true) ? 'admin/dashboard.php' : 'passenger/dashboard.php') ?>" class="btn btn-primary rounded-pill px-4 shadow-sm">
                        Go to Dashboard <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- How It Works Section -->
<section class="how-it-works-section py-5 bg-white border-top border-bottom" id="how-it-works">
    <div class="container">
        <div class="text-center max-w-600 mx-auto mb-5">
            <span class="badge bg-primary-subtle text-primary px-3 py-1 rounded-pill mb-2 fw-semibold">Simple 4-Step Process</span>
            <h2 class="h3 fw-bold">How It Works</h2>
            <p class="text-muted small mb-0">From search to boarding, booking your next journey takes less than two minutes.</p>
        </div>
        <div class="row g-4 text-center">
            <div class="col-md-3">
                <div class="step-card p-3 p-md-4 rounded-4 bg-light border shadow-sm h-100">
                    <div class="step-number mb-3 mx-auto bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-5 shadow-sm" style="width: 48px; height: 48px;">1</div>
                    <h3 class="h6 fw-bold mb-2 text-dark">1. Search Trips</h3>
                    <p class="text-muted small mb-0">Type your departure and destination terminal to find available buses.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="step-card p-3 p-md-4 rounded-4 bg-light border shadow-sm h-100">
                    <div class="step-number mb-3 mx-auto bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-5 shadow-sm" style="width: 48px; height: 48px;">2</div>
                    <h3 class="h6 fw-bold mb-2 text-dark">2. Choose Seats</h3>
                    <p class="text-muted small mb-0">Pick your favorite seat (window, aisle, front) on our real-time interactive coach layout.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="step-card p-3 p-md-4 rounded-4 bg-light border shadow-sm h-100">
                    <div class="step-number mb-3 mx-auto bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-5 shadow-sm" style="width: 48px; height: 48px;">3</div>
                    <h3 class="h6 fw-bold mb-2 text-dark">3. Pay &amp; Confirm</h3>
                    <p class="text-muted small mb-0">Pay online (GCash, Maya, Card) or choose terminal walk-in.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="step-card p-3 p-md-4 rounded-4 bg-light border shadow-sm h-100">
                    <div class="step-number mb-3 mx-auto bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-5 shadow-sm" style="width: 48px; height: 48px;">4</div>
                    <h3 class="h6 fw-bold mb-2 text-dark">4. Board &amp; Travel</h3>
                    <p class="text-muted small mb-0">Show your digital e-ticket or printable QR boarding pass upon boarding at the terminal.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Routes We Serve Section -->
<section class="container py-5" id="routes">
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4">
        <div>
            <span class="badge bg-primary-subtle text-primary px-3 py-1 rounded-pill mb-2 fw-semibold">Available Network</span>
            <h2 class="h3 mb-1 fw-bold">Routes We Serve</h2>
            <p class="text-muted mb-0 small">Explore our intercity network across Luzon, Visayas, and Mindanao with real-time seat availability.</p>
        </div>
        <a href="#hero-portal" class="btn btn-outline-dark btn-sm d-none d-md-inline-flex align-items-center gap-1">
            <i class="bi bi-arrow-up-circle"></i> Back to Top
        </a>
    </div>

    <div class="row g-3">
        <?php foreach ($routes as $r): ?>
            <?php
                $nextDateParam = !empty($r['next_departure']) ? date('Y-m-d', strtotime($r['next_departure'])) : '';
            ?>
            <div class="col-md-6 col-xl-4">
                <a class="route-card-modern"
                   href="<?= is_logged_in() ? url('passenger/trip_results.php?origin=' . urlencode($r['origin']) . '&destination=' . urlencode($r['destination']) . '&date=' . e($nextDateParam)) : url('index.php#hero-portal') ?>">
                    <div class="route-card-header d-flex justify-content-between align-items-center mb-2">
                        <span class="route-badge-km"><i class="bi bi-signpost-split me-1"></i><?= e(number_format((float)$r['distance_km'])) ?> km</span>
                        <?php if ($r['upcoming_trips'] > 0): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                <i class="bi bi-clock me-1"></i><?= (int)$r['upcoming_trips'] ?> upcoming trip<?= $r['upcoming_trips'] > 1 ? 's' : '' ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-muted">No upcoming trips</span>
                        <?php endif; ?>
                    </div>
                    <div class="route-line-modern">
                        <span class="origin-city"><?= e($r['origin']) ?></span>
                        <div class="route-arrow-icon"><i class="bi bi-arrow-right"></i></div>
                        <span class="destination-city"><?= e($r['destination']) ?></span>
                    </div>

                    <?php if (!empty($r['next_departure'])): ?>
                        <div class="next-departure-tag small mt-2 p-2 bg-light rounded-2 border d-flex align-items-center justify-content-between">
                            <span class="text-muted"><i class="bi bi-calendar2-check text-primary me-1"></i> Next departure:</span>
                            <strong class="text-dark"><?= e(fmt_date($r['next_departure'])) ?> &bull; <?= e(fmt_time($r['next_departure'])) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div class="route-card-footer d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                        <span class="text-muted small">Starting from</span>
                        <?php if ($r['upcoming_trips'] > 0): ?>
                            <span class="route-price"><?= money($r['from_fare']) ?></span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
        <?php if (!$routes): ?>
            <div class="col-12"><div class="alert alert-info mb-0">No active routes are available yet.</div></div>
        <?php endif; ?>
    </div>
</section>

<script>
const availableOrigins = <?= json_encode(array_values($origins)) ?>;
const availableDestinations = <?= json_encode(array_values($destinations)) ?>;

function setupAutocomplete(inputId, suggestionsId, itemsList) {
    const input = document.getElementById(inputId);
    const box = document.getElementById(suggestionsId);
    if (!input || !box) return;

    input.addEventListener('input', function() {
        const val = this.value.trim().toLowerCase();
        box.innerHTML = '';
        if (!val) {
            box.classList.add('d-none');
            return;
        }

        const matches = itemsList.filter(item => item.toLowerCase().includes(val));
        if (matches.length === 0) {
            box.innerHTML = '<div class="list-group-item text-muted small py-2"><i class="bi bi-info-circle me-1"></i>No matching terminal found</div>';
            box.classList.remove('d-none');
            return;
        }

        matches.forEach(item => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action py-2 px-3 d-flex align-items-center justify-content-between';
            
            // Highlight matching letters
            const regex = new RegExp(`(${val.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
            const highlighted = item.replace(regex, '<strong class="text-primary">$1</strong>');
            
            btn.innerHTML = `<span><i class="bi bi-geo-alt me-2 text-muted"></i>${highlighted}</span><i class="bi bi-arrow-up-left small text-muted"></i>`;
            btn.addEventListener('click', function() {
                input.value = item;
                box.classList.add('d-none');
            });
            box.appendChild(btn);
        });

        box.classList.remove('d-none');
    });

    // Close when clicking outside
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !box.contains(e.target)) {
            box.classList.add('d-none');
        }
    });

    input.addEventListener('focus', function() {
        if (this.value.trim() !== '') {
            input.dispatchEvent(new Event('input'));
        }
    });
}

setupAutocomplete('originInput', 'originSuggestions', availableOrigins);
setupAutocomplete('destinationInput', 'destinationSuggestions', availableDestinations);

// Dropdown item selection
document.querySelectorAll('.city-opt').forEach(btn => {
    btn.addEventListener('click', function() {
        const targetId = this.dataset.target;
        const targetInput = document.getElementById(targetId);
        if (targetInput) {
            targetInput.value = this.textContent.trim();
        }
    });
});

// Swap cities
const swapBtn = document.getElementById('swapCities');
if (swapBtn) {
    swapBtn.addEventListener('click', function() {
        const orig = document.getElementById('originInput');
        const dest = document.getElementById('destinationInput');
        if (orig && dest) {
            const tmp = orig.value;
            orig.value = dest.value;
            dest.value = tmp;
        }
    });
}

function fillSearch(from, to, date) {
    var originInput = document.getElementById('originInput');
    var destInput = document.getElementById('destinationInput');
    var dateInput = document.getElementById('date');
    if (originInput && destInput) {
        originInput.value = from;
        destInput.value = to;
        if (dateInput && date) {
            dateInput.value = date;
        }
        var searchEl = document.getElementById('search');
        if (searchEl) {
            searchEl.scrollIntoView({ behavior: 'smooth' });
        }
    }
}

// Landing Page Sliding Auth Helpers
function toggleLandingToRegister() {
    const c = document.getElementById('authContainerLanding');
    if (c) c.classList.add('right-panel-active');
}

function toggleLandingToLogin() {
    const c = document.getElementById('authContainerLanding');
    if (c) c.classList.remove('right-panel-active');
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
