<?php
/** includes/footer.php  Closes what header.php opened. */
$layout = $layout ?? 'public';
?>
<?php if ($layout === 'admin'): ?>
            </main>
        </div>
    </div>
<?php else: ?>
    </main>
    <footer class="site-footer mt-auto">
        <div class="container py-5">
            <div class="row g-4 justify-content-between">
                <div class="col-lg-4">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="brand-icon-wrapper">
                            <i class="bi bi-bus-front-fill"></i>
                        </span>
                        <span class="brand-text text-white">
                            <?= e(APP_NAME) ?>
                            <span class="brand-tag">Express</span>
                        </span>
                    </div>
                    <p class="text-white-50 small pe-lg-4 mb-3">
                        The easiest and most reliable way to book bus tickets across the Philippines. Choose your preferred seats online, get instant digital tickets, and travel with peace of mind.
                    </p>
                </div>

                <div class="col-6 col-md-3 col-lg-2">
                    <h6 class="text-white fw-semibold mb-3">Quick Navigation</h6>
                    <ul class="list-unstyled footer-links small">
                        <li class="mb-2"><a href="<?= url('index.php') ?>"><i class="bi bi-chevron-right me-1"></i> Search Buses</a></li>
                        <li class="mb-2"><a href="<?= url('passenger/my_bookings.php') ?>"><i class="bi bi-chevron-right me-1"></i> My Bookings</a></li>
                        <li class="mb-2"><a href="<?= url('passenger/dashboard.php') ?>"><i class="bi bi-chevron-right me-1"></i> Passenger Dashboard</a></li>
                        <li class="mb-2"><a href="<?= url('passenger/profile.php') ?>"><i class="bi bi-chevron-right me-1"></i> Account Settings</a></li>
                    </ul>
                </div>

                <div class="col-6 col-md-3 col-lg-2">
                    <h6 class="text-white fw-semibold mb-3">Top Terminals</h6>
                    <ul class="list-unstyled footer-links small text-white-50">
                        <li class="mb-2"><i class="bi bi-geo-alt me-1 text-warning"></i> Cubao / Manila</li>
                        <li class="mb-2"><i class="bi bi-geo-alt me-1 text-warning"></i> Baguio City</li>
                        <li class="mb-2"><i class="bi bi-geo-alt me-1 text-warning"></i> Cebu South Bus Terminal</li>
                        <li class="mb-2"><i class="bi bi-geo-alt me-1 text-warning"></i> Davao Overland Terminal</li>
                    </ul>
                </div>

                <div class="col-md-5 col-lg-3">
                    <h6 class="text-white fw-semibold mb-3">Need Help?</h6>
                    <p class="text-white-50 small mb-2">Have questions about your booking or scheduled trip?</p>
                    <div class="d-flex align-items-center gap-2 text-white small mb-2">
                        <i class="bi bi-telephone-fill text-warning"></i>
                        <span>+63 (2) 8800-BUS-1</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 text-white small">
                        <i class="bi bi-envelope-fill text-warning"></i>
                        <span>support@busticket.ph</span>
                    </div>
                </div>
            </div>

            <div class="footer-bottom border-top border-secondary border-opacity-25 pt-4 mt-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 small text-white-50">
            </div>
        </div>
    </footer>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('assets/js/script.js') ?>"></script>
</body>
</html>
