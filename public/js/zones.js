(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    let state = { page: 1, recherche: '', actif: '' };
    let superviseurs = [];

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await loadSuperviseurs();
        await loadZones();
    }

    async function loadSuperviseurs() {
        // Charge les utilisateurs avec rôle SUPERVISEUR_DSA (si tu as l'endpoint)
        // Sinon fallback : charge tous les utilisateurs actifs
        const res = await API.get('/utilisateurs?per_page=100');
        if (res.ok && res.data?.data?.data) {
            superviseurs = res.data.data.data;
            const sel = $('#superviseur_id');
            superviseurs.forEach(u => {
                sel.innerHTML += `<option value="${u.id}">${UI.escapeHtml(u.nom_complet)}</option>`;
            });
        }
    }

    async function loadZones(page = 1) {
        state.page = page;
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page, per_page: 15,
            ...(state.recherche && { q: state.recherche }),
            ...(state.actif !== '' && { actif: state.actif }),
        });

        const res = await API.get('/zones?' + params);
        if (!res.ok) {
            tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-red-500">Erreur de chargement</td></tr>`;
            return;
        }

        renderTable(res.data.data.data);
        UI.renderPagination($('#pagination'), res.data.data, p => loadZones(p));
    }

    function renderTable(zones) {
        const tbody = $('#table-body');
        if (!zones.length) {
            tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucune zone</td></tr>`;
            return;
        }

        tbody.innerHTML = zones.map(z => `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(z.code)}</td>
                <td class="px-4 py-3 font-medium text-gray-900">${UI.escapeHtml(z.nom)}</td>
                <td class="px-4 py-3 text-gray-600">${UI.escapeHtml(z.ville || '—')}</td>
                <td class="px-4 py-3 text-gray-600">${UI.escapeHtml(z.region || '—')}</td>
                <td class="px-4 py-3 text-gray-600">${z.superviseur ? UI.escapeHtml(z.superviseur.nom_complet) : '—'}</td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-indigo-50 text-indigo-700">
                        ${z.agents_count ?? 0} agent(s)
                    </span>
                </td>
                <td class="px-4 py-3">
                    ${z.actif
                        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700">Active</span>'
                        : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">Inactive</span>'}
                </td>
                <td class="px-4 py-3 text-right">
                    <button data-action="edit" data-id="${z.id}" title="Modifier"
                            class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button data-action="delete" data-id="${z.id}" data-name="${UI.escapeHtml(z.nom)}" title="Supprimer"
                            class="px-2 py-1 text-xs text-red-600 bg-red-50 rounded hover:bg-red-100">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`).join('');
    }

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const { action, id, name } = btn.dataset;

        if (action === 'edit') return openEdit(id);
        if (action === 'delete') return confirmDelete(id, name);
    });

    function openCreate() {
        $('#modal-title').textContent = 'Nouvelle zone';
        $('#zone-form').reset();
        $('#zone-id').value = '';
        $('#actif').checked = true;
        UI.openModal('zone-modal');
    }

    async function openEdit(id) {
        const res = await API.get('/zones/' + id);
        if (!res.ok) { UI.toast('danger', 'Zone introuvable'); return; }

        const z = res.data.data;
        $('#modal-title').textContent = 'Modifier la zone';
        $('#zone-id').value = z.id;
        $('#code').value = z.code || '';
        $('#nom').value = z.nom || '';
        $('#ville').value = z.ville || '';
        $('#region').value = z.region || '';
        $('#superviseur_id').value = z.superviseur_id || '';
        $('#rayon_km').value = z.rayon_km || '';
        $('#actif').checked = !!z.actif;
        UI.openModal('zone-modal');
    }

    $('#zone-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = $('#zone-id').value;
        const isEdit = !!id;
        const btn = $('#btn-save');

        const payload = {
            code: $('#code').value.trim(),
            nom: $('#nom').value.trim(),
            ville: $('#ville').value.trim() || null,
            region: $('#region').value.trim() || null,
            superviseur_id: $('#superviseur_id').value || null,
            rayon_km: $('#rayon_km').value || null,
            actif: $('#actif').checked,
        };

        UI.setLoading(btn, true);
        const res = isEdit
            ? await API.put('/zones/' + id, payload)
            : await API.post('/zones', payload);
        UI.setLoading(btn, false);

        if (res.ok) {
            UI.toast('success', isEdit ? 'Zone mise à jour' : 'Zone créée');
            UI.closeModal('zone-modal');
            loadZones(state.page);
        } else {
            const errors = res.data?.errors || res.data?.error || res.data?.message;
            if (typeof errors === 'object') UI.toast('danger', Object.values(errors).flat().join(' · '));
            else UI.toast('danger', errors || 'Erreur');
        }
    });

    function confirmDelete(id, name) {
        UI.confirm(`Supprimer la zone "${name}" ?`, async () => {
            const res = await API.delete('/zones/' + id);
            if (res.ok) { UI.toast('success', 'Zone supprimée'); loadZones(state.page); }
            else UI.toast('danger', res.data?.error || 'Erreur');
        }, { title: 'Supprimer', confirmText: 'Supprimer' });
    }

    $('#search-input').addEventListener('input', UI.debounce(e => {
        state.recherche = e.target.value.trim();
        loadZones(1);
    }, 400));

    $('#filter-actif').addEventListener('change', e => {
        state.actif = e.target.value;
        loadZones(1);
    });

    $('#btn-refresh').addEventListener('click', () => loadZones(state.page));
    $('#btn-create').addEventListener('click', openCreate);

    $$('[data-close-modal]').forEach(b => b.addEventListener('click', () => UI.closeModal('zone-modal')));
    $('#zone-modal').addEventListener('click', e => {
        if (e.target.id === 'zone-modal') UI.closeModal('zone-modal');
    });

    init();
})();s