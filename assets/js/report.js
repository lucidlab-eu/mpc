document.addEventListener('DOMContentLoaded', function () {
    var button = document.getElementById('lucidlab-mpc-print-report');
    if (button) button.addEventListener('click', function () { window.print(); });
});
