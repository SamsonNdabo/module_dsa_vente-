(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    const $ = sel => document.querySelector(sel);
    let state = { recherche: '', module: '', action: '' };

    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await Promise.all([loadModules(), loadStats()]);
        await loadPermissions();
    }

    async function loadModules() {
        const res = await API.get('/permissions/modules');
        if (res.ok && res.data?.data) {
            res.data.data.forEach(m => {
                $('#filter-module').innerHTML += `<option value="${m.nom}">${UI.escapeHtml(m.nom)} (${m.total})</option>`;
            });
        }
    }

    async function loadStats() {
        const res = await API.get('/permissions/statistiques');
        if (res.ok && res.data?.data) {
            const s = res.data.data;
            $('#stat-total').textContent = s.total_permissions ?? 0;
            $('#stat-systeme').textContent = s.permissions_systeme ?? 0;
            $('#stat-assignees').textContent = s.permissions_assignees ?? 0;
            $('#stat-non-assignees').textContent = s.permissions_non_assignees ?? 0;
        }
    }

    async function loadPermissions() {
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="6" class="p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            ...(state.recherche && { recherche: state.recherche }),
            ...(state.module && { module: state.module }),
            ...(state.action && { action: state.action }),
        });

        const res = await API.get('/permissions?' + params);
        if (!res.ok) {
            tbody.innerHTML = `<tr><td colspan="6" class="p-10 text-center text-red-500">Erreur</td></tr>`;
            return;
        }

        const perms = res.data.data || [];
        if (!perms.length) {
            tbody.innerHTML = `<tr><td colspan="6" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucune permission</td></tr>`;
            return;
        }

        // Actions uniques pour le filtre
        const actions = [...new Set(perms.map(p => p.action).filter(Boolean))].sort();
        const currentAction = $('#filter-action').value;
        $('#filter-action').innerHTML = '<option value="">Toutes les actions</option>' +
            actions.map(a => `<option value="${a}" ${currentAction === a ? 'selected' : ''}>${UI.escapeHtml(a)}</option>`).join('');

        tbody.innerHTML = perms.map(p => `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(p.permission_code)}</td>
                <td class="px-4 py-3"><span class="px-2 py-1 text-xs bg-indigo-50 text-indigo-700 rounded-full font-semibold">${UI.escapeHtml(p.module)}</span></td>
                <td class="px-4 py-3 text-gray-600">${UI.escapeHtml(p.action || '—')}</td>
                <td class="px-4 py-3 text-gray-600 text-xs">${UI.escapeHtml(p.description || '—')}</td>
                <td class="px-4 py-3"><span class="text-xs">${p.roles_count ?? 0}</span></td>
                <td class="px-4 py-3">
                    ${p.est_systeme
                        ? '<span class="px-2 py-1 text-xs bg-amber-50 text-amber-700 rounded-full">Système</span>'
                        : '<span class="px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded-full">Custom</span>'}
                </td>
            </tr>`).join('');
    }

    $('#search-input').addEventListener('input', UI.debounce(e => {
        state.recherche = e.target.value.trim();
        loadPermissions();
    }, 400));

    $('#filter-module').addEventListener('change', e => { state.module = e.target.value; loadPermissions(); });
    $('#filter-action').addEventListener('change', e => { state.action = e.target.value; loadPermissions(); });

    init();
})();