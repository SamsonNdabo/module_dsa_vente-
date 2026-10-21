(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    // ─────────────────────────────────────────────────────────
    // STATE
    // ─────────────────────────────────────────────────────────
    let state = {
        recherche: '',
        partenaire_id: '',
        type: '',
        actif: '',
    };

    let partenaires = [];
    let produitsDsa = [];

    // ── Types autorisés par le backend (miroir exact de KycPiece::TYPES_REGLE) ──
    // Toute modification de KycPiece::TYPES_REGLE doit être répercutée ici.
    const TYPES_DOCUMENTS = [
        { value: 'CARTE_IDENTITE',       label: "Carte d'identité" },
        { value: 'PASSEPORT',            label: 'Passeport' },
        { value: 'CARTE_ELECTEUR',       label: "Carte d'électeur" },
        { value: 'PERMIS',               label: 'Permis de conduire' },
        { value: 'AUTRE_PIECE',          label: 'Autre pièce' },
        { value: 'PHOTO_CLIENT',         label: 'Photo du client' },
        { value: 'JUSTIFICATIF_ADRESSE', label: "Justificatif d'adresse" },
        { value: 'IDENTITE',             label: "Pièce d'identité (au choix)" },
        { value: 'SIGNATURE',            label: 'Signature du dossier' },
    ];

    const TYPES_LABELS = TYPES_DOCUMENTS.reduce((acc, t) => {
        acc[t.value] = t.label;
        return acc;
    }, {});

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
        await loadPartenaires();
        await loadTypesDocuments();
        await loadRegles();
        attachEvents();
    }

    // ─────────────────────────────────────────────────────────
    // CHARGEMENTS
    // ─────────────────────────────────────────────────────────
    async function loadPartenaires() {
        try {
            const res = await API.get('/dsa/partenaires/options');
            if (res.ok && res.data?.data) {
                partenaires = res.data.data;
                const filterSel = $('#filter-partenaire');
                const formSel   = $('#partenaire_id');
                partenaires.forEach(p => {
                    const opt = `<option value="${p.id}">${UI.escapeHtml(p.nom)}</option>`;
                    if (filterSel) filterSel.innerHTML += opt;
                    if (formSel)   formSel.innerHTML   += opt;
                });
            }
        } catch (e) {
            console.error('[REGLES-KYC] partenaires:', e);
        }
    }

    async function loadTypesDocuments() {
        let types = TYPES_DOCUMENTS;

        // Tentative d'API (si endpoint présent)
        try {
            const res = await API.get('/dsa/kyc/types-documents');
            if (res.ok && Array.isArray(res.data?.data) && res.data.data.length) {
                types = res.data.data.map(t => {
                    if (typeof t === 'string') {
                        return { value: t, label: TYPES_LABELS[t] ?? t };
                    }
                    const value = t.value ?? t.code ?? t;
                    return { value, label: t.label ?? TYPES_LABELS[value] ?? value };
                });
            }
        } catch (_) { /* fallback silencieux */ }

        const filterSel = $('#filter-type');
        const formSel   = $('#type_document');

        types.forEach(t => {
            const opt = `<option value="${UI.escapeHtml(t.value)}">${UI.escapeHtml(t.label)}</option>`;
            if (filterSel) filterSel.innerHTML += opt;
            if (formSel)   formSel.innerHTML   += opt;
        });
    }

    async function loadProduitsPourPartenaire(partenaireId) {
        const sel = $('#produit_dsa_id');
        if (!sel) return;
        sel.innerHTML = '<option value="">Tous les produits (règle globale)</option>';
        produitsDsa = [];

        if (!partenaireId) return;

        try {
            const res = await API.get('/dsa/produits?partenaire_id=' + partenaireId + '&per_page=200');
            if (res.ok && res.data?.data?.data) {
                produitsDsa = res.data.data.data;
                produitsDsa.forEach(p => {
                    sel.innerHTML += `<option value="${p.id}">${UI.escapeHtml(p.code)} — ${UI.escapeHtml(p.nom)}</option>`;
                });
            }
        } catch (e) {
            console.error('[REGLES-KYC] produits:', e);
        }
    }

    async function loadRegles() {
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            ...(state.partenaire_id && { partenaire_id: state.partenaire_id }),
        });

        const res = await API.get('/dsa/regles-kyc?' + params);
        if (!res.ok) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-red-500">Erreur de chargement</td></tr>`;
            return;
        }

        let items = Array.isArray(res.data) ? res.data : (res.data?.data ?? []);

        // Filtres côté client
        if (state.recherche) {
            const q = state.recherche.toLowerCase();
            items = items.filter(r => {
                const code  = (r.type_document || '').toLowerCase();
                const label = (TYPES_LABELS[r.type_document] || '').toLowerCase();
                return code.includes(q) || label.includes(q);
            });
        }
        if (state.type) {
            items = items.filter(r => r.type_document === state.type);
        }
        if (state.actif !== '') {
            const a = state.actif === '1';
            items = items.filter(r => !!r.actif === a);
        }

        renderTable(items);
        computeStats(items);
    }

    function computeStats(items) {
        $('#stat-total').textContent        = items.length;
        $('#stat-actives').textContent      = items.filter(r => r.actif).length;
        $('#stat-obligatoires').textContent = items.filter(r => r.obligatoire).length;
        $('#stat-produit').textContent      = items.filter(r => r.produit_dsa_id).length;
    }

    function renderTable(items) {
        const tbody = $('#table-body');
        if (!items.length) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucune règle KYC</td></tr>`;
            return;
        }

        tbody.innerHTML = items.map(r => {
            const partenaire = partenaires.find(p => p.id === r.partenaire_id);
            const partenaireLabel = partenaire ? UI.escapeHtml(partenaire.nom) : `#${r.partenaire_id}`;
            const produitLabel = r.produit
                ? `${UI.escapeHtml(r.produit.code)} — ${UI.escapeHtml(r.produit.nom)}`
                : '<span class="text-gray-400 italic">Tous les produits</span>';

            const typeLabel = TYPES_LABELS[r.type_document] ?? r.type_document;

            return `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-gray-700 font-medium">${partenaireLabel}</td>
                <td class="px-4 py-3 text-xs text-gray-600">${produitLabel}</td>
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-800">${UI.escapeHtml(typeLabel)}</div>
                    <div class="text-[10px] text-gray-400 font-mono">${UI.escapeHtml(r.type_document)}</div>
                </td>
                <td class="px-4 py-3">
                    ${r.obligatoire
                        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-amber-50 text-amber-700"><i class="fas fa-star text-[10px] mr-1"></i>Obligatoire</span>'
                        : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">Facultatif</span>'}
                </td>
                <td class="px-4 py-3 text-xs text-gray-600">
                    ${r.validite_jours ? r.validite_jours + ' j' : '<span class="text-gray-400 italic">Illimitée</span>'}
                </td>
                <td class="px-4 py-3">
                    <button data-action="toggle" data-id="${r.id}" data-actif="${r.actif ? 1 : 0}"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${r.actif ? 'bg-emerald-500' : 'bg-gray-300'}">
                        <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${r.actif ? 'translate-x-6' : 'translate-x-1'}"></span>
                    </button>
                </td>
                <td class="px-4 py-3 text-right">
                    <button data-action="edit" data-id="${r.id}"
                            class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100">
                        <i class="fas fa-edit"></i>
                    </button>
                </td>
            </tr>`;
        }).join('');
    }

    // ─────────────────────────────────────────────────────────
    // EVENTS
    // ─────────────────────────────────────────────────────────
    function attachEvents() {
        // Filtres
        $('#search-input').addEventListener('input', UI.debounce(e => {
            state.recherche = e.target.value.trim();
            loadRegles();
        }, 300));
        $('#filter-partenaire').addEventListener('change', e => {
            state.partenaire_id = e.target.value;
            loadRegles();
        });
        $('#filter-type').addEventListener('change', e => {
            state.type = e.target.value;
            loadRegles();
        });
        $('#filter-actif').addEventListener('change', e => {
            state.actif = e.target.value;
            loadRegles();
        });
        $('#btn-refresh').addEventListener('click', loadRegles);
        $('#btn-create').addEventListener('click', openCreate);

        // Changement partenaire dans le modal → recharge produits
        $('#partenaire_id').addEventListener('change', e => {
            loadProduitsPourPartenaire(e.target.value);
        });

        // Actions table (délégation)
        document.addEventListener('click', async e => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const { action, id, actif } = btn.dataset;
            if (action === 'edit')   return openEdit(parseInt(id));
            if (action === 'toggle') return toggleActif(parseInt(id), actif === '1');
        });

        // Submit
        $('#regle-form').addEventListener('submit', onSubmit);

        // Modals
        $$('[data-close-modal]').forEach(b => b.addEventListener('click', () => UI.closeModal('regle-modal')));
        $('#regle-modal').addEventListener('click', e => {
            if (e.target.id === 'regle-modal') UI.closeModal('regle-modal');
        });
    }

    // ─────────────────────────────────────────────────────────
    // CRUD — CREATE
    // ─────────────────────────────────────────────────────────
    function openCreate() {
        $('#modal-title').textContent = 'Nouvelle règle KYC';
        $('#regle-form').reset();
        $('#regle-id').value = '';

        // Déverrouiller les champs (au cas où on vient d'un edit)
        $('#partenaire_id').disabled = false;
        $('#produit_dsa_id').disabled = false;
        $('#type_document').disabled = false;

        // Valeurs par défaut
        $('#obligatoire').checked = true;
        $('#actif').checked = true;
        $('#produit_dsa_id').innerHTML = '<option value="">Tous les produits (règle globale)</option>';

        // Pré-sélection du filtre partenaire si actif
        if (state.partenaire_id) {
            $('#partenaire_id').value = state.partenaire_id;
            loadProduitsPourPartenaire(state.partenaire_id);
        }

        UI.openModal('regle-modal');
    }

    // ─────────────────────────────────────────────────────────
    // CRUD — EDIT
    // ─────────────────────────────────────────────────────────
    async function openEdit(id) {
        const res = await API.get('/dsa/regles-kyc');
        if (!res.ok) { UI.toast('danger', 'Erreur de chargement'); return; }

        const items = Array.isArray(res.data) ? res.data : (res.data?.data ?? []);
        const r = items.find(x => x.id === id);
        if (!r) { UI.toast('danger', 'Règle introuvable'); return; }

        $('#modal-title').textContent = 'Modifier la règle KYC';
        $('#regle-id').value = r.id;

        // Partenaire
        $('#partenaire_id').value = r.partenaire_id;

        // Produits du partenaire, puis sélection
        await loadProduitsPourPartenaire(r.partenaire_id);
        $('#produit_dsa_id').value = r.produit_dsa_id ?? '';

        // Type de document
        $('#type_document').value = r.type_document;

        // Autres champs
        $('#validite_jours').value = r.validite_jours ?? '';
        $('#obligatoire').checked = !!r.obligatoire;
        $('#actif').checked = !!r.actif;

        // Champs verrouillés en édition (backend ne les accepte pas dans update())
        $('#partenaire_id').disabled = true;
        $('#produit_dsa_id').disabled = true;
        $('#type_document').disabled = true;

        UI.openModal('regle-modal');
    }

    // ─────────────────────────────────────────────────────────
    // SUBMIT
    // ─────────────────────────────────────────────────────────
    async function onSubmit(e) {
        e.preventDefault();
        const id = $('#regle-id').value;
        const isEdit = !!id;
        const btn = $('#btn-save');

        const payload = isEdit
            ? {
                obligatoire:    $('#obligatoire').checked,
                validite_jours: $('#validite_jours').value || null,
                actif:          $('#actif').checked,
            }
            : {
                partenaire_id:  $('#partenaire_id').value,
                produit_dsa_id: $('#produit_dsa_id').value || null,
                type_document:  $('#type_document').value,
                obligatoire:    $('#obligatoire').checked,
                validite_jours: $('#validite_jours').value || null,
                actif:          $('#actif').checked,
            };

        UI.setLoading(btn, true);
        const res = isEdit
            ? await API.put('/dsa/regles-kyc/' + id, payload)
            : await API.post('/dsa/regles-kyc', payload);
        UI.setLoading(btn, false);

        if (res.ok) {
            UI.toast('success', isEdit ? 'Règle mise à jour' : 'Règle créée');
            UI.closeModal('regle-modal');
            loadRegles();
        } else {
            const errs = res.data?.errors || res.data?.error || res.data?.message;
            if (typeof errs === 'object') UI.toast('danger', Object.values(errs).flat().join(' · '));
            else UI.toast('danger', errs || 'Erreur');
        }
    }

    // ─────────────────────────────────────────────────────────
    // TOGGLE ACTIF
    // ─────────────────────────────────────────────────────────
    async function toggleActif(id, actifActuel) {
        const res = await API.put('/dsa/regles-kyc/' + id, { actif: !actifActuel });
        if (res.ok) {
            UI.toast('success', !actifActuel ? 'Règle activée' : 'Règle désactivée');
            loadRegles();
        } else {
            UI.toast('danger', res.data?.error || 'Erreur');
        }
    }

    init();
})();