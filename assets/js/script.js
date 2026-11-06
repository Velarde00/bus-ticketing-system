// assets/js/script.js
document.addEventListener('DOMContentLoaded', function () {

    // Swap origin and destination on the search form
    var swap = document.getElementById('swapCities');
    if (swap) {
        swap.addEventListener('click', function () {
            var from = document.getElementById('origin');
            var to = document.getElementById('destination');
            var temp = from.value;
            from.value = to.value;
            to.value = temp;
        });
    }

    // Ask before doing risky things. Usage: <form data-confirm="Delete this bus?"> or <a data-confirm="...">
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
            button.querySelector('i').className = showPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    });
});
