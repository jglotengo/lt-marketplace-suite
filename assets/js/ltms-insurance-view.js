// v2.9.97 P3: filtros client-side para view-insurance (CSP-compliant, sin inline handlers).
document.addEventListener("DOMContentLoaded", function() {
    var search = document.getElementById('ltms-insurance-search');
    var filter = document.getElementById('ltms-insurance-status-filter');
    var rows   = document.querySelectorAll('#ltms-insurance-table tbody tr');
    if (!rows.length) return;

    function applyFilters() {
        var q = (search && search.value ? search.value.toLowerCase().trim() : '');
        var s = (filter && filter.value ? filter.value : '');
        var visible = 0;
        rows.forEach(function(row) {
            var rowSearch = row.getAttribute('data-search') || '';
            var rowStatus = row.getAttribute('data-status') || '';
            var matchesQ = !q || rowSearch.indexOf(q) !== -1;
            var matchesS = !s || rowStatus === s;
            if (matchesQ && matchesS) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });
        // Empty-results notice (toggle).
        var notice = document.getElementById('ltms-insurance-no-results');
        if (!notice) {
            notice = document.createElement('div');
            notice.id = 'ltms-insurance-no-results';
            notice.className = 'ltms-empty-state';
            notice.style.cssText = 'text-align:center;padding:40px 16px;color:#6b7280;';
            notice.textContent = 'No se encontraron pólizas con esos criterios.';
            var table = document.getElementById('ltms-insurance-table');
            if (table && table.parentNode) {
                table.parentNode.appendChild(notice);
            }
        }
        notice.style.display = visible === 0 ? 'block' : 'none';
    }

    if (search) search.addEventListener('input', applyFilters);
    if (filter) filter.addEventListener('change', applyFilters);

    // CSV export.
    var csvBtn = document.getElementById('ltms-insurance-export-csv');
    if (csvBtn) {
        csvBtn.addEventListener('click', function() {
            var csv = 'Pedido,Poliza,Tipo,Prima,Estado,Fecha\n';
            rows.forEach(function(row) {
                if (row.style.display === 'none') return;
                var cells = row.querySelectorAll('td');
                if (cells.length < 7) return;
                var pedido  = (cells[0].textContent || '').trim();
                var poliza  = (cells[1].textContent || '').trim();
                var tipo    = (cells[2].textContent || '').trim();
                var prima   = (cells[3].textContent || '').trim();
                var estado  = (cells[4].textContent || '').trim();
                var fecha   = (cells[6].textContent || '').trim();
                csv += [pedido, poliza, tipo, prima, estado, fecha]
                    .map(function(v) { return '"' + v.replace(/"/g, '""') + '"'; })
                    .join(',') + '\n';
            });
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'seguros_export.csv';
            link.click();
        });
    }
});
