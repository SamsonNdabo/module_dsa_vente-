(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    let state = { page: 1, q: '', partenaire_id: '', statut: '' };
    let partenaires = [];
    let agents = [];
    let objectifsTypesState = [];
    let devises = [];
    let produitsPartenaire = [];
    let produitSelectionnes = [];
    let currentCampagneAgents = [];

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    const TYPES_STANDARD = {
        MONTANT: { label: 'Montant',  unite: 'USD' },
        VOLUME:  { label: 'Volume',   unite: '' },
        CLIENTS: { label: 'Clients',  unite: 'Personnes' },
        COMPTES: { label: 'Comptes',  unite: '' },
        VISITES: { label: 'Visites',  unite: 'Entreprises' },
        PRODUIT: { label: 'Produit',  unite: 'produits' },
    };

    // ══════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════
    function safeToast(type, msg) {
        if (window.UI && typeof UI.toast === 'function') UI.toast(type, msg);
        else { console.log(`[${type}]`, msg); if (type === 'danger') alert(msg); }
    }

    function openModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('hidden');
        el.classList.add('flex');
        el.style.display = 'flex';
        el.querySelector('.modal-content')?.classList.replace('scale-95', 'scale-100');
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.add('hidden');
        el.classList.remove('flex');
        el.style.display = 'none';
        el.querySelector('.modal-content')?.classList.replace('scale-100', 'scale-95');
    }

    function setLoading(btn, loading) {
        if (!btn) return;
        if (window.UI && typeof UI.setLoading === 'function') UI.setLoading(btn, loading);
        else {
            btn.disabled = loading;
            const spinner = btn.querySelector('.spinner');
            if (spinner) spinner.classList.toggle('hidden', !loading);
        }
    }

    function formatNumber(n) {
        return Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    // ══════════════════════════════════════════════════════════
    // INIT
    // ══════════════════════════════════════════════════════════
    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        try {
            await Promise.allSettled([loadPartenaires(), loadAgents()]);
            await loadDevises();
            await loadCampagnes();
        } catch (e) {
            console.error('[CAMPAGNES] init:', e);
        }
        attachEvents();
        initObjectifsCheckboxes();
        initCustomObjectifForm();
        attachDeviseListener();
    }

    async function loadDevises() {
        try {
            const res = await API.get('/devises?per_page=100&only_actif=1');
            let list = res.ok ? (res.data?.data?.data ?? res.data?.data ?? res.data ?? []) : [];
            devises = Array.isArray(list) ? list : [];

            const sel = document.querySelector('#devise_id');
            if (!sel) return;

            sel.innerHTML = '<option value="">— Choisir —</option>';
            if (!devises.length) {
                sel.innerHTML += '<option value="">Aucune devise active</option>';
                return;
            }

            devises.forEach(d => {
                const label = d.symbole ? `${d.code} (${d.symbole})` : `${d.code} — ${d.nom ?? d.libelle ?? ''}`.trim();
                sel.insertAdjacentHTML('beforeend', `<option value="${d.id}" data-code="${d.code}">${UI.escapeHtml(label)}</option>`);
            });

            const defaultDevise = devises.find(d => d.devise_defaut || d.est_defaut);
            if (defaultDevise) sel.value = defaultDevise.id;
        } catch (e) { console.error(e); }
    }

    async function loadPartenaires() {
        try {
            const res = await API.get('/dsa/partenaires/options');
            if (res.ok && res.data?.data) {
                partenaires = res.data.data;
                const filter = $('#filter-partenaire');
                const form   = $('#partenaire_id');
                partenaires.forEach(p => {
                    const opt = `<option value="${p.id}">${UI.escapeHtml(p.nom)}</option>`;
                    if (filter) filter.insertAdjacentHTML('beforeend', opt);
                    if (form)   form.insertAdjacentHTML('beforeend', opt);
                });
            }
        } catch (e) { console.error(e); }
    }

    async function loadAgents() {
        try {
            const res = await API.get('/dsa/agents/options');
            if (res.ok && res.data?.data) agents = res.data.data;
        } catch (e) { console.error(e); }
    }

    function renderAgentsSelection(selectedIds = []) {
        const el = $('#agents-selection');
        if (!el) return;
        if (!agents.length) {
            el.innerHTML = '<div class="text-xs text-gray-500 italic p-2">Aucun agent disponible</div>';
            return;
        }
        el.innerHTML = agents.map(a => `
            <label class="flex items-center gap-2 p-2 bg-white rounded hover:bg-indigo-50 cursor-pointer text-sm border border-gray-100 transition-colors">
                <input type="checkbox" data-agent-id="${a.id}"
                       ${selectedIds.includes(a.id) ? 'checked' : ''}
                       class="w-4 h-4 rounded accent-indigo-600 agent-checkbox">
                <span class="flex-1">${UI.escapeHtml(a.nom_complet)} <span class="text-xs text-gray-500">(${UI.escapeHtml(a.code || '')})</span></span>
            </label>
        `).join('');
        updateAgentsCount();
        el.querySelectorAll('.agent-checkbox').forEach(cb => cb.addEventListener('change', updateAgentsCount));
    }

    function updateAgentsCount() {
        const n = $$('#agents-selection .agent-checkbox:checked').length;
        const el = $('#agents-count');
        if (el) el.textContent = n;
    }

    function initObjectifsCheckboxes() {
        $$('.type-checkbox').forEach(cb => {
            cb.addEventListener('change', e => {
                const type = e.target.dataset.type;

                if (type === 'PRODUIT') {
                    const sec = $('#produits-section');
                    if (sec) sec.classList.toggle('hidden', !e.target.checked);
                    if (e.target.checked) {
                        const partId = $('#partenaire_id')?.value;
                        if (partId) loadProduitsPartenaire(partId);
                    } else {
                        produitSelectionnes = [];
                        renderProduitsCheckboxes();
                        renderObjectifsTypes();
                    }
                    return;
                }

                if (e.target.checked) addTypeFromCheckbox(type);
                else removeTypeFromState(type);
            });
        });

        $('#partenaire_id')?.addEventListener('change', e => loadProduitsPartenaire(e.target.value));
        $('#btn-add-objectif-type')?.addEventListener('click', addCustomObjectifType);
    }

    function addTypeFromCheckbox(type) {
        if (!type) return;
        if (objectifsTypesState.some(t => t.type_objectif === type)) return;

        const meta = TYPES_STANDARD[type] || { label: type, unite: '' };
        let unite = meta.unite;

        if (type === 'MONTANT') {
            const sel = document.querySelector('#devise_id');
            const opt = sel?.options[sel.selectedIndex];
            unite = opt?.dataset?.code || 'USD';
        }

        objectifsTypesState.push({ type_objectif: type, libelle: meta.label, valeur_cible: 0, unite });
        renderObjectifsTypes();
    }

    function removeTypeFromState(type) {
        objectifsTypesState = objectifsTypesState.filter(t => t.type_objectif !== type);
        renderObjectifsTypes();
    }

    function addCustomObjectifType() {
        const form = document.querySelector('#custom-objectif-form');
        if (!form) return;
        form.classList.remove('hidden');
        document.querySelector('#custom-libelle')?.focus();
    }

    function initCustomObjectifForm() {
        const form = document.querySelector('#custom-objectif-form');
        const btnSave = document.querySelector('#btn-custom-save');
        const btnCancel = document.querySelector('#btn-custom-cancel');
        if (!form || !btnSave || !btnCancel) return;

        btnCancel.addEventListener('click', () => {
            form.classList.add('hidden');
            ['#custom-libelle', '#custom-unite', '#custom-valeur'].forEach(sel => {
                const el = document.querySelector(sel); if (el) el.value = '';
            });
        });

        btnSave.addEventListener('click', () => {
            const libelle = (document.querySelector('#custom-libelle')?.value || '').trim();
            const unite   = (document.querySelector('#custom-unite')?.value || '').trim();
            const valeur  = parseFloat(document.querySelector('#custom-valeur')?.value || '0');

            if (!libelle) { safeToast('danger', 'Nom requis'); return; }
            if (isNaN(valeur) || valeur <= 0) { safeToast('danger', 'Valeur invalide'); return; }

            const base = 'LIBRE_' + libelle.toUpperCase().replace(/[^A-Z0-9]/g, '_').substring(0, 30);
            let code = base; let i = 1;
            while (objectifsTypesState.some(t => t.type_objectif === code)) code = base + '_' + i++;

            objectifsTypesState.push({ type_objectif: code, libelle, valeur_cible: valeur, unite });
            renderObjectifsTypes();

            form.classList.add('hidden');
            ['#custom-libelle', '#custom-unite', '#custom-valeur'].forEach(sel => {
                const el = document.querySelector(sel); if (el) el.value = '';
            });
        });
    }

    function renderObjectifsTypes() {
        const el = $('#objectifs-types-list');
        if (!el) return;

        const total = objectifsTypesState.length + produitSelectionnes.length;
        const count = $('#objectifs-types-count');
        if (count) count.textContent = total;

        const lignes = [];

        objectifsTypesState.forEach((t, i) => {
            const meta = TYPES_STANDARD[t.type_objectif] || { label: t.libelle || t.type_objectif, unite: t.unite || '' };
            const label = t.libelle || meta.label;
            const unite = t.unite || meta.unite || '';

            lignes.push(`
                <div class="flex items-center gap-2 p-2 bg-emerald-50 rounded-lg border border-emerald-200">
                    <span class="w-7 h-7 rounded-full bg-emerald-500 text-white flex items-center justify-center text-xs font-bold flex-shrink-0">${lignes.length + 1}</span>
                    <span class="flex-1 text-sm font-semibold text-gray-800">${UI.escapeHtml(label)}</span>
                    <input type="number" step="0.01" min="0.01"
                           data-objectif-index="${i}"
                           value="${t.valeur_cible || ''}"
                           placeholder="Cible"
                           class="w-28 px-2 py-1 text-sm border border-emerald-300 rounded-lg objectif-valeur">
                    <span class="text-xs text-gray-600 w-12">${UI.escapeHtml(unite)}</span>
                    <button type="button" data-remove-objectif="${i}" class="w-7 h-7 text-red-500 hover:bg-red-50 rounded flex items-center justify-center">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                </div>`);
        });

        produitSelectionnes.forEach((prod, i) => {
            lignes.push(`
                <div class="flex items-center gap-2 p-2 bg-blue-50 rounded-lg border border-blue-200">
                    <span class="w-7 h-7 rounded-full bg-blue-500 text-white flex items-center justify-center text-xs font-bold flex-shrink-0">${lignes.length + 1}</span>
                    <span class="flex-1 text-sm font-semibold text-gray-800">
                        <i class="fas fa-box text-blue-500 text-xs mr-1"></i>
                        ${UI.escapeHtml(prod.code)} — ${UI.escapeHtml(prod.nom)}
                    </span>
                    <input type="number" step="0.01" min="0.01"
                           data-produit-objectif-index="${i}"
                           placeholder="Cible"
                           value="${prod.valeur_cible || ''}"
                           class="w-28 px-2 py-1 text-sm border border-blue-300 rounded-lg produit-objectif-valeur">
                    <span class="text-xs text-gray-600 w-12">unités</span>
                    <button type="button" data-remove-produit-objectif="${i}" class="w-7 h-7 text-red-500 hover:bg-red-50 rounded flex items-center justify-center">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                </div>`);
        });

        if (!lignes.length) {
            el.innerHTML = '<div class="text-xs text-gray-500 italic p-2">Cochez un type pour définir une valeur cible</div>';
            return;
        }

        el.innerHTML = lignes.join('');

        el.querySelectorAll('.objectif-valeur').forEach(inp => {
            inp.addEventListener('input', e => {
                const idx = parseInt(e.target.dataset.objectifIndex, 10);
                objectifsTypesState[idx].valeur_cible = parseFloat(e.target.value) || 0;
            });
        });

        el.querySelectorAll('.produit-objectif-valeur').forEach(inp => {
            inp.addEventListener('input', e => {
                const idx = parseInt(e.target.dataset.produitObjectifIndex, 10);
                produitSelectionnes[idx].valeur_cible = parseFloat(e.target.value) || 0;
            });
        });

        el.querySelectorAll('[data-remove-objectif]').forEach(b => {
            b.addEventListener('click', () => {
                const idx = parseInt(b.dataset.removeObjectif, 10);
                const removed = objectifsTypesState[idx];
                objectifsTypesState.splice(idx, 1);
                const cb = document.querySelector(`.type-checkbox[data-type="${removed.type_objectif}"]`);
                if (cb) cb.checked = false;
                renderObjectifsTypes();
            });
        });

        el.querySelectorAll('[data-remove-produit-objectif]').forEach(b => {
            b.addEventListener('click', () => {
                const idx = parseInt(b.dataset.removeProduitObjectif, 10);
                const removed = produitSelectionnes[idx];
                produitSelectionnes.splice(idx, 1);
                const cb = document.querySelector(`.produit-checkbox[data-produit-id="${removed.id}"]`);
                if (cb) cb.checked = false;
                renderObjectifsTypes();
            });
        });
    }

    async function loadCampagnes(page = 1) {
        state.page = page;
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400"><i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page, per_page: 15,
            ...(state.q && { q: state.q }),
            ...(state.partenaire_id && { partenaire_id: state.partenaire_id }),
            ...(state.statut && { statut: state.statut }),
        });

        try {
            const res = await API.get('/dsa/campagnes?' + params);
            if (!res.ok) { tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-red-500">Erreur ${res.status}</td></tr>`; return; }

            const pag = res.data.data || res.data;
            const items = pag.data || [];
            renderTable(items);
            computeStats(items);
            if (UI.renderPagination) UI.renderPagination($('#pagination'), pag, p => loadCampagnes(p));
        } catch (e) {
            console.error(e);
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-red-500">Exception : ${UI.escapeHtml(e.message)}</td></tr>`;
        }
    }

    function computeStats(items) {
        const el = id => document.getElementById(id);
        if (el('stat-total'))      el('stat-total').textContent      = items.length;
        if (el('stat-actives'))    el('stat-actives').textContent    = items.filter(c => c.statut === 'ACTIVE').length;
        if (el('stat-planifiees')) el('stat-planifiees').textContent = items.filter(c => c.statut === 'PLANIFIEE').length;
        if (el('stat-terminees'))  el('stat-terminees').textContent  = items.filter(c => c.statut === 'TERMINEE').length;
    }

    function renderTable(items) {
        const tbody = $('#table-body');
        if (!items.length) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400"><i class="fas fa-inbox text-3xl mb-2 block"></i>Aucune campagne</td></tr>`;
            return;
        }
        tbody.innerHTML = items.map(c => {
            const badge = {
                PLANIFIEE: 'bg-gray-100 text-gray-700 border-gray-200',
                ACTIVE:    'bg-emerald-50 text-emerald-700 border-emerald-200',
                SUSPENDUE: 'bg-amber-50 text-amber-700 border-amber-200',
                TERMINEE:  'bg-blue-50 text-blue-700 border-blue-200',
                ANNULEE:   'bg-red-50 text-red-700 border-red-200',
            }[c.statut] || 'bg-gray-100 text-gray-700 border-gray-200';

            const nbAgents = c.affectations_count ?? c.affectations?.length ?? 0;
            const nbObjectifs = c.objectifs_types_count ?? 0;

            return `
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(c.code)}</td>
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-900 text-sm">${UI.escapeHtml(c.nom)}</div>
                    ${c.description ? `<div class="text-xs text-gray-500 truncate max-w-xs">${UI.escapeHtml(c.description)}</div>` : ''}
                </td>
                <td class="px-4 py-3 text-xs text-gray-600">${c.partenaire ? UI.escapeHtml(c.partenaire.nom) : '—'}</td>
                <td class="px-4 py-3 text-xs text-gray-600">
                    ${new Date(c.date_debut).toLocaleDateString('fr-FR')}
                    ${c.date_fin ? ' → ' + new Date(c.date_fin).toLocaleDateString('fr-FR') : ''}
                </td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200">
                            <i class="fas fa-users mr-1"></i>${nbAgents}
                        </span>
                        ${nbObjectifs > 0 ? `
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <i class="fas fa-bullseye mr-1"></i>${nbObjectifs}
                        </span>` : ''}
                    </div>
                </td>
                <td class="px-4 py-3"><span class="px-2 py-1 text-xs font-semibold rounded-full border ${badge}">${c.statut}</span></td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <button data-action="edit" data-id="${c.id}" title="Modifier" class="inline-flex items-center gap-1 px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100 transition-colors"><i class="fas fa-edit"></i></button>
                    <a href="${window.APP_BASE}/campagnes/${c.id}/objectifs-agents" title="Attribuer objectifs" class="inline-flex items-center gap-1 px-2 py-1 text-xs text-emerald-600 bg-emerald-50 rounded hover:bg-emerald-100 transition-colors ml-1"><i class="fas fa-bullseye"></i></a>
                    <a href="${window.APP_BASE}/campagnes/${c.id}/objectifs-mesurables" title="Objectifs mesurables" class="inline-flex items-center gap-1 px-2 py-1 text-xs text-blue-600 bg-blue-50 rounded hover:bg-blue-100 transition-colors ml-1"><i class="fas fa-chart-line"></i></a>
                    <button data-action="delete" data-id="${c.id}" data-name="${UI.escapeHtml(c.nom)}" title="Supprimer" class="inline-flex items-center gap-1 px-2 py-1 text-xs text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors ml-1"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;
        }).join('');
    }

    function openCreate() {
        $('#modal-title').textContent = 'Nouvelle campagne';
        $('#campagne-form').reset();
        $('#campagne-id').value = '';
        $('#statut').value = 'PLANIFIEE';
        $('#date_debut').value = new Date().toISOString().slice(0, 10);

        objectifsTypesState = [];
        produitSelectionnes = [];
        produitsPartenaire = [];
        currentCampagneAgents = [];   // ← AJOUTER — reset en création

        $$('.type-checkbox').forEach(cb => cb.checked = false);
        $('#produits-section')?.classList.add('hidden');
        $('#produits-liste').innerHTML = '<div class="text-xs text-gray-500 italic">Sélectionnez un partenaire d\'abord</div>';
        renderObjectifsTypes();

        renderAgentsSelection();
        const countEl = $('#agents-count');
        if (countEl) countEl.textContent = '0';

        openModal('campagne-modal');
    }

    async function openEdit(id) {
        const res = await API.get('/dsa/campagnes/' + id);
        if (!res.ok) {
            safeToast('danger', 'Campagne introuvable');
            return;
        }
        const c = res.data.data || res.data;

        $('#modal-title').textContent = 'Modifier la campagne';
        $('#campagne-id').value = c.id;
        $('#partenaire_id').value = c.partenaire_id || '';
        $('#code').value = c.code || '';
        $('#nom').value = c.nom || '';
        $('#description').value = c.description || '';
        $('#budget').value = c.budget || '';
        $('#devise_id').value = c.devise_id || '';
        $('#date_debut').value = c.date_debut || '';
        $('#date_fin').value = c.date_fin || '';
        $('#statut').value = c.statut || 'PLANIFIEE';

        // ── Charger les objectifs types existants
        const objRes = await API.get(`/dsa/campagnes/${id}/objectifs-types`);
        const typesExistants = objRes.ok ? (objRes.data.data || []) : [];

        objectifsTypesState = [];
        produitSelectionnes = [];

        typesExistants.forEach(o => {
            if (o.type_objectif === 'PRODUIT' && o.produit_dsa_id) {
                produitSelectionnes.push({
                    id: o.produit_dsa_id,
                    code: o.produit?.code || '',
                    nom: o.produit?.nom || '',
                    valeur_cible: parseFloat(o.valeur_cible) || 0,
                });
            } else {
                objectifsTypesState.push({
                    type_objectif: o.type_objectif,
                    libelle: o.libelle,
                    valeur_cible: parseFloat(o.valeur_cible) || 0,
                    unite: o.unite,
                });
            }
        });

        // Cocher les checkboxes correspondantes
        $$('.type-checkbox').forEach(cb => {
            cb.checked = objectifsTypesState.some(t => t.type_objectif === cb.dataset.type);
        });

        // Charger les produits du partenaire
        if (c.partenaire_id) {
            await loadProduitsPartenaire(c.partenaire_id);
        }

        // Afficher/cacher la section produits
        const sec = $('#produits-section');
        if (sec) sec.classList.toggle('hidden', !produitSelectionnes.length);

        renderObjectifsTypes();

        // ── Agents sélectionnés : stocker pour éviter la réaffectation
        currentCampagneAgents = (c.affectations || []).map(a => a.agent_dsa_id);   // ← AJOUTER
        renderAgentsSelection(currentCampagneAgents);

        openModal('campagne-modal');
    }

    async function onSubmit(e) {
            e.preventDefault();

            const id = $('#campagne-id').value;
            const isEdit = !!id;
            const btn = $('#btn-save');

            const payload = {
                partenaire_id: $('#partenaire_id').value,
                code:          $('#code').value.trim() || null,
                nom:           $('#nom').value.trim(),
                description:   $('#description').value.trim() || null,
                budget:        $('#budget').value || null,
                devise_id:     $('#devise_id').value || null,
                date_debut:    $('#date_debut').value,
                date_fin:      $('#date_fin').value || null,
                statut:        $('#statut').value,
            };

            setLoading(btn, true);

            try {
                // ─── 1. Créer / mettre à jour la campagne
                const res = isEdit
                    ? await API.put('/dsa/campagnes/' + id, payload)
                    : await API.post('/dsa/campagnes', payload);

                if (!res.ok) {
                    const err = res.data?.errors || res.data?.message;
                    safeToast('danger', typeof err === 'object'
                        ? Object.values(err).flat().join(' · ')
                        : (err || 'Erreur'));
                    return;
                }

                const campagneId = isEdit
                    ? parseInt(id, 10)
                    : (res.data?.data?.id ?? res.data?.campagne?.id);

                if (!campagneId) {
                    safeToast('danger', 'ID campagne manquant');
                    return;
                }

                // ─── 2. Objectifs types (via /objectifs-types)
                const typesPayload = [];

                objectifsTypesState.forEach((t, i) => {
                    if (t.valeur_cible > 0) {
                        typesPayload.push({
                            type_objectif:  t.type_objectif,
                            produit_dsa_id: null,
                            libelle:        t.libelle,
                            valeur_cible:   t.valeur_cible,
                            unite:          t.unite || null,
                            ordre:          i,
                            actif:          true,
                        });
                    }
                });

                produitSelectionnes.forEach((p, i) => {
                    if (p.valeur_cible > 0) {
                        typesPayload.push({
                            type_objectif:  'PRODUIT',
                            produit_dsa_id: p.id,
                            libelle:        `${p.code} — ${p.nom}`,
                            valeur_cible:   p.valeur_cible,
                            unite:          'unités',
                            ordre:          objectifsTypesState.length + i,
                            actif:          true,
                        });
                    }
                });

                if (typesPayload.length) {
                    const objRes = await API.post(`/dsa/campagnes/${campagneId}/objectifs-types`, {
                        types: typesPayload,
                    });
                    if (!objRes.ok) {
                        const err = objRes.data?.errors || objRes.data?.message;
                        safeToast('danger', 'Erreur objectifs : ' + (
                            typeof err === 'object' ? Object.values(err).flat().join(' · ') : err
                        ));
                        return;
                    }
                }

                // ─── 3. Affectations agents (uniquement les NOUVEAUX)
                const selectedAgentIds = $$('#agents-selection .agent-checkbox:checked')
                    .map(cb => parseInt(cb.dataset.agentId, 10));

                let agentIdsAAffecter = selectedAgentIds;

                if (isEdit) {
                    // Ne réaffecter QUE les agents nouvellement cochés
                    agentIdsAAffecter = selectedAgentIds.filter(
                        agentId => !currentCampagneAgents.includes(agentId)
                    );

                    console.log('[CAMPAGNES] Agents à affecter:', agentIdsAAffecter,
                                '| Déjà affectés:', currentCampagneAgents);
                }

                if (agentIdsAAffecter.length) {
                    const results = await Promise.allSettled(agentIdsAAffecter.map(agentId =>
                        API.post(`/dsa/agents/${agentId}/affectations`, {
                            campagne_id:   campagneId,
                            partenaire_id: payload.partenaire_id,
                            date_debut:    payload.date_debut,
                        })
                    ));

                    // Compter les succès
                    const nbOk = results.filter(r => r.status === 'fulfilled' && r.value?.ok).length;
                    if (nbOk > 0) {
                        console.log(`[CAMPAGNES] ${nbOk} affectation(s) ajoutée(s)`);
                    }
                }

                safeToast('success', isEdit ? 'Campagne mise à jour' : 'Campagne créée');
                closeModal('campagne-modal');
                loadCampagnes(state.page);

            } catch (err) {
                console.error('[CAMPAGNES] onSubmit erreur:', err);
                safeToast('danger', 'Erreur réseau');
            } finally {
                setLoading(btn, false);
            }
        }

    function confirmDelete(id, name) {
        if (!confirm(`Supprimer la campagne "${name}" ?`)) return;
        API.delete('/dsa/campagnes/' + id).then(res => {
            if (res.ok) { safeToast('success', 'Campagne supprimée'); loadCampagnes(state.page); }
            else { const err = res.data?.error || res.data?.message; safeToast('danger', typeof err === 'object' ? Object.values(err).flat().join(' · ') : (err || 'Erreur')); }
        });
    }

    function attachEvents() {
        const form = document.querySelector('#campagne-form');
        if (form) form.addEventListener('submit', onSubmit);

        $('#search-input')?.addEventListener('input', UI.debounce(e => { state.q = e.target.value.trim(); loadCampagnes(1); }, 350));
        $('#filter-partenaire')?.addEventListener('change', e => { state.partenaire_id = e.target.value; loadCampagnes(1); });
        $('#filter-statut')?.addEventListener('change', e => { state.statut = e.target.value; loadCampagnes(1); });
        $('#btn-refresh')?.addEventListener('click', () => loadCampagnes(state.page));
        $('#btn-create')?.addEventListener('click', openCreate);

        document.addEventListener('click', e => {
            const b = e.target.closest('[data-action]');
            if (!b) return;
            if (b.dataset.action === 'edit')   openEdit(b.dataset.id);
            if (b.dataset.action === 'delete') confirmDelete(b.dataset.id, b.dataset.name);
        });

        $$('[data-close-modal]').forEach(b => {
            b.addEventListener('click', () => {
                const mid = b.dataset.closeModal;
                if (mid) closeModal(mid);
            });
        });

        const modal = document.querySelector('#campagne-modal');
        if (modal) modal.addEventListener('click', ev => { if (ev.target.id === modal.id) closeModal(modal.id); });
    }

    function attachDeviseListener() {
        document.querySelector('#devise_id')?.addEventListener('change', e => {
            const opt = e.target.options[e.target.selectedIndex];
            const code = opt?.dataset?.code || 'CDF';
            objectifsTypesState.filter(t => t.type_objectif === 'MONTANT').forEach(t => t.unite = code);
            renderObjectifsTypes();
        });
    }

    async function loadProduitsPartenaire(partenaireId) {
        if (!partenaireId) { produitsPartenaire = []; return; }
        try {
            const res = await API.get(`/dsa/produits?partenaire_id=${partenaireId}&per_page=200`);
            if (res.ok && res.data?.data?.data) {
                produitsPartenaire = res.data.data.data.filter(p => p.statut === 'ACTIF');
                renderProduitsCheckboxes();
            }
        } catch (e) { console.error(e); }
    }

    function renderProduitsCheckboxes() {
        const el = $('#produits-liste');
        if (!el) return;

        if (!produitsPartenaire.length) {
            el.innerHTML = '<div class="text-xs text-gray-500 italic col-span-2">Aucun produit pour ce partenaire</div>';
            return;
        }

        el.innerHTML = produitsPartenaire.map(p => `
            <label class="flex items-center gap-2 p-2 bg-white rounded text-xs border border-gray-100 cursor-pointer hover:bg-emerald-100">
                <input type="checkbox" data-produit-id="${p.id}"
                       ${produitSelectionnes.some(x => x.id === p.id) ? 'checked' : ''}
                       class="w-4 h-4 rounded accent-emerald-600 produit-checkbox">
                <span class="flex-1">${UI.escapeHtml(p.code)} — ${UI.escapeHtml(p.nom)}</span>
            </label>
        `).join('');

        el.querySelectorAll('.produit-checkbox').forEach(cb => {
            cb.addEventListener('change', e => {
                const pid = parseInt(e.target.dataset.produitId, 10);
                if (e.target.checked) {
                    const prod = produitsPartenaire.find(p => p.id === pid);
                    if (prod && !produitSelectionnes.some(x => x.id === pid)) {
                        produitSelectionnes.push({ id: pid, code: prod.code, nom: prod.nom, valeur_cible: 0 });
                    }
                } else {
                    produitSelectionnes = produitSelectionnes.filter(x => x.id !== pid);
                }
                renderObjectifsTypes();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();