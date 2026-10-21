(function () {
    'use strict';

    if (!document.getElementById('roles-grid')) return;

    let state = { page: 1, recherche: '' };
    let allPermissions = [];
    let selectedPermissionIds = new Set();

    const $ = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await loadPermissions();
        await loadRoles();
    }

    async function loadPermissions() {
        const res = await API.get('/permissions');
        if (res.ok) allPermissions = res.data.data || [];
    }

    async function loadRoles(page = 1) {
        state.page = page;
        const grid = $('#roles-grid');
        grid.innerHTML = `<div class="col-span-full text-center p-10 text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></div>`;

        const params = new URLSearchParams({
            page, per_page: 12,
            ...(state.recherche && { recherche: state.recherche }),
        });

        const res = await API.get('/roles?' + params);
        if (!res.ok) {
            grid.innerHTML = `<div class="col-span-full text-center p-10 text-red-500">Erreur de chargement</div>`;
            return;
        }

        renderRoles(res.data.data.data);
        UI.renderPagination($('#pagination'), res.data.data, p => loadRoles(p));
    }

    function renderRoles(roles) {
        const grid = $('#roles-grid');
        if (!roles.length) {
            grid.innerHTML = `<div class="col-span-full text-center p-10 text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucun rôle</div>`;
            return;
        }

        grid.innerHTML = roles.map(r => `
            <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 ${r.est_systeme ? 'border-amber-500' : 'border-indigo-600'}">
                <div class="flex items-start justify-between mb-2">
                    <div>
                        <h3 class="font-bold text-gray-900">${UI.escapeHtml(r.nom_role)}</h3>
                        <p class="text-xs text-gray-500 font-mono">${UI.escapeHtml(r.code)}</p>
                    </div>
                    ${r.est_systeme ? '<span class="px-2 py-1 text-xs font-semibold bg-amber-50 text-amber-700 rounded-full">Système</span>' : ''}
                </div>
                <p class="text-sm text-gray-600 mb-3 line-clamp-2">${UI.escapeHtml(r.description || '—')}</p>
                <div class="flex items-center justify-between text-xs text-gray-500 mb-3">
                    <span><i class="fas fa-layer-group mr-1"></i>Niveau ${r.niveau_acces}</span>
                    <span><i class="fas fa-users mr-1"></i>${r.utilisateurs_count ?? 0} utilisateur(s)</span>
                </div>
                <div class="flex gap-1 pt-2 border-t border-gray-100">
                    <button data-action="edit" data-id="${r.id}"
                            class="flex-1 px-2 py-1.5 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100">
                        <i class="fas fa-edit"></i> Modifier
                    </button>
                    <button data-action="permissions" data-id="${r.id}"
                            class="flex-1 px-2 py-1.5 text-xs text-purple-600 bg-purple-50 rounded hover:bg-purple-100">
                        <i class="fas fa-key"></i> Perms
                    </button>
                    ${!r.est_systeme ? `<button data-action="delete" data-id="${r.id}" data-name="${UI.escapeHtml(r.nom_role)}"
                            class="px-2 py-1.5 text-xs text-red-600 bg-red-50 rounded hover:bg-red-100">
                        <i class="fas fa-trash"></i></button>` : ''}
                </div>
            </div>`).join('');
    }

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const { action, id, name } = btn.dataset;
        if (action === 'edit') return openEdit(id);
        if (action === 'permissions') return openPermissions(id);
        if (action === 'delete') return confirmDelete(id, name);
    });

    function openCreate() {
        $('#modal-title').textContent = 'Nouveau rôle';
        $('#role-form').reset();
        $('#role-id').value = '';
        selectedPermissionIds.clear();
        renderPermissionsList();
        UI.openModal('role-modal');
    }

    async function openEdit(id) {
        const res = await API.get('/roles/' + id);
        if (!res.ok) { UI.toast('danger', 'Rôle introuvable'); return; }
        const r = res.data.data;

        $('#modal-title').textContent = 'Modifier le rôle';
        $('#role-id').value = r.id;
        $('#code').value = r.code || '';
        $('#nom_role').value = r.nom_role || '';
        $('#niveau_acces').value = r.niveau_acces || 1;
        $('#description').value = r.description || '';

        selectedPermissionIds = new Set((r.permissions || []).map(p => p.id));
        renderPermissionsList();
        UI.openModal('role-modal');
    }

    async function openPermissions(id) {
        await openEdit(id);
        // focus sur les permissions
        $('#permissions-list').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function renderPermissionsList() {
        const container = $('#permissions-list');
        if (!allPermissions.length) {
            container.innerHTML = '<div class="text-center text-gray-400 text-sm py-4">Aucune permission</div>';
            return;
        }

        const grouped = {};
        allPermissions.forEach(p => {
            const mod = p.module || 'Général';
            if (!grouped[mod]) grouped[mod] = [];
            grouped[mod].push(p);
        });

        let html = '';
        Object.keys(grouped).sort().forEach(mod => {
            html += `<div class="mb-3">
                <div class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">${UI.escapeHtml(mod)}</div>
                <div class="space-y-1">`;
            grouped[mod].forEach(p => {
                const checked = selectedPermissionIds.has(p.id) ? 'checked' : '';
                html += `<label class="flex items-start gap-2 p-1.5 rounded hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" class="mt-0.5 permission-checkbox" data-id="${p.id}" ${checked}>
                    <div class="flex-1 text-sm">
                        <div class="font-medium text-gray-800">${UI.escapeHtml(p.permission_code)}</div>
                        ${p.description ? `<div class="text-xs text-gray-500">${UI.escapeHtml(p.description)}</div>` : ''}
                    </div>
                </label>`;
            });
            html += '</div></div>';
        });
        container.innerHTML = html;

        container.querySelectorAll('.permission-checkbox').forEach(cb => {
            cb.addEventListener('change', e => {
                const id = parseInt(e.target.dataset.id);
                if (e.target.checked) selectedPermissionIds.add(id);
                else selectedPermissionIds.delete(id);
                $('#perm-count').textContent = `${selectedPermissionIds.size} sélectionnée(s)`;
            });
        });
        $('#perm-count').textContent = `${selectedPermissionIds.size} sélectionnée(s)`;
    }

    $('#role-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = $('#role-id').value;
        const isEdit = !!id;
        const btn = $('#btn-save');

        const payload = {
            code: $('#code').value.trim(),
            nom_role: $('#nom_role').value.trim(),
            description: $('#description').value.trim() || null,
            niveau_acces: parseInt($('#niveau_acces').value),
            permissions: [...selectedPermissionIds],
        };

        UI.setLoading(btn, true);
        const res = isEdit
            ? await API.put('/roles/' + id, payload)
            : await API.post('/roles', payload);
        UI.setLoading(btn, false);

        if (res.ok) {
            UI.toast('success', isEdit ? 'Rôle mis à jour' : 'Rôle créé');
            UI.closeModal('role-modal');
            loadRoles(state.page);
        } else {
            const errors = res.data?.errors || res.data?.error || res.data?.message;
            if (typeof errors === 'object') UI.toast('danger', Object.values(errors).flat().join(' · '));
            else UI.toast('danger', errors || 'Erreur');
        }
    });

    function confirmDelete(id, name) {
        UI.confirm(`Supprimer le rôle "${name}" ?`, async () => {
            const res = await API.delete('/roles/' + id);
            if (res.ok) { UI.toast('success', 'Rôle supprimé'); loadRoles(state.page); }
            else UI.toast('danger', res.data?.error || 'Erreur');
        }, { title: 'Supprimer', confirmText: 'Supprimer' });
    }

    $('#search-input').addEventListener('input', UI.debounce(e => {
        state.recherche = e.target.value.trim();
        loadRoles(1);
    }, 400));

    $('#btn-refresh').addEventListener('click', () => loadRoles(state.page));
    $('#btn-create').addEventListener('click', openCreate);

    $$('[data-close-modal]').forEach(b => b.addEventListener('click', () => UI.closeModal('role-modal')));
    $('#role-modal').addEventListener('click', e => {
        if (e.target.id === 'role-modal') UI.closeModal('role-modal');
    });

    init();
})();