(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    let state = {
        page: 1,
        recherche: '',
        entite_id: '',
        ville: '',
        statut: '',
        est_active: '',
    };

    let entites  = [];
    let villes   = [];
    let responsables = [];

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    // ─────────────────────────────────────────────────────────
    // INIT
    // ─────────────────────────────────────────────────────────
    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await loadOptions();
        await loadSuccursales();
        attachEvents();
    }

    // ─────────────────────────────────────────────────────────
    // OPTIONS (filtres + modal)
    // ─────────────────────────────────────────────────────────
    async function loadOptions() {
        try {
            const res = await API.get('/succursales/options');
            if (!res.ok) return;

            const data = res.data?.data ?? {};
            entites = data.entites ?? [];
            villes  = data.villes ?? [];

            // Filtre entités
            const filterEntite = $('#filter-entite');
            entites.forEach(e => {
                filterEntite.innerHTML += `<option value="${e.id}">${UI.escapeHtml(e.nom)}</option>`;
            });

            // Filtre villes
            const filterVille = $('#filter-ville');
            villes.forEach(v => {
                filterVille.innerHTML += `<option value="${UI.escapeHtml(v)}">${UI.escapeHtml(v)}</option>`;
            });

            // Select entité (modal)
            const formEntite = $('#entite_id');
            entites.forEach(e => {
                formEntite.innerHTML += `<option value="${e.id}">${UI.escapeHtml(e.code)} — ${UI.escapeHtml(e.nom)}</option>`;
            });
        } catch (e) {
            console.error('[SUCCURSALES] options:', e);
        }
    }

    async function loadResponsablesPotentiels() {
        const sel = $('#responsable_id');
        if (!sel) return;
        sel.innerHTML = '<option value="">— Aucun —</option>';

        try {
            const res = await API.get('/succursales/responsables-potentiels');
            if (res.ok && Array.isArray(res.data?.data)) {
                responsables = res.data.data;
                responsables.forEach(r => {
                    sel.innerHTML += `<option value="${r.id}">${UI.escapeHtml(r.nom_complet)} (${UI.escapeHtml(r.email)})</option>`;
                });
            }
        } catch (e) {
            console.error('[SUCCURSALES] responsables:', e);
        }
    }

    // ─────────────────────────────────────────────────────────
    // LISTE
    // ─────────────────────────────────────────────────────────
    async function loadSuccursales(page = 1) {
        state.page = page;
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="9" class="p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page, per_page: 15,
            ...(state.recherche   && { recherche: state.recherche }),
            ...(state.entite_id   && { entite_id: state.entite_id }),
            ...(state.ville       && { ville: state.ville }),
            ...(state.statut      && { statut: state.statut }),
            ...(state.est_active !== '' && { est_active: state.est_active }),
        });

        const res = await API.get('/succursales?' + params);
        if (!res.ok) {
            tbody.innerHTML = `<tr><td colspan="9" class="p-10 text-center text-red-500">Erreur de chargement</td></tr>`;
            return;
        }

        const paginator = res.data.data;
        renderTable(paginator.data);
        computeStats(res.data.stats ?? {});
        UI.renderPagination($('#pagination'), paginator, p => loadSuccursales(p));
    }

    function computeStats(stats) {
        $('#stat-total').textContent       = stats.total ?? '—';
        $('#stat-actives').textContent     = stats.actives ?? '—';
        $('#stat-responsable').textContent = stats.avec_responsable ?? '—';
        $('#stat-principales').textContent = stats.principales ?? '—';
    }

    function renderTable(items) {
        const tbody = $('#table-body');
        if (!items.length) {
            tbody.innerHTML = `<tr><td colspan="9" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucune succursale</td></tr>`;
            return;
        }

        tbody.innerHTML = items.map(s => {
            const statutBadge = {
                ACTIVE:      '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700">Active</span>',
                INACTIVE:    '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">Inactive</span>',
                MAINTENANCE: '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-amber-50 text-amber-700">Maintenance</span>',
            }[s.statut] || '—';

            return `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(s.code)}</td>
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-900">${UI.escapeHtml(s.nom)}</div>
                    ${s.est_principale ? '<div class="text-[10px] text-indigo-600 font-semibold"><i class="fas fa-star text-[9px] mr-1"></i>Principale</div>' : ''}
                </td>
                <td class="px-4 py-3 text-xs text-gray-600">${UI.escapeHtml(s.ville ?? '—')}</td>
                <td class="px-4 py-3 text-xs text-gray-600">${UI.escapeHtml(s.province ?? '—')}</td>
                <td class="px-4 py-3 text-xs text-gray-600">${s.entite ? UI.escapeHtml(s.entite.nom) : '—'}</td>
                <td class="px-4 py-3 text-xs text-gray-600">${s.responsable ? UI.escapeHtml(s.responsable.nom_complet) : '<span class="text-gray-400 italic">Non assigné</span>'}</td>
                <td class="px-4 py-3">${statutBadge}</td>
                <td class="px-4 py-3">
                    ${s.est_active
                        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700">Oui</span>'
                        : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-50 text-red-700">Non</span>'}
                </td>
                <td class="px-4 py-3 text-right">
                    <button data-action="edit" data-id="${s.id}"
                            class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100" title="Éditer">
                        <i class="fas fa-edit"></i>
                    </button>
                    ${s.est_active
                        ? `<button data-action="desactiver" data-id="${s.id}" data-name="${UI.escapeHtml(s.nom)}"
                                class="px-2 py-1 text-xs text-amber-600 bg-amber-50 rounded hover:bg-amber-100" title="Désactiver">
                            <i class="fas fa-pause"></i>
                        </button>`
                        : `<button data-action="activer" data-id="${s.id}"
                                class="px-2 py-1 text-xs text-emerald-600 bg-emerald-50 rounded hover:bg-emerald-100" title="Activer">
                            <i class="fas fa-play"></i>
                        </button>`}
                    <button data-action="delete" data-id="${s.id}" data-name="${UI.escapeHtml(s.nom)}"
                            class="px-2 py-1 text-xs text-red-600 bg-red-50 rounded hover:bg-red-100" title="Supprimer">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`;
        }).join('');
    }

    // ─────────────────────────────────────────────────────────
    // ÉVÉNEMENTS
    // ─────────────────────────────────────────────────────────
    function attachEvents() {
        // Filtres
        $('#search-input').addEventListener('input', UI.debounce(e => {
            state.recherche = e.target.value.trim();
            loadSuccursales(1);
        }, 350));
        $('#filter-entite').addEventListener('change', e => { state.entite_id = e.target.value; loadSuccursales(1); });
        $('#filter-ville').addEventListener('change',   e => { state.ville = e.target.value; loadSuccursales(1); });
        $('#filter-statut').addEventListener('change',  e => { state.statut = e.target.value; loadSuccursales(1); });
        $('#filter-active').addEventListener('change',  e => { state.est_active = e.target.value; loadSuccursales(1); });

        $('#btn-refresh').addEventListener('click', () => loadSuccursales(state.page));
        $('#btn-create').addEventListener('click', openCreate);
        $('#btn-export').addEventListener('click', exportCsv);

        // Actions table
        document.addEventListener('click', async e => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const { action, id, name } = btn.dataset;
            if (action === 'edit')       return openEdit(parseInt(id));
            if (action === 'delete')     return confirmDelete(parseInt(id), name);
            if (action === 'activer')    return activer(parseInt(id));
            if (action === 'desactiver') return desactiver(parseInt(id), name);
        });

        // Submit modal
        $('#succursale-form').addEventListener('submit', onSubmit);

        // Fermeture modal
        $$('[data-close-modal]').forEach(b => b.addEventListener('click', () => UI.closeModal('succursale-modal')));
        $('#succursale-modal').addEventListener('click', e => {
            if (e.target.id === 'succursale-modal') UI.closeModal('succursale-modal');
        });
    }

    // ─────────────────────────────────────────────────────────
    // CRUD
    // ─────────────────────────────────────────────────────────
    async function openCreate() {
        $('#modal-title').textContent = 'Nouvelle succursale';
        $('#succursale-form').reset();
        $('#succursale-id').value = '';
        $('#statut').value = 'ACTIVE';
        $('#est_active').checked = true;
        $('#est_principale').checked = false;
        $('#pays').value = 'RDC';
        $('#code').disabled = false;
        await loadResponsablesPotentiels();
        UI.openModal('succursale-modal');
    }

    async function openEdit(id) {
        const res = await API.get('/succursales/' + id);
        if (!res.ok) { UI.toast('danger', 'Succursale introuvable'); return; }

        const s = res.data.data;
        $('#modal-title').textContent = 'Modifier la succursale';
        $('#succursale-id').value = s.id;
        $('#code').value = s.code || '';
        $('#code').disabled = true; // non modifiable
        $('#nom').value = s.nom || '';
        $('#type').value = s.type || '';
        $('#entite_id').value = s.entite_id || '';
        $('#telephone').value = s.telephone || '';
        $('#email').value = s.email || '';
        $('#adresse').value = s.adresse || '';
        $('#ville').value = s.ville || '';
        $('#province').value = s.province || '';
        $('#pays').value = s.pays || 'RDC';
        $('#code_postal').value = s.code_postal || '';
        $('#zone_geo').value = s.zone_geo || '';
        $('#capacite_maximale').value = s.capacite_maximale || '';
        $('#est_principale').checked = !!s.est_principale;
        $('#est_active').checked = !!s.est_active;
        $('#statut').value = s.statut || 'ACTIVE';

        await loadResponsablesPotentiels();
        $('#responsable_id').value = s.responsable_id || '';

        UI.openModal('succursale-modal');
    }

    async function onSubmit(e) {
        e.preventDefault();
        const id = $('#succursale-id').value;
        const isEdit = !!id;
        const btn = $('#btn-save');

        const payload = {
            nom:           $('#nom').value.trim(),
            type:          $('#type').value.trim() || null,
            entite_id:     $('#entite_id').value || null,
            telephone:     $('#telephone').value.trim(),
            email:         $('#email').value.trim(),
            adresse:       $('#adresse').value.trim(),
            ville:         $('#ville').value.trim(),
            province:      $('#province').value.trim() || null,
            pays:          $('#pays').value.trim() || null,
            code_postal:   $('#code_postal').value.trim() || null,
            zone_geo:      $('#zone_geo').value.trim() || null,
            capacite_maximale: $('#capacite_maximale').value || null,
            est_principale: $('#est_principale').checked,
            est_active:    $('#est_active').checked,
            statut:        $('#statut').value,
            responsable_id: $('#responsable_id').value || null,
        };

        if (!isEdit) {
            payload.code = $('#code').value.trim() || null;
        }

        UI.setLoading(btn, true);
        const res = isEdit
            ? await API.put('/succursales/' + id, payload)
            : await API.post('/succursales', payload);
        UI.setLoading(btn, false);

        if (res.ok) {
            UI.toast('success', isEdit ? 'Succursale mise à jour' : 'Succursale créée');
            UI.closeModal('succursale-modal');
            loadSuccursales(state.page);
        } else {
            const errs = res.data?.errors || res.data?.error || res.data?.message;
            if (typeof errs === 'object') UI.toast('danger', Object.values(errs).flat().join(' · '));
            else UI.toast('danger', errs || 'Erreur');
        }
    }

    async function activer(id) {
        const res = await API.post('/succursales/' + id + '/activer');
        if (res.ok) { UI.toast('success', 'Succursale activée'); loadSuccursales(state.page); }
        else UI.toast('danger', res.data?.error || 'Erreur');
    }

    async function desactiver(id, name) {
        UI.confirm(`Désactiver la succursale "${name}" ?`, async () => {
            const res = await API.post('/succursales/' + id + '/desactiver', { motif: null });
            if (res.ok) { UI.toast('success', 'Succursale désactivée'); loadSuccursales(state.page); }
            else UI.toast('danger', res.data?.error || res.data?.message || 'Erreur');
        }, { title: 'Désactiver', confirmText: 'Désactiver' });
    }

    function confirmDelete(id, name) {
        UI.confirm(`Supprimer définitivement la succursale "${name}" ?`, async () => {
            const res = await API.delete('/succursales/' + id);
            if (res.ok) { UI.toast('success', 'Succursale supprimée'); loadSuccursales(state.page); }
            else UI.toast('danger', res.data?.error || 'Erreur');
        }, { title: 'Supprimer', confirmText: 'Supprimer' });
    }

    async function exportCsv() {
        const params = new URLSearchParams({
            ...(state.entite_id   && { entite_id: state.entite_id }),
            ...(state.statut      && { statut: state.statut }),
            ...(state.est_active !== '' && { est_active: state.est_active }),
        });

        const token = localStorage.getItem('auth_token');
        const url = window.APP_BASE + '/api/succursales/export?' + params;
        const res = await fetch(url, { headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'text/csv' } });

        if (!res.ok) { UI.toast('danger', 'Erreur d\'export'); return; }

        const blob = await res.blob();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'succursales_' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(a.href);
    }

    init();
})();