(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    let state = { page: 1, q: '', campagne_id: '', agent_dsa_id: '', type: '', statut: '' };
    let partenaires = [], campagnes = [], agents = [], zones = [], produits = [];

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    async function init() {
        if (!API.isAuthenticated()) { window.location.href = window.APP_BASE + '/login'; return; }
        try {
            await loadSelects();
            await loadObjectifs();
            attachEvents();
        } catch (e) {
            console.error('[OBJECTIFS] init error:', e);
            UI.toast('danger', 'Erreur de chargement');
        }
    }

    async function loadSelects() {
        const results = await Promise.allSettled([
            API.get('/dsa/partenaires/options'),
            API.get('/dsa/campagnes/options'),
            API.get('/dsa/agents/options'),
            API.get('/dsa/zones/options'),
            API.get('/dsa/produits/options'),
        ]);

        const [p, c, a, z, pr] = results;
        const extract = (r) => (!r || r.status !== 'fulfilled' || !r.value.ok) ? [] : (r.value.data?.data ?? r.value.data ?? []);

        partenaires = extract(p);
        campagnes   = extract(c);
        agents      = extract(a);
        zones       = extract(z);
        produits    = extract(pr);

        fillSelect('#filter-campagne', campagnes.map(c => ({ value: c.id, label: c.code + ' — ' + c.nom })), false);
        fillSelect('#campagne_id', campagnes.map(c => ({ value: c.id, label: c.code + ' — ' + c.nom })), true);
        fillSelect('#filter-agent', agents.map(a => ({ value: a.id, label: a.nom_complet })), false);
        fillSelect('#agent_dsa_id', agents.map(a => ({ value: a.id, label: a.nom_complet })), true);
        fillSelect('#partenaire_id', partenaires.map(p => ({ value: p.id, label: p.nom })), false);
        fillSelect('#zone_id', zones.map(z => ({ value: z.id, label: z.code + ' — ' + z.nom })), true);
        fillSelect('#produit_dsa_id', produits.map(p => ({ value: p.id, label: p.code + ' — ' + p.nom })), true);
    }

    function fillSelect(sel, items, keepFirst = false) {
        const el = $(sel);
        if (!el) return;
        if (!keepFirst) el.innerHTML = '<option value="">— Choisir —</option>';
        items.forEach(i => el.insertAdjacentHTML('beforeend', `<option value="${i.value}">${UI.escapeHtml(i.label)}</option>`));
    }

    async function loadObjectifs(page = 1) {
        state.page = page;
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="10" class="p-10 text-center text-gray-400"><i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page, per_page: 15,
            ...(state.q && { q: state.q }),
            ...(state.campagne_id && { campagne_id: state.campagne_id }),
            ...(state.agent_dsa_id && { agent_dsa_id: state.agent_dsa_id }),
            ...(state.type && { type: state.type }),
            ...(state.statut && { statut: state.statut }),
        });

        try {
            const res = await API.get('/dsa/objectifs?' + params);
            if (!res.ok) { tbody.innerHTML = `<tr><td colspan="10" class="p-10 text-center text-red-500">Erreur ${res.status}</td></tr>`; return; }

            const pag = res.data.data || res.data;
            const items = pag.data || [];
            renderTable(items);
            computeStats(items);
            if (UI.renderPagination) UI.renderPagination($('#pagination'), pag, p => loadObjectifs(p));
        } catch (e) {
            console.error(e);
            tbody.innerHTML = `<tr><td colspan="10" class="p-10 text-center text-red-500">Exception : ${UI.escapeHtml(e.message)}</td></tr>`;
        }
    }

    function computeStats(items) {
        document.getElementById('stat-total').textContent = items.length;
        document.getElementById('stat-atteints').textContent = items.filter(o => o.statut === 'ATTEINT' || o.statut === 'DEPASSE').length;
        document.getElementById('stat-en-cours').textContent = items.filter(o => o.statut === 'ACTIF').length;
        document.getElementById('stat-retard').textContent = items.filter(o => o.statut === 'ACTIF' && new Date(o.periode_fin) < new Date() && Number(o.valeur_realisee) < Number(o.valeur_cible)).length;
    }

    function renderTable(items) {
        const tbody = $('#table-body');
        if (!items.length) {
            tbody.innerHTML = `<tr><td colspan="10" class="p-10 text-center text-gray-400"><i class="fas fa-inbox text-3xl mb-2 block"></i>Aucun objectif</td></tr>`;
            return;
        }

        tbody.innerHTML = items.map(o => {
            const cible = Number(o.valeur_cible);
            const realise = Number(o.valeur_realisee);
            const pct = cible > 0 ? Math.min(100, Math.round((realise / cible) * 100)) : 0;
            const barreColor = pct >= 100 ? 'bg-emerald-500' : pct >= 50 ? 'bg-amber-500' : 'bg-red-500';

            const statutBadge = {
                PLANIFIE: '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-sky-50 text-sky-700">Planifié</span>',
                ACTIF:    '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700">Actif</span>',
                ATTEINT:  '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700">Atteint</span>',
                DEPASSE:  '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-purple-50 text-purple-700">Dépassé</span>',
                ANNULE:   '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">Annulé</span>',
            }[o.statut] || '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">—</span>';

            return `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(o.code)}</td>
                <td class="px-4 py-3 text-sm">${UI.escapeHtml(o.libelle)}</td>
                <td class="px-4 py-3 text-xs text-gray-600">${o.campagne ? UI.escapeHtml(o.campagne.nom) : '—'}</td>
                <td class="px-4 py-3 text-xs text-gray-600">${o.agent ? UI.escapeHtml(o.agent.prenom + ' ' + o.agent.nom) : '—'}</td>
                <td class="px-4 py-3 text-xs">${UI.escapeHtml(o.type_objectif)}</td>
                <td class="px-4 py-3 text-xs">${cible.toLocaleString('fr-FR')} ${UI.escapeHtml(o.unite || '')}</td>
                <td class="px-4 py-3 text-xs">${realise.toLocaleString('fr-FR')} ${UI.escapeHtml(o.unite || '')}</td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                        <div class="flex-1 bg-gray-100 rounded-full h-2 overflow-hidden">
                            <div class="h-full ${barreColor}" style="width:${pct}%"></div>
                        </div>
                        <span class="text-xs font-semibold">${pct}%</span>
                    </div>
                </td>
                <td class="px-4 py-3">${statutBadge}</td>
                <td class="px-4 py-3 text-right">
                    <button data-action="edit" data-id="${o.id}" class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100"><i class="fas fa-edit"></i></button>
                    <button data-action="delete" data-id="${o.id}" data-name="${UI.escapeHtml(o.libelle)}" class="px-2 py-1 text-xs text-red-600 bg-red-50 rounded hover:bg-red-100"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;
        }).join('');
    }

    function attachEvents() {
        $('#search-input')?.addEventListener('input', UI.debounce(e => { state.q = e.target.value.trim(); loadObjectifs(1); }, 350));
        ['#filter-campagne','#filter-agent','#filter-type','#filter-statut'].forEach(sel => {
            $(sel)?.addEventListener('change', e => {
                if (sel === '#filter-campagne') state.campagne_id = e.target.value;
                if (sel === '#filter-agent')    state.agent_dsa_id = e.target.value;
                if (sel === '#filter-type')     state.type = e.target.value;
                if (sel === '#filter-statut')   state.statut = e.target.value;
                loadObjectifs(1);
            });
        });
        $('#btn-refresh')?.addEventListener('click', () => loadObjectifs(state.page));
        $('#btn-create')?.addEventListener('click', openCreate);

        document.addEventListener('click', e => {
            const b = e.target.closest('[data-action]');
            if (!b) return;
            if (b.dataset.action === 'edit')   openEdit(b.dataset.id);
            if (b.dataset.action === 'delete') confirmDelete(b.dataset.id, b.dataset.name);
        });

        $('#objectif-form')?.addEventListener('submit', onSubmit);
        $$('[data-close-modal]').forEach(b => b.addEventListener('click', () => UI.closeModal('objectif-modal')));
        $('#objectif-modal')?.addEventListener('click', e => { if (e.target.id === 'objectif-modal') UI.closeModal('objectif-modal'); });
    }

    function openCreate() {
        $('#modal-title').textContent = 'Nouvel objectif';
        $('#objectif-form').reset();
        $('#objectif-id').value = '';
        $('#statut').value = 'ACTIF';
        $('#valeur_realisee').value = 0;
        $('#periode_debut').value = new Date().toISOString().slice(0, 10);
        $('#periode_fin').value = new Date(new Date().setMonth(new Date().getMonth() + 3)).toISOString().slice(0, 10);
        UI.openModal('objectif-modal');
    }

    async function openEdit(id) {
        const res = await API.get('/dsa/objectifs/' + id);
        if (!res.ok) { UI.toast('danger', 'Objectif introuvable'); return; }
        const o = res.data.data || res.data;
        $('#modal-title').textContent = 'Modifier l\'objectif';
        $('#objectif-id').value = o.id;
        $('#partenaire_id').value = o.partenaire_id || '';
        $('#campagne_id').value = o.campagne_id || '';
        $('#agent_dsa_id').value = o.agent_dsa_id || '';
        $('#produit_dsa_id').value = o.produit_dsa_id || '';
        $('#zone_id').value = o.zone_id || '';
        $('#type_objectif').value = o.type_objectif || 'MONTANT';
        $('#libelle').value = o.libelle || '';
        $('#valeur_cible').value = o.valeur_cible || '';
        $('#valeur_realisee').value = o.valeur_realisee || 0;
        $('#unite').value = o.unite || '';
        $('#statut').value = o.statut || 'ACTIF';
        $('#periode_debut').value = o.periode_debut || '';
        $('#periode_fin').value = o.periode_fin || '';
        UI.openModal('objectif-modal');
    }

    async function onSubmit(e) {
        e.preventDefault();
        const id = $('#objectif-id').value;
        const isEdit = !!id;
        const btn = $('#btn-save');

        const payload = {
            partenaire_id:    $('#partenaire_id').value,
            campagne_id:      $('#campagne_id').value || null,
            agent_dsa_id:     $('#agent_dsa_id').value || null,
            produit_dsa_id:   $('#produit_dsa_id').value || null,
            zone_id:          $('#zone_id').value || null,
            type_objectif:    $('#type_objectif').value,
            libelle:          $('#libelle').value.trim(),
            valeur_cible:     $('#valeur_cible').value,
            valeur_realisee:  $('#valeur_realisee').value || 0,
            unite:            $('#unite').value.trim() || null,
            statut:           $('#statut').value,
            periode_debut:    $('#periode_debut').value,
            periode_fin:      $('#periode_fin').value,
        };

        UI.setLoading(btn, true);
        const res = isEdit ? await API.put('/dsa/objectifs/' + id, payload) : await API.post('/dsa/objectifs', payload);
        UI.setLoading(btn, false);

        if (res.ok) {
            UI.toast('success', isEdit ? 'Objectif mis à jour' : 'Objectif créé');
            UI.closeModal('objectif-modal');
            loadObjectifs(state.page);
        } else {
            const errs = res.data?.errors || res.data?.error || res.data?.message;
            UI.toast('danger', typeof errs === 'object' ? Object.values(errs).flat().join(' · ') : (errs || 'Erreur'));
        }
    }

    function confirmDelete(id, name) {
        UI.confirm(`Supprimer l'objectif "${name}" ?`, async () => {
            const res = await API.delete('/dsa/objectifs/' + id);
            if (res.ok) { UI.toast('success', 'Objectif supprimé'); loadObjectifs(state.page); }
            else UI.toast('danger', res.data?.error || 'Erreur');
        }, { title: 'Supprimer', confirmText: 'Supprimer' });
    }

    init();
})();