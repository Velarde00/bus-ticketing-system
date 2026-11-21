// assets/js/script.js
document.addEventListener('DOMContentLoaded', function () {

    // Swap origin and destination on the search form
    var swap = document.getElementById('swapCities');
    if (swap) {
        swap.addEventListener('click', function () {
            var from = document.getElementById('origin');
            var to = document.getElementById('destination');
            if (from && to) {
                var temp = from.value;
                from.value = to.value;
                to.value = temp;

                // Icon rotation animation
                var icon = swap.querySelector('i');
                if (icon) {
                    icon.style.transition = 'transform 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
                    icon.style.transform = 'rotate(180deg)';
                    setTimeout(function () {
                        icon.style.transform = 'rotate(0deg)';
                    }, 300);
                }
            }
        });
    }

    // Ask before doing risky things. Usage: <form data-confirm="..."> or <a data-confirm="...">
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });
    document.querySelectorAll('a[data-confirm]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (!window.confirm(link.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    // Password visibility toggle
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-password-toggle'));
            if (!input) {
                return;
            }

            var showPassword = input.type === 'password';
            input.type = showPassword ? 'text' : 'password';
            button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
            button.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
            var icon = button.querySelector('i');
            if (icon) {
                icon.className = showPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
            }
        });
    });
});
