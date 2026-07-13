// v2.9.97 P3: Handlers JS para view-drivers (CSP-compliant, sin inline handlers).
// Cubre: modal agregar/editar, toggle active, toggle available, delete, filtros, settings.
(function() {
    var ajaxUrl = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url) || '/wp-admin/admin-ajax.php';
    var nonce   = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce) || '';

    // ── Helpers ─────────────────────────────────────────────────
    function showToast(msg, type) {
        type = type || 'success';
        var colors = { success: '#16a34a', error: '#dc2626', info: '#2563eb' };
        var toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:' + (colors[type] || colors.info) +
            ';color:#fff;padding:12px 20px;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:99999;' +
            'font-size:0.9rem;font-weight:500;opacity:0;transition:opacity 0.3s,transform 0.3s;transform:translateY(10px);';
        toast.textContent = msg;
        document.body.appendChild(toast);
        requestAnimationFrame(function() {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });
        setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            setTimeout(function() { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 300);
        }, 3000);
    }

    // Reload only used for create/edit (need fresh server-rendered row HTML).
    // Toggle/delete update DOM inline (instant feedback, no reload).
    function refresh() { window.location.reload(); }

    // Update KPIs after a DOM-level change (toggle/delete).
    function updateKpis() {
        var totalRows = document.querySelectorAll('#ltms-drivers-table tbody tr').length;
        var activeRows = 0, availableRows = 0;
        document.querySelectorAll('#ltms-drivers-table tbody tr').forEach(function(r) {
            if (r.style.display === 'none') return;
            if (r.getAttribute('data-status') === 'active') {
                activeRows++;
                var badge = r.querySelector('td:nth-child(5) .ltms-status-badge');
                if (badge && badge.classList.contains('paid')) availableRows++;
            }
        });
        var cards = document.querySelectorAll('.ltms-stat-card .ltms-stat-value');
        if (cards[0]) cards[0].textContent = String(totalRows);
        if (cards[1]) cards[1].textContent = String(activeRows);
        if (cards[2]) cards[2].textContent = String(availableRows);
        if (cards[3]) {
            cards[3].textContent = activeRows > 0 ? 'Habilitado' : 'Deshabilitado';
            cards[3].style.color = activeRows > 0 ? '#16a34a' : '#dc2626';
        }
    }

    // ── Modal: Agregar / Editar ─────────────────────────────────
    var modal       = document.getElementById('ltms-driver-modal');
    var form        = document.getElementById('ltms-driver-form');
    var modalTitle  = document.getElementById('ltms-driver-modal-title');
    var addBtn      = document.getElementById('ltms-add-driver-btn');
    var addBtnEmpty = document.getElementById('ltms-add-driver-btn-empty');

    function openModal(opts) {
        opts = opts || {};
        modalTitle.textContent = opts.edit ? 'Editar repartidor'
                                            : 'Agregar repartidor';
        form.querySelector('[name="driver_id"]').value        = opts.driver_id || 0;
        form.querySelector('[name="driver_name"]').value      = opts.name || '';
        form.querySelector('[name="driver_phone"]').value     = opts.phone || '';
        form.querySelector('[name="driver_vehicle_type"]').value = opts.vehicle || '';
        form.querySelector('[name="driver_vehicle_plate"]').value = opts.plate || '';
        // Document number: never pre-fill (security best practice — re-enter on edit).
        form.querySelector('[name="driver_document_number"]').value = '';
        form.querySelector('[name="driver_document_number"]').placeholder =
            opts.edit ? 'Re-ingresa el documento para confirmar'
                      : '';
        modal.style.display = 'block';
        setTimeout(function() { form.querySelector('[name="driver_name"]').focus(); }, 50);
    }

    function closeModal() { modal.style.display = 'none'; }

    if (addBtn)      addBtn.addEventListener('click', function() { openModal({ edit: false }); });
    if (addBtnEmpty) addBtnEmpty.addEventListener('click', function() { openModal({ edit: false }); });

    modal.querySelectorAll('[data-modal-close]').forEach(function(el) {
        el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'block') closeModal();
    });

    // Submit form (create or update).
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var fd = new FormData(form);
            var submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Guardando...';

            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        showToast(res.data.message || 'Repartidor guardado', 'success');
                        setTimeout(refresh, 800);
                    } else {
                        showToast(res.data || 'Error al guardar', 'error');
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Guardar';
                    }
                })
                .catch(function() {
                    showToast('Error de red', 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Guardar';
                });
        });
    }

    // ── Editar (botón ✏️ en cada fila) ──────────────────────────
    document.querySelectorAll('.ltms-driver-edit').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openModal({
                edit:       true,
                driver_id:  btn.getAttribute('data-driver-id'),
                name:       btn.getAttribute('data-name'),
                phone:      btn.getAttribute('data-phone'),
                vehicle:    btn.getAttribute('data-vehicle'),
                plate:      btn.getAttribute('data-plate'),
            });
        });
    });

    // ── Toggle activo ────────────────────────────────────────────
    document.querySelectorAll('.ltms-driver-toggle-active').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var driverId = btn.getAttribute('data-driver-id');
            var fd = new FormData();
            fd.append('action', 'ltms_toggle_driver_active');
            fd.append('nonce', nonce);
            fd.append('driver_id', driverId);
            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        var newStatus = res.data.new_status || 'active';
                        var row = btn.closest('tr');
                        row.setAttribute('data-status', newStatus);
                        var isActive = (newStatus === 'active');
                        var statusBadge = row.querySelector('td:nth-child(4) .ltms-status-badge');
                        if (statusBadge) {
                            statusBadge.className = 'ltms-status-badge ' + (isActive ? 'delivered' : 'failed');
                            statusBadge.textContent = isActive ? 'Activo' : 'Inactivo';
                        }
                        btn.textContent = isActive ? 'Desactivar' : 'Activar';
                        btn.setAttribute('data-active', isActive ? '1' : '0');
                        updateKpis();
                        showToast('Estado actualizado', 'success');
                    } else {
                        showToast(res.data || 'Error', 'error');
                    }
                })
                .catch(function() { showToast('Error de red', 'error'); });
        });
    });

    // ── Toggle disponible ────────────────────────────────────────
    document.querySelectorAll('.ltms-driver-toggle-available').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var driverId = btn.getAttribute('data-driver-id');
            var fd = new FormData();
            fd.append('action', 'ltms_toggle_driver_available');
            fd.append('nonce', nonce);
            fd.append('driver_id', driverId);
            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        var isAvail = !!res.data.is_available;
                        var row = btn.closest('tr');
                        var badge = row.querySelector('td:nth-child(5) .ltms-status-badge');
                        if (badge) {
                            badge.className = 'ltms-status-badge ' + (isAvail ? 'paid' : 'pending');
                            badge.textContent = isAvail ? 'Disponible' : 'Ocupado';
                        }
                        btn.textContent = isAvail ? 'Marcar ocupado' : 'Marcar disponible';
                        btn.setAttribute('data-available', isAvail ? '1' : '0');
                        updateKpis();
                        showToast('Disponibilidad actualizada', 'success');
                    } else {
                        showToast(res.data || 'Error', 'error');
                    }
                })
                .catch(function() { showToast('Error de red', 'error'); });
        });
    });

    // ── Eliminar (modal de confirmación) ────────────────────────
    var deleteModal     = document.getElementById('ltms-driver-delete-modal');
    var deleteNameEl    = document.getElementById('ltms-delete-driver-name');
    var confirmDeleteBtn = document.getElementById('ltms-confirm-delete-driver');
    var pendingDeleteId = null;

    document.querySelectorAll('.ltms-driver-delete').forEach(function(btn) {
        btn.addEventListener('click', function() {
            pendingDeleteId = btn.getAttribute('data-driver-id');
            deleteNameEl.textContent = btn.getAttribute('data-name') || '';
            deleteModal.style.display = 'block';
        });
    });

    deleteModal.querySelectorAll('[data-delete-modal-close]').forEach(function(el) {
        el.addEventListener('click', function() {
            deleteModal.style.display = 'none';
            pendingDeleteId = null;
        });
    });

    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', function() {
            if (!pendingDeleteId) return;
            var fd = new FormData();
            fd.append('action', 'ltms_delete_driver');
            fd.append('nonce', nonce);
            fd.append('driver_id', pendingDeleteId);
            confirmDeleteBtn.disabled = true;
            confirmDeleteBtn.textContent = 'Eliminando...';
            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        var row = document.querySelector('tr[data-driver-id="' + pendingDeleteId + '"]');
                        if (row && row.parentNode) row.parentNode.removeChild(row);
                        deleteModal.style.display = 'none';
                        pendingDeleteId = null;
                        updateKpis();
                        showToast('Repartidor eliminado', 'success');
                    } else {
                        showToast(res.data || 'Error al eliminar', 'error');
                    }
                    confirmDeleteBtn.disabled = false;
                    confirmDeleteBtn.textContent = 'Sí, eliminar';
                })
                .catch(function() {
                    showToast('Error de red', 'error');
                    confirmDeleteBtn.disabled = false;
                    confirmDeleteBtn.textContent = 'Sí, eliminar';
                });
        });
    }

    // ── Configuración de entrega ────────────────────────────────
    var settingsForm = document.getElementById('ltms-delivery-settings-form');
    if (settingsForm) {
        settingsForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var fd = new FormData(settingsForm);
            var spinner = document.getElementById('ltms-delivery-spinner');
            var msg     = document.getElementById('ltms-delivery-msg');
            var btn     = document.getElementById('ltms-save-delivery-settings');
            btn.disabled = true;
            spinner.style.display = 'inline-block';
            msg.textContent = '';
            msg.style.color = '';
            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    spinner.style.display = 'none';
                    btn.disabled = false;
                    if (res.success) {
                        msg.textContent = res.data || 'Configuración guardada';
                        msg.style.color = '#16a34a';
                        showToast('Configuración guardada', 'success');
                    } else {
                        msg.textContent = res.data || 'Error al guardar';
                        msg.style.color = '#dc2626';
                    }
                })
                .catch(function() {
                    spinner.style.display = 'none';
                    btn.disabled = false;
                    msg.textContent = 'Error de red';
                    msg.style.color = '#dc2626';
                });
        });
    }

    // ── Filtros client-side ─────────────────────────────────────
    var search  = document.getElementById('ltms-driver-search');
    var sFilter = document.getElementById('ltms-driver-status-filter');
    var vFilter = document.getElementById('ltms-driver-vehicle-filter');
    var rows    = document.querySelectorAll('#ltms-drivers-table tbody tr');
    if (rows.length) {
        function applyFilters() {
            var q = search && search.value ? search.value.toLowerCase().trim() : '';
            var s = sFilter && sFilter.value ? sFilter.value : '';
            var v = vFilter && vFilter.value ? vFilter.value : '';
            rows.forEach(function(row) {
                var rs = row.getAttribute('data-search') || '';
                var rst = row.getAttribute('data-status') || '';
                var rv = row.getAttribute('data-vehicle') || '';
                var ok = (!q || rs.indexOf(q) !== -1) && (!s || rst === s) && (!v || rv === v);
                row.style.display = ok ? '' : 'none';
            });
        }
        if (search)  search.addEventListener('input', applyFilters);
        if (sFilter) sFilter.addEventListener('change', applyFilters);
        if (vFilter) vFilter.addEventListener('change', applyFilters);
    }
})();
