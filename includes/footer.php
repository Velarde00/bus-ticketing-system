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
    <footer class="site-footer">
        <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
            <span><strong><?= e(APP_NAME) ?></strong> Bus Ticketing and Reservation System</span>
            <span>Information Management Project</span>
        </div>
    </footer>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('assets/js/script.js') ?>"></script>
</body>
</html>
