/**
 * LTMS Insurance — client-side filters + CSV export.
 * FASE2B P0 FIX (CSP): extracted from inline <script> in view-insurance.php.
 */
(function () {
    'use strict';
    var search = document.getElementById('ltms-insurance-search');
    var filter = document.getElementById('ltms-insurance-status-filter');
    var rows = document.querySelectorAll('#ltms-insurance-table tbody tr');
    if (!rows.length) return;

    function applyFilters() {
        var q = (search && search.value ? search.value.toLowerCase().trim() : '');
        var s = (filter && filter.value ? filter.value : '');
        var visible = 0;
        rows.forEach(function (row) {
            var rowSearch = row.getAttribute('data-search') || '';
            var rowStatus = row.getAttribute('data-status') || '';
            if ((!q || rowSearch.indexOf(q) !== -1) && (!s || rowStatus === s)) {
                row.style.display = ''; visible++;
            } else { row.style.display = 'none'; }
        });
        var notice = document.getElementById('ltms-insurance-no-results');
        if (!notice) {
            notice = document.createElement('div');
            notice.id = 'ltms-insurance-no-results';
            notice.className = 'ltms-empty-state';
            notice.style.cssText = 'text-align:center;padding:40px 16px;color:#6b7280;';
            notice.textContent = 'No se encontraron pólizas con esos criterios.';
            var table = document.getElementById('ltms-insurance-table');
            if (table && table.parentNode) table.parentNode.appendChild(notice);
        }
        notice.style.display = visible === 0 ? 'block' : 'none';
    }

    if (search) search.addEventListener('input', applyFilters);
    if (filter) filter.addEventListener('change', applyFilters);

    var csvBtn = document.getElementById('ltms-insurance-export-csv');
    if (csvBtn) {
        csvBtn.addEventListener('click', function () {
            var csv = 'Pedido,Poliza,Tipo,Prima,Estado,Fecha\n';
            rows.forEach(function (row) {
                if (row.style.display === 'none') return;
                var cells = row.querySelectorAll('td');
                if (cells.length < 7) return;
                var vals = [];
                for (var i = 0; i < 7; i++) {
                    if (i === 5) continue; // skip column 5
                    vals.push((cells[i].textContent || '').trim());
                }
                csv += vals.map(function (v) { return '"' + v.replace(/"/g, '""') + '"'; }).join(',') + '\n';
            });
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = csvBtn.dataset.filename || 'seguros.csv';
            link.click();
        });
    }
})();
