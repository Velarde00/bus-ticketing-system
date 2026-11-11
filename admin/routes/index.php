<?php
/**
 * admin/routes/index.php
 * Dedicated routes manager with searchable city dropdowns and
 * automatic Haversine distance calculation.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$errors = [];
$record = null;

/* ── Resolve edit target ──────────────────────────────────────────────── */
$rawEditId = $_GET['id'] ?? ($_POST['id'] ?? '');
$editId    = filter_var(is_scalar($rawEditId) ? $rawEditId : '', FILTER_VALIDATE_INT);
if ($editId) {
    $s = $pdo->prepare('SELECT * FROM routes WHERE route_id = :id');
    $s->execute([':id' => $editId]);
    $record = $s->fetch() ?: null;
}

/* ── POST handler ─────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'delete') {
        $delId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if (!$delId) {
            $errors[] = 'Choose a valid record to delete.';
        } else {
            try {
                $pdo->prepare('DELETE FROM routes WHERE route_id = :id')->execute([':id' => $delId]);
                set_flash('success', 'Route deleted.');
                redirect('admin/routes/index.php');
            } catch (PDOException) {
                $errors[] = 'This route cannot be deleted because it is used by existing trips. Set it to INACTIVE instead.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'save') {
        $origin      = trim(substr((string)($_POST['origin']      ?? ''), 0, 100));
        $destination = trim(substr((string)($_POST['destination'] ?? ''), 0, 100));
        $distanceRaw = trim((string)($_POST['distance_km'] ?? ''));
        $status      = trim((string)($_POST['status']       ?? ''));

        if ($origin === '')      $errors[] = 'Origin is required.';
        if ($destination === '') $errors[] = 'Destination is required.';
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) $errors[] = 'Choose a valid status.';
        if (strcasecmp($origin, $destination) === 0) $errors[] = 'Origin and destination must be different.';

        $distance = filter_var($distanceRaw, FILTER_VALIDATE_FLOAT);
        if ($distance === false || $distance <= 0) {
            $errors[] = 'Enter a valid distance in kilometres (must be greater than 0).';
        }

        if (!$errors) {
            try {
                $saveId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
                if ($saveId) {
                    $pdo->prepare(
                        'UPDATE routes SET origin=:o, destination=:d, distance_km=:km, status=:s WHERE route_id=:id'
                    )->execute([':o'=>$origin,':d'=>$destination,':km'=>round($distance,2),':s'=>$status,':id'=>$saveId]);
                } else {
                    $pdo->prepare(
                        "INSERT INTO routes (origin, destination, distance_km, status) VALUES (:o,:d,:km,:s)"
                    )->execute([':o'=>$origin,':d'=>$destination,':km'=>round($distance,2),':s'=>$status]);
                }
                set_flash('success', 'Route saved successfully.');
                redirect('admin/routes/index.php');
            } catch (PDOException $e) {
                error_log('admin routes save: ' . $e->getMessage());
                $errors[] = 'Could not save this route. Make sure the origin-destination pair is not already registered.';
            }
        }
    }
}

$rows = $pdo->query(
    'SELECT route_id AS id, origin, destination, distance_km, status FROM routes ORDER BY origin, destination'
)->fetchAll();

$pageTitle  = 'Manage Routes';
$layout     = 'admin';
$activePage = 'routes';
require __DIR__ . '/../../includes/header.php';

/* ── Philippine cities with lat/lng for Haversine calculation ─────────── */
$phCities = [
    // NCR
    ['Manila','14.5995','120.9842'],
    ['Quezon City','14.6760','121.0437'],
    ['Caloocan','14.6500','120.9667'],
    ['Las Piñas','14.4453','120.9900'],
    ['Makati','14.5547','121.0244'],
    ['Malabon','14.6625','120.9570'],
    ['Mandaluyong','14.5794','121.0359'],
    ['Marikina','14.6507','121.1029'],
    ['Muntinlupa','14.4082','121.0416'],
    ['Navotas','14.6667','120.9500'],
    ['Parañaque','14.4793','121.0198'],
    ['Pasay','14.5378','121.0014'],
    ['Pasig','14.5760','121.0851'],
    ['Pateros','14.5440','121.0680'],
    ['San Juan','14.6000','121.0333'],
    ['Taguig','14.5243','121.0792'],
    ['Valenzuela','14.7000','120.9667'],
    // Luzon
    ['Angeles City','15.1450','120.5887'],
    ['Antipolo','14.5862','121.1760'],
    ['Batangas City','13.7565','121.0583'],
    ['Baguio','16.4023','120.5960'],
    ['Biñan','14.3374','121.0831'],
    ['Cabanatuan','15.4900','120.9700'],
    ['Calamba','14.2126','121.1651'],
    ['Calapan','13.4119','121.1803'],
    ['Dagupan','16.0430','120.3330'],
    ['Dasmariñas','14.3291','120.9360'],
    ['Ilagan','17.1489','121.8898'],
    ['Imus','14.4297','120.9367'],
    ['Laoag','18.1976','120.5936'],
    ['Legazpi','13.1391','123.7437'],
    ['Lucena','13.9369','121.6174'],
    ['Malolos','14.8527','120.8148'],
    ['Masbate City','12.3700','123.6200'],
    ['Meycauayan','14.7367','120.9602'],
    ['Naga City','13.6192','123.1814'],
    ['Olongapo','14.8295','120.2826'],
    ['Ormoc','11.0057','124.6075'],
    ['Palayan','15.5359','121.0836'],
    ['San Fernando (La Union)','16.6159','120.3167'],
    ['San Fernando (Pampanga)','15.0286','120.6900'],
    ['San Jose','15.7985','121.0254'],
    ['San Pablo','14.0699','121.3222'],
    ['Santa Rosa','14.3122','121.1114'],
    ['Santiago City','16.6881','121.5503'],
    ['Tabaco','13.3598','123.7340'],
    ['Tarlac City','15.4800','120.5970'],
    ['Trece Martires','14.2817','120.8651'],
    ['Tuguegarao','17.6132','121.7270'],
    ['Vigan','17.5747','120.3869'],
    // Visayas
    ['Bacolod','10.6771','122.9540'],
    ['Bago','10.5361','122.8358'],
    ['Bayawan','9.3669','122.8017'],
    ['Cadiz','10.9527','123.3043'],
    ['Calbayog','12.0674','124.5949'],
    ['Canlaon','10.3836','123.1990'],
    ['Cebu City','10.3157','123.8854'],
    ['Danao','10.5214','124.0266'],
    ['Dumaguete','9.3068','123.3054'],
    ['Escalante','10.8373','123.5050'],
    ['Guihulngan','10.1196','123.2730'],
    ['Himamaylan','10.0981','122.8679'],
    ['Iloilo City','10.6969','122.5644'],
    ['Kabankalan','9.9900','122.8143'],
    ['La Carlota','10.4238','122.9187'],
    ['Lapu-Lapu','10.3119','123.9494'],
    ['Mandaue','10.3236','123.9223'],
    ['Maasin','10.1322','124.8463'],
    ['Naga (Cebu)','10.2119','123.7553'],
    ['Roxas City','11.5854','122.7510'],
    ['Sagay','10.9029','123.4218'],
    ['San Carlos (Negros Occ.)','10.4939','123.4119'],
    ['Silay','10.8003','122.9741'],
    ['Sipalay','9.7515','122.4010'],
    ['Tagbilaran','9.6500','123.8500'],
    ['Talisay (Cebu)','10.2448','123.8465'],
    ['Talisay (Negros)','10.7375','122.9739'],
    ['Tacloban','11.2543','125.0000'],
    ['Toledo','10.3772','123.6391'],
    // Mindanao
    ['Butuan','8.9490','125.5444'],
    ['Cagayan de Oro','8.4542','124.6319'],
    ['Catbalogan','11.7753','124.8864'],
    ['Cotabato City','7.2047','124.2310'],
    ['Davao City','7.1907','125.4553'],
    ['Digos','6.7498','125.3572'],
    ['Dipolog','8.5887','123.3417'],
    ['Gingoog','8.8216','125.1066'],
    ['Iligan','8.2284','124.2452'],
    ['Kidapawan','7.0083','125.0893'],
    ['Koronadal','6.5004','124.8471'],
    ['Mati','6.9573','126.2296'],
    ['Marawi','7.9986','124.2928'],
    ['Oroquieta','8.4850','123.7980'],
    ['Pagadian','7.8285','123.4357'],
    ['Panabo','7.3081','125.6837'],
    ['Surigao City','9.7897','125.4958'],
    ['Tagum','7.4479','125.8077'],
    ['Valencia','7.9050','125.0942'],
    ['Zamboanga City','6.9214','122.0790'],
];
?>

<!-- Tom Select CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
<style>
.ts-wrapper.form-control { padding: 0; border: none; box-shadow: none; }
.ts-wrapper .ts-control { border-radius: 8px; border: 1px solid #dee2e6; min-height: 42px; font-size: 0.9rem; }
.ts-wrapper.focus .ts-control { border-color: #0d6efd; box-shadow: 0 0 0 0.2rem rgba(13,110,253,.2); }
.ts-dropdown { border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,.12); font-size: 0.875rem; }
.ts-dropdown .option { padding: 8px 14px; }
.ts-dropdown .option.active { background: #e8f0fe; color: #1a56db; }
#distanceResult { transition: opacity .3s ease; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1 text-dark">Manage Routes</h1>
        <p class="text-muted small mb-0">Add, update, and maintain bus routes. Distance is auto-calculated when you pick both cities.</p>
    </div>
    <a class="btn btn-primary btn-sm rounded-pill shadow-sm px-3" href="<?= url('admin/routes/index.php') ?>">
        <i class="bi bi-plus-lg me-1"></i> New Route
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger py-2 border-0 shadow-sm small mb-4">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Left: Form -->
    <div class="col-xl-4">
        <form method="post" class="panel bg-white p-4 rounded-4 border shadow-sm" id="routeForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($record['route_id'] ?? $editId ?? 0) ?>">

            <div class="d-flex align-items-center gap-2 mb-4 pb-2 border-bottom">
                <span class="stat-icon-wrapper bg-primary-subtle text-primary">
                    <i class="bi bi-signpost-split"></i>
                </span>
                <h2 class="h5 fw-bold mb-0 text-dark"><?= $record ? 'Edit Route' : 'Add New Route' ?></h2>
            </div>

            <!-- Origin -->
            <div class="mb-3">
                <label class="form-label small fw-semibold" for="origin">
                    <i class="bi bi-geo-alt-fill text-primary me-1"></i>Origin City / Municipality
                </label>
                <select id="origin" name="origin" class="form-control" required>
                    <option value="">Search origin city...</option>
                    <?php foreach ($phCities as [$city]):
                        $sel = ($record['origin'] ?? $_POST['origin'] ?? '') === $city ? 'selected' : '';
                    ?>
                        <option value="<?= e($city) ?>" <?= $sel ?>><?= e($city) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Destination -->
            <div class="mb-3">
                <label class="form-label small fw-semibold" for="destination">
                    <i class="bi bi-geo-fill text-danger me-1"></i>Destination City / Municipality
                </label>
                <select id="destination" name="destination" class="form-control" required>
                    <option value="">Search destination city...</option>
                    <?php foreach ($phCities as [$city]):
                        $sel = ($record['destination'] ?? $_POST['destination'] ?? '') === $city ? 'selected' : '';
                    ?>
                        <option value="<?= e($city) ?>" <?= $sel ?>><?= e($city) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Distance (auto-computed or manual) -->
            <div class="mb-3">
                <label class="form-label small fw-semibold" for="distance_km">
                    <i class="bi bi-rulers me-1 text-success"></i>Distance (km)
                </label>
                <div class="input-group">
                    <input type="number" id="distance_km" name="distance_km" class="form-control"
                           step="0.01" min="0.01" required
                           value="<?= e($record['distance_km'] ?? $_POST['distance_km'] ?? '') ?>"
                           placeholder="Auto-filled when cities are selected">
                    <span class="input-group-text text-muted small">km</span>
                </div>
                <!-- Result hint -->
                <div id="distanceResult" class="mt-2 p-2 rounded-2 small" style="display:none;"></div>
            </div>

            <!-- Status -->
            <div class="mb-4">
                <label class="form-label small fw-semibold">Status</label>
                <select name="status" class="form-select custom-select-styled" required>
                    <?php foreach (['ACTIVE', 'INACTIVE'] as $opt): ?>
                        <option value="<?= $opt ?>" <?= ($record['status'] ?? 'ACTIVE') === $opt ? 'selected' : '' ?>>
                            <?= ucfirst(strtolower($opt)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="d-flex gap-2 pt-2">
                <button type="submit" class="btn btn-primary rounded-pill shadow-sm px-4">
                    <i class="bi bi-check-lg me-1"></i><?= $record ? 'Save Changes' : 'Save Route' ?>
                </button>
                <?php if ($record): ?>
                    <a href="<?= url('admin/routes/index.php') ?>" class="btn btn-outline-secondary rounded-pill px-3">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Right: Routes Table -->
    <div class="col-xl-8">
        <div class="panel bg-white rounded-4 border shadow-sm overflow-hidden">
            <div class="panel-head p-3 px-4 border-bottom d-flex justify-content-between align-items-center bg-light bg-opacity-50">
                <h2 class="h6 fw-bold mb-0 text-uppercase text-muted" style="letter-spacing:.05em;">Routes Database</h2>
                <span class="badge bg-light text-muted border"><?= count($rows) ?> routes</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 custom-modern-table">
                    <thead>
                        <tr>
                            <th class="ps-4">Origin</th>
                            <th>Destination</th>
                            <th>Distance</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="ps-4">
                                <span class="d-flex align-items-center gap-1">
                                    <i class="bi bi-geo-alt-fill text-primary" style="font-size:.8rem;"></i>
                                    <span class="fw-semibold text-dark"><?= e($row['origin']) ?></span>
                                </span>
                            </td>
                            <td>
                                <span class="d-flex align-items-center gap-1">
                                    <i class="bi bi-geo-fill text-danger" style="font-size:.8rem;"></i>
                                    <span class="fw-semibold text-dark"><?= e($row['destination']) ?></span>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill">
                                    <i class="bi bi-signpost me-1"></i><?= e(number_format((float)$row['distance_km'], 1)) ?> km
                                </span>
                            </td>
                            <td><?= status_badge($row['status']) ?></td>
                            <td class="text-end text-nowrap pe-4">
                                <a class="btn btn-outline-dark btn-sm rounded-pill px-3 me-1"
                                   href="<?= url('admin/routes/index.php?id=' . (int)$row['id']) ?>">
                                    <i class="bi bi-pencil me-1"></i>Edit
                                </a>
                                <form method="post" class="d-inline"
                                      data-confirm="Delete this route? Routes used by existing trips cannot be deleted.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm rounded-pill px-2" type="submit" title="Delete route">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="5" class="text-center text-muted py-5">
                            No routes yet. Add the first route using the form on the left.
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Tom Select JS -->
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
(function () {
    /* ── City coordinate table (Haversine) ───────────────────────────── */
    var CITIES = <?php
        $coords = [];
        foreach ($phCities as [$city, $lat, $lng]) {
            $coords[strtolower($city)] = [(float)$lat, (float)$lng];
        }
        echo json_encode($coords, JSON_UNESCAPED_UNICODE);
    ?>;

    function haversine(lat1, lon1, lat2, lon2) {
        var R  = 6371;
        var dL = (lat2 - lat1) * Math.PI / 180;
        var dO = (lon2 - lon1) * Math.PI / 180;
        var a  = Math.sin(dL/2)*Math.sin(dL/2) +
                 Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*
                 Math.sin(dO/2)*Math.sin(dO/2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }

    var inp    = document.getElementById('distance_km');
    var box    = document.getElementById('distanceResult');
    var manualEdit = false;

    function tryCompute() {
        var orig = originSel.getValue().trim().toLowerCase();
        var dest = destSel.getValue().trim().toLowerCase();

        if (!orig || !dest) { box.style.display = 'none'; return; }
        if (orig === dest)  { box.style.display = 'none'; return; }

        var c1 = CITIES[orig];
        var c2 = CITIES[dest];

        if (!c1 || !c2) {
            box.style.display = 'block';
            box.className = 'mt-2 p-2 rounded-2 small bg-warning-subtle text-warning border border-warning-subtle';
            box.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>Coordinates not found for one of the cities — please enter the distance manually.';
            return;
        }

        var km = haversine(c1[0], c1[1], c2[0], c2[1]);
        // Add ~25% for road winding factor
        var roadEst = Math.round(km * 1.25 * 10) / 10;

        if (!manualEdit) {
            inp.value = roadEst.toFixed(2);
        }

        box.style.display = 'block';
        box.className = 'mt-2 p-2 rounded-2 small bg-success-subtle text-success border border-success-subtle';
        box.innerHTML =
            '<i class="bi bi-check-circle-fill me-1"></i>' +
            'Straight-line: <strong>' + (Math.round(km*10)/10).toFixed(1) + ' km</strong> &bull; ' +
            'Est. road distance: <strong>' + roadEst.toFixed(1) + ' km</strong> (auto-filled). ' +
            'Adjust to actual road km if known.';
    }

    /* ── Tom Select — Origin ──────────────────────────────────────────── */
    var originSel = new TomSelect('#origin', {
        create: true,
        sortField: 'text',
        maxOptions: 15,
        placeholder: 'Type to search city...',
        onChange: function() { manualEdit = false; tryCompute(); }
    });

    /* ── Tom Select — Destination ─────────────────────────────────────── */
    var destSel = new TomSelect('#destination', {
        create: true,
        sortField: 'text',
        maxOptions: 15,
        placeholder: 'Type to search city...',
        onChange: function() { manualEdit = false; tryCompute(); }
    });

    /* Manual override stops auto-fill */
    inp.addEventListener('input', function () {
        manualEdit = true;
        box.style.display = 'none';
    });

    /* On edit page load, show distance info if both cities already set */
    tryCompute();
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
