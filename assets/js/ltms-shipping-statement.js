/**
 * LTMS Shipping Statement — CSV export + form submit.
 * FASE2B P0 FIX (CSP): extracted from inline <script> in view-shipping-statement.php.
 */
(function () {
    'use strict';

    // CSV Export
    var btn = document.getElementById('ltms-shipping-export-csv');
    if (btn) {
        btn.addEventListener('click', function () {
            var rows = document.querySelectorAll('.ltms-table tbody tr');
            var csv = 'Fecha,Pedido,Transportadora,Costo Absorbido,Costo Real\n';
            rows.forEach(function (row) {
                var cells = row.querySelectorAll('td');
                if (cells.length >= 5) {
                    var line = Array.from(cells).slice(0, 5).map(function (c) {
                        return '"' + (c.textContent || '').trim().replace(/"/g, '""') + '"';
                    }).join(',');
                    csv += line + '\n';
                }
            });
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            // Filename comes from data attribute (set by PHP).
            link.download = btn.dataset.filename || 'fletes.csv';
            link.click();
        });
    }

    // CSP-compliant form submit (replaces inline onchange)
    document.querySelectorAll('[data-action="submit-form"]').forEach(function (el) {
        el.addEventListener('change', function () { this.form.submit(); });
    });
})();
