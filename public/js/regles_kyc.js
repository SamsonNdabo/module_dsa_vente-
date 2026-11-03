(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    const state = { recherche: '', partenaire_id: '', type: '', actif: '' };
    let partenaires = [];
    let produitsSelectionnes = [];

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
    const TYPES_LABELS = TYPES_DOCUMENTS.reduce((a, t) => { a[t.value] = t.label; return a; }, {});

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

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

    async function loadPartenaires() {
        try {
            const res = await API.get('/dsa/partenaires/options');
            if (res.ok && res.data?.data) {
                partenaires = res.data.data;
                partenaires.forEach(p => {
                    const opt = `<option value="${p.id}">${UI.escapeHtml(p.nom)}</option>`;
                    $('#filter-partenaire')?.insertAdjacentHTML('beforeend', opt);
                    $('#partenaire_id')?.insertAdjacentHTML('beforeend', opt);
                });
            }
        } catch (e) { console.error(e); }
    }

    function loadTypesDocuments() {
        TYPES_DOCUMENTS.forEach(t => {
            const opt = `<option value="${t.value}">${UI.escapeHtml(t.label)}</option>`;
            $('#filter-type')?.insertAdjacentHTML('beforeend', opt);
            $('#type_document')?.insertAdjacentHTML('beforeend', opt);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // CHARGEMENT DES PRODUITS (select d'ajout)
    // ═══════════════════════════════════════════════════════════
    async function loadProduitsSelect(typeProduit) {
        const partenaireId = $('#partenaire_id').value;
        const sel = $('#add-produit-id');

        sel.innerHTML = '<option value="">— Chargement... —</option>';

        if (!partenaireId) {
            sel.innerHTML = '<option value="">— Sélectionnez d\'abord un partenaire —</option>';
            return;
        }

        try {
            const endpoint = typeProduit === 'STOCK'
                ? `/dsa/produits?partenaire_id=${partenaireId}&per_page=200`
                : `/dsa/produits-epargne?partenaire_id=${partenaireId}&per_page=200`;

            console.log('[REGLES-KYC] GET', endpoint);

            const res = await API.get(endpoint);
            if (!res.ok) {
                console.error('[REGLES-KYC] HTTP', res.status);
                sel.innerHTML = '<option value="">— Erreur de chargement —</option>';
                return;
            }

            // ⚠️ Gestion des 2 formats (paginator ou tableau)
            const produits = res.data?.data?.data || res.data?.data || [];
            console.log('[REGLES-KYC] Produits reçus:', produits.length);

            if (!produits.length) {
                sel.innerHTML = '<option value="">— Aucun produit pour ce partenaire —</option>';
                return;
            }

            sel.innerHTML = '<option value="">— Sélectionner un produit —</option>' +
                produits.map(p =>
                    `<option value="${p.id}">${UI.escapeHtml(p.code_barre || p.code || '')} — ${UI.escapeHtml(p.nom)}</option>`
                ).join('');
        } catch (e) {
            console.error('[REGLES-KYC] Erreur:', e);
            sel.innerHTML = '<option value="">— Erreur réseau —</option>';
        }
    }

    // ═══════════════════════════════════════════════════════════
    // LISTE DES PRODUITS SÉLECTIONNÉS
    // ═══════════════════════════════════════════════════════════
    function renderProduitsList() {
        const container = $('#produits-list');

        if (!produitsSelectionnes.length) {
            container.innerHTML = '<div class="text-sm text-gray-400 italic text-center py-2">Aucun produit sélectionné (règle globale).</div>';
            return;
        }

        container.innerHTML = produitsSelectionnes.map((p, i) => {
            const badge = p.type === 'STOCK'
                ? 'bg-blue-50 text-blue-700'
                : 'bg-emerald-50 text-emerald-700';
            return `
                <div class="flex items-center justify-between p-2 bg-gray-50 border border-gray-200 rounded-lg">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full ${badge}">${p.type}</span>
                        <span class="text-sm font-medium text-gray-800">${UI.escapeHtml(p.nom)}</span>
                        <span class="text-xs text-gray-400 font-mono">${UI.escapeHtml(p.code || '')}</span>
                    </div>
                    <button type="button" data-remove-index="${i}" class="text-red-500 hover:text-red-700 text-xs">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
        }).join('');

        container.querySelectorAll('[data-remove-index]').forEach(btn => {
            btn.addEventListener('click', () => {
                produitsSelectionnes.splice(parseInt(btn.dataset.removeIndex, 10), 1);
                renderProduitsList();
            });
        });
    }

    async function ajouterProduit() {
        const type = $('#add-type-produit').value;
        const id = $('#add-produit-id').value;

        if (!id) {
            UI.toast('warning', 'Sélectionnez un produit.');
            return;
        }

        const idNum = parseInt(id, 10);

        if (produitsSelectionnes.some(p => p.type === type && p.id === idNum)) {
            UI.toast('warning', 'Ce produit est déjà sélectionné.');
            return;
        }

        const sel = $('#add-produit-id');
        const opt = sel.querySelector(`option[value="${id}"]`);
        const label = opt ? opt.textContent : `#${id}`;

        produitsSelectionnes.push({
            type,
            id: idNum,
            nom: label.split(' — ')[1] || label,
            code: label.split(' — ')[0] || '',
        });

        renderProduitsList();
        sel.value = '';
    }

    // ═══════════════════════════════════════════════════════════
    // CRUD — LISTE
    // ═══════════════════════════════════════════════════════════
    async function loadRegles() {
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400"><i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            ...(state.partenaire_id && { partenaire_id: state.partenaire_id }),
        });

        const res = await API.get('/dsa/regles-kyc?' + params);
        if (!res.ok) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-red-500">Erreur</td></tr>`;
            return;
        }

        let items = Array.isArray(res.data) ? res.data : (res.data?.data ?? []);

        if (state.recherche) {
            const q = state.recherche.toLowerCase();
            items = items.filter(r =>
                (r.type_document || '').toLowerCase().includes(q) ||
                (TYPES_LABELS[r.type_document] || '').toLowerCase().includes(q)
            );
        }
        if (state.type) items = items.filter(r => r.type_document === state.type);
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
        $('#stat-produit').textContent      = items.filter(r => (r.produits_resolus && r.produits_resolus.length > 0)).length;
    }

    function renderTable(items) {
        const tbody = $('#table-body');
        if (!items.length) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400"><i class="fas fa-inbox text-3xl mb-2 block"></i>Aucune règle</td></tr>`;
            return;
        }

        tbody.innerHTML = items.map(r => {
            const partenaire = partenaires.find(p => p.id === r.partenaire_id);
            const partenaireLabel = partenaire
                ? UI.escapeHtml(partenaire.nom)
                : (r.partenaire?.nom ? UI.escapeHtml(r.partenaire.nom) : `#${r.partenaire_id}`);

            let produitsLabel;
            if (r.produits_resolus && r.produits_resolus.length > 0) {
                produitsLabel = r.produits_resolus.map(p => {
                    const badge = p.type_produit === 'STOCK'
                        ? 'bg-blue-50 text-blue-700'
                        : 'bg-emerald-50 text-emerald-700';
                    return `<span class="inline-block px-2 py-0.5 mb-0.5 text-[10px] font-semibold rounded-full ${badge}">${p.type_produit}: ${UI.escapeHtml(p.nom || '')}</span>`;
                }).join(' ');
            } else {
                produitsLabel = '<span class="text-xs text-gray-400 italic"><i class="fas fa-globe mr-1"></i>Tous les produits</span>';
            }

            const typeLabel = TYPES_LABELS[r.type_document] ?? r.type_document;

            return `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-gray-700 font-medium">${partenaireLabel}</td>
                <td class="px-4 py-3 max-w-xs">${produitsLabel}</td>
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
                    <button data-action="edit" data-id="${r.id}" class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100"><i class="fas fa-edit"></i></button>
                </td>
            </tr>`;
        }).join('');
    }

    // ═══════════════════════════════════════════════════════════
    // ÉVÉNEMENTS
    // ═══════════════════════════════════════════════════════════
    function attachEvents() {
        $('#search-input')?.addEventListener('input', UI.debounce(e => {
            state.recherche = e.target.value.trim();
            loadRegles();
        }, 300));

        $('#filter-partenaire')?.addEventListener('change', e => {
            state.partenaire_id = e.target.value;
            loadRegles();
        });

        $('#filter-type')?.addEventListener('change', e => {
            state.type = e.target.value;
            loadRegles();
        });

        $('#filter-actif')?.addEventListener('change', e => {
            state.actif = e.target.value;
            loadRegles();
        });

        $('#btn-refresh')?.addEventListener('click', loadRegles);
        $('#btn-create')?.addEventListener('click', openCreate);

        // ⚠️ Type produit → recharge le select
        $('#add-type-produit')?.addEventListener('change', e => {
            loadProduitsSelect(e.target.value);
        });

        // ⚠️ Partenaire → recharge le select
        $('#partenaire_id')?.addEventListener('change', () => {
            const type = $('#add-type-produit').value || 'STOCK';
            loadProduitsSelect(type);
        });

        // Ajout produit au panier
        $('#btn-add-produit')?.addEventListener('click', ajouterProduit);

        document.addEventListener('click', async e => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const { action, id, actif } = btn.dataset;
            if (action === 'edit')   return openEdit(parseInt(id));
            if (action === 'toggle') return toggleActif(parseInt(id), actif === '1');
        });

        $('#regle-form')?.addEventListener('submit', onSubmit);

        $$('[data-close-modal]').forEach(b => b.addEventListener('click', () => UI.closeModal('regle-modal')));
        $('#regle-modal')?.addEventListener('click', e => {
            if (e.target.id === 'regle-modal') UI.closeModal('regle-modal');
        });
    }

    // ═══════════════════════════════════════════════════════════
    // MODAL — CREATE
    // ═══════════════════════════════════════════════════════════
    function openCreate() {
        $('#modal-title').textContent = 'Nouvelle règle KYC';
        $('#regle-form').reset();
        $('#regle-id').value = '';
        $('#partenaire_id').disabled = false;
        $('#type_document').disabled = false;
        $('#obligatoire').checked = true;
        $('#actif').checked = true;
        produitsSelectionnes = [];
        renderProduitsList();

        $('#add-type-produit').value = 'STOCK';
        $('#add-produit-id').innerHTML = '<option value="">— Sélectionner un produit —</option>';

        // ⚠️ Si filtre partenaire actif, pré-sélectionner et charger
        if (state.partenaire_id) {
            $('#partenaire_id').value = state.partenaire_id;
            setTimeout(() => loadProduitsSelect('STOCK'), 150);
        }

        UI.openModal('regle-modal');
    }

    // ═══════════════════════════════════════════════════════════
    // MODAL — EDIT
    // ═══════════════════════════════════════════════════════════
    async function openEdit(id) {
        const res = await API.get('/dsa/regles-kyc');
        if (!res.ok) return;
        const items = Array.isArray(res.data) ? res.data : (res.data?.data ?? []);
        const r = items.find(x => x.id === id);
        if (!r) return;

        $('#modal-title').textContent = 'Modifier la règle';
        $('#regle-id').value = r.id;
        $('#partenaire_id').value = r.partenaire_id;
        $('#type_document').value = r.type_document;
        $('#validite_jours').value = r.validite_jours ?? '';
        $('#obligatoire').checked = !!r.obligatoire;
        $('#actif').checked = !!r.actif;

        produitsSelectionnes = (r.produits_resolus || []).map(p => ({
            type: p.type_produit,
            id: p.id,
            nom: p.nom,
            code: p.code,
        }));
        renderProduitsList();

        $('#partenaire_id').disabled = true;
        $('#type_document').disabled = true;

        UI.openModal('regle-modal');
    }

    // ═══════════════════════════════════════════════════════════
    // SUBMIT
    // ═══════════════════════════════════════════════════════════
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
                produits:       produitsSelectionnes.map(p => ({ type: p.type, id: p.id })),
            }
            : {
                partenaire_id:  $('#partenaire_id').value,
                type_document:  $('#type_document').value,
                obligatoire:    $('#obligatoire').checked,
                validite_jours: $('#validite_jours').value || null,
                actif:          $('#actif').checked,
                produits:       produitsSelectionnes.map(p => ({ type: p.type, id: p.id })),
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
            const errs = res.data?.errors || res.data?.message;
            UI.toast('danger', typeof errs === 'object' ? Object.values(errs).flat().join(' · ') : (errs || 'Erreur'));
        }
    }

    // ═══════════════════════════════════════════════════════════
    // TOGGLE ACTIF
    // ═══════════════════════════════════════════════════════════
    async function toggleActif(id, actifActuel) {
        const res = await API.put('/dsa/regles-kyc/' + id, { actif: !actifActuel });
        if (res.ok) {
            UI.toast('success', !actifActuel ? 'Activée' : 'Désactivée');
            loadRegles();
        }
    }

    init();
})();