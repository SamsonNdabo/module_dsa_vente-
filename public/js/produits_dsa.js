(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    let state = { page: 1, recherche: '', partenaire_id: '', statut: '', stock_requis: '' };
    let currentTab = 'pos';
    let partenaires = [];
    let selectedPosProduit = null;

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await loadPartenaires();
        await loadDevises();
        await loadProduits();
        attachEvents();
    }

    // ─────────────────────────────────────────────────────────
    // CHARGEMENTS
    // ─────────────────────────────────────────────────────────
    async function loadPartenaires() {
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
    }

      async function loadDevises() {
        const selPos = $('#produit_pos_devise_id');
        const selDsa = $('#devise_id');
        if (!selPos && !selDsa) return;

        // On vide et remet le placeholder sur chaque select présent
        [selPos, selDsa].forEach(sel => {
            if (!sel) return;
            sel.innerHTML = '<option value="">— Choisir —</option>';
        });

        let devises = [];

        try {
            const res = await API.get('/devises?per_page=100&only_actif=1');

            if (res.ok && Array.isArray(res.data?.data?.data)) {
                devises = res.data.data.data;
            } else if (res.ok && Array.isArray(res.data?.data)) {
                // au cas où le controller renvoie data directement
                devises = res.data.data;
            }
        } catch (e) {
            console.error('[DEVISES] erreur API:', e);
        }

        // Fallback si l'API échoue ou ne renvoie rien
        if (!devises.length) {
            devises = [
                { id: 1, code: 'CDF', nom: 'Franc congolais', symbole: 'FC', devise_defaut: true  },
                { id: 2, code: 'USD', nom: 'Dollar américain', symbole: '$',  devise_defaut: false },
                { id: 3, code: 'EUR', nom: 'Euro',             symbole: '€',  devise_defaut: false },
            ];
        }

        // On remplit les deux selects avec la même liste
        [selPos, selDsa].forEach(sel => {
            if (!sel) return;
            devises.forEach(d => {
                const label = d.symbole
                    ? `${d.code} (${d.symbole})`
                    : `${d.code} — ${d.nom ?? d.libelle ?? ''}`.trim();

                const selected = d.devise_defaut ? 'selected' : '';
                sel.innerHTML += `<option value="${d.id}" ${selected}>${UI.escapeHtml(label)}</option>`;
            });
        });
    }
    async function loadProduits(page = 1) {
        state.page = page;
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="9" class="p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page, per_page: 15,
            ...(state.recherche && { q: state.recherche }),
            ...(state.partenaire_id && { partenaire_id: state.partenaire_id }),
            ...(state.statut && { statut: state.statut }),
            ...(state.stock_requis !== '' && { stock_requis: state.stock_requis }),
        });

        const res = await API.get('/dsa/produits?' + params);
        if (!res.ok) {
            tbody.innerHTML = `<tr><td colspan="9" class="p-10 text-center text-red-500">Erreur de chargement</td></tr>`;
            return;
        }

        const paginator = res.data.data;
        renderTable(paginator.data);
        computeStats(paginator.data);
        UI.renderPagination($('#pagination'), paginator, p => loadProduits(p));
    }

    function computeStats(items) {
        $('#stat-total').textContent        = items.length;
        $('#stat-actifs').textContent       = items.filter(p => p.statut === 'ACTIF').length;
        $('#stat-stock').textContent        = items.filter(p => p.stock_requis).length;
        $('#stat-activations').textContent  = items.filter(p => p.est_activation).length;
    }

    function renderTable(items) {
        const tbody = $('#table-body');
        if (!items.length) {
            tbody.innerHTML = `<tr><td colspan="9" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucun produit DSA</td></tr>`;
            return;
        }

        tbody.innerHTML = items.map(p => {
            // Devise réelle (fallback "—" si non chargée)
            const deviseCode = p.devise?.code ?? p.devise?.symbole ?? '—';

            // Commission : FIXE → "X <CODE>", POURCENTAGE → "X %"
            const commissionLabel = p.commission_type === 'POURCENTAGE'
                ? `${formatNumber(p.taux_commission ?? 0)} %`
                : `${formatNumber(p.commission)} ${deviseCode}`;

            // Prix de vente avec la vraie devise
            const prixLabel = p.prix_vente
                ? `${formatNumber(p.prix_vente)} ${deviseCode}`
                : '—';

            return `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(p.code)}</td>
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-900">${UI.escapeHtml(p.nom)}</div>
                    ${p.produit_pos ? `<div class="text-xs text-gray-500"><i class="fas fa-link text-emerald-500"></i> POS: ${UI.escapeHtml(p.produit_pos.code_barre)}</div>` : ''}
                </td>
                <td class="px-4 py-3 text-gray-600 text-xs">${p.partenaire ? UI.escapeHtml(p.partenaire.nom) : '—'}</td>
                <td class="px-4 py-3 text-xs">${UI.escapeHtml(p.type)}</td>
                <td class="px-4 py-3 text-gray-600">${prixLabel}</td>
                <td class="px-4 py-3 text-gray-600 text-xs">${commissionLabel}</td>
                <td class="px-4 py-3">
                    ${p.stock_requis
                        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700"><i class="fas fa-boxes text-[10px] mr-1"></i>Oui</span>'
                        : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">Non</span>'}
                    ${p.est_activation ? '<span class="ml-1 px-2 py-1 text-xs font-semibold rounded-full bg-purple-50 text-purple-700">Activation</span>' : ''}
                </td>
                <td class="px-4 py-3">
                    ${p.statut === 'ACTIF'
                        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700">Actif</span>'
                        : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">Inactif</span>'}
                </td>
                <td class="px-4 py-3 text-right">
                    <button data-action="edit" data-id="${p.id}" class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button data-action="delete" data-id="${p.id}" data-name="${UI.escapeHtml(p.nom)}"
                            class="px-2 py-1 text-xs text-red-600 bg-red-50 rounded hover:bg-red-100">
                        <i class="fas fa-ban"></i>
                    </button>
                </td>
            </tr>`;
        }).join('');
    }
    function formatNumber(n) {
        return n ? Number(n).toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) : '0';
    }

    // ─────────────────────────────────────────────────────────
    // ONGLETS
    // ─────────────────────────────────────────────────────────
    function setTab(tab) {
        currentTab = tab;
        $$('[data-tab]').forEach(b => {
            const active = b.dataset.tab === tab;
            b.classList.toggle('border-indigo-600', active);
            b.classList.toggle('text-indigo-600', active);
            b.classList.toggle('border-transparent', !active);
            b.classList.toggle('text-gray-500', !active);
        });
        $$('[data-tab-content]').forEach(c => {
            c.classList.toggle('hidden', c.dataset.tabContent !== tab);
        });
    }

    // ─────────────────────────────────────────────────────────
    // AUTOCOMPLETE PRODUIT POS
    // ─────────────────────────────────────────────────────────
    async function searchProduitsPos(query) {
        if (!query || query.length < 2) {
            $('#search-pos-results').classList.add('hidden');
            return;
        }

        const res = await API.get('/dsa/produits/produits-pos-disponibles?q=' + encodeURIComponent(query));
        if (!res.ok) return;

        const results = res.data.data || [];
        const container = $('#search-pos-results');

        if (!results.length) {
            container.innerHTML = '<div class="p-3 text-sm text-gray-500">Aucun produit POS disponible</div>';
            container.classList.remove('hidden');
            return;
        }

        container.innerHTML = results.map(p => `
            <div class="p-3 border-b border-gray-100 hover:bg-indigo-50 cursor-pointer" data-pos-id="${p.id}">
                <div class="font-medium text-gray-900">${UI.escapeHtml(p.nom)}</div>
                <div class="text-xs text-gray-500">${UI.escapeHtml(p.code_barre)} · ${p.prix_vente ? formatNumber(p.prix_vente) + ' CDF' : 'Pas de prix'}</div>
            </div>
        `).join('');
        container.classList.remove('hidden');

        container.querySelectorAll('[data-pos-id]').forEach(el => {
            el.addEventListener('click', () => {
                const id = parseInt(el.dataset.posId);
                const produit = results.find(r => r.id === id);
                selectProduitPos(produit);
            });
        });
    }

    function selectProduitPos(produit) {
        selectedPosProduit = produit;
        $('#produit_id_pos').value = produit.id;
        $('#pos-nom').textContent = produit.nom;
        $('#pos-code').textContent = produit.code_barre;
        $('#produit-pos-selectionne').classList.remove('hidden');
        $('#search-pos-results').classList.add('hidden');
        $('#search-pos').value = produit.nom;

        // Pré-remplir les champs DSA
        if (!$('#nom').value) $('#nom').value = produit.nom;
        if (!$('#prix_vente').value) $('#prix_vente').value = produit.prix_vente || '';
        if (!$('#produit_pos_code_barre').value) $('#produit_pos_code_barre').value = produit.code_barre;
    }

    function clearProduitPos() {
        selectedPosProduit = null;
        $('#produit_id_pos').value = '';
        $('#produit-pos-selectionne').classList.add('hidden');
        $('#search-pos').value = '';
        $('#search-pos-results').classList.add('hidden');
    }

    // ─────────────────────────────────────────────────────────
    // ÉVÉNEMENTS
    // ─────────────────────────────────────────────────────────
    function attachEvents() {
        // Onglets
        $$('[data-tab]').forEach(b => b.addEventListener('click', () => setTab(b.dataset.tab)));

        // Autocomplete POS
        $('#search-pos').addEventListener('input', UI.debounce(e => searchProduitsPos(e.target.value.trim()), 300));
        $('#btn-clear-pos').addEventListener('click', clearProduitPos);

        // Toggle commission
        $('#commission_type').addEventListener('change', e => {
            const isPct = e.target.value === 'POURCENTAGE';
            $('#commission-fixe-wrapper').classList.toggle('hidden', isPct);
            $('#commission-taux-wrapper').classList.toggle('hidden', !isPct);
        });

        // Toggle stock/activation
        $('#est_activation').addEventListener('change', e => {
            if (e.target.checked) $('#stock_requis').checked = false;
        });
        $('#stock_requis').addEventListener('change', e => {
            if (e.target.checked) $('#est_activation').checked = false;
        });

        // Actions table
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const { action, id, name } = btn.dataset;
            if (action === 'edit')   return openEdit(id);
            if (action === 'delete') return confirmDelete(id, name);
        });

        // Submit
        $('#produit-form').addEventListener('submit', onSubmit);

        // Filtres
        $('#search-input').addEventListener('input', UI.debounce(e => {
            state.recherche = e.target.value.trim();
            loadProduits(1);
        }, 400));
        $('#filter-partenaire').addEventListener('change', e => { state.partenaire_id = e.target.value; loadProduits(1); });
        $('#filter-statut').addEventListener('change',     e => { state.statut = e.target.value; loadProduits(1); });
        $('#filter-stock').addEventListener('change',      e => { state.stock_requis = e.target.value; loadProduits(1); });

        $('#btn-refresh').addEventListener('click', () => loadProduits(state.page));
        $('#btn-create').addEventListener('click', openCreate);

        $$('[data-close-modal]').forEach(b => b.addEventListener('click', () => UI.closeModal('produit-modal')));
        $('#produit-modal').addEventListener('click', e => {
            if (e.target.id === 'produit-modal') UI.closeModal('produit-modal');
        });
    }

    // ─────────────────────────────────────────────────────────
    // CRUD
    // ─────────────────────────────────────────────────────────
    function openCreate() {
        $('#modal-title').textContent = 'Nouveau produit DSA';
        $('#produit-form').reset();
        $('#produit-id').value = '';
        $('#produit_id_pos').value = '';
        $('#statut').value = 'ACTIF';
        $('#commission_type').value = 'FIXE';
        $('#commission-fixe-wrapper').classList.remove('hidden');
        $('#commission-taux-wrapper').classList.add('hidden');
        $('#produit-pos-selectionne').classList.add('hidden');
        $('#search-pos-results').classList.add('hidden');
        setTab('pos');
        UI.openModal('produit-modal');
    }

    async function openEdit(id) {
        const res = await API.get('/dsa/produits/' + id);
        if (!res.ok) { UI.toast('danger', 'Produit introuvable'); return; }

        const p = res.data.data;
        $('#modal-title').textContent = 'Modifier le produit DSA';
        $('#produit-id').value = p.id;

        if (p.produit_id) {
            selectProduitPos({
                id: p.produit_id,
                nom: p.produit_pos?.nom || '',
                code_barre: p.produit_pos?.code_barre || '',
                prix_vente: p.produit_pos?.prix_vente || '',
            });
            setTab('pos');
        } else {
            setTab('nouveau');
        }

        $('#code').value = p.code || '';
        $('#nom').value = p.nom || '';
        $('#partenaire_id').value = p.partenaire_id || '';
        $('#type').value = p.type || '';
        $('#categorie').value = p.categorie || '';
        $('#prix_vente').value = p.prix_vente || '';
        $('#unite').value = p.unite || '';
        $('#commission_type').value = p.commission_type || 'FIXE';
        $('#commission').value = p.commission || '';
        $('#taux_commission').value = p.taux_commission || '';
        $('#stock_requis').checked = !!p.stock_requis;
        $('#est_activation').checked = !!p.est_activation;
        $('#date_debut').value = p.date_debut || '';
        $('#date_fin').value = p.date_fin || '';
        $('#statut').value = p.statut || 'ACTIF';

        const isPct = p.commission_type === 'POURCENTAGE';
        $('#commission-fixe-wrapper').classList.toggle('hidden', isPct);
        $('#commission-taux-wrapper').classList.toggle('hidden', !isPct);

        UI.openModal('produit-modal');
    }

    async function onSubmit(e) {
        e.preventDefault();
        const id = $('#produit-id').value;
        const isEdit = !!id;
        const btn = $('#btn-save');

        const payload = {
            partenaire_id: $('#partenaire_id').value,
            code:          $('#code').value.trim(),
            nom:           $('#nom').value.trim(),
            type:          $('#type').value.trim(),
            categorie:     $('#categorie').value.trim() || null,
            prix_vente:    $('#prix_vente').value || null,
            devise_id:       $('#devise_id')?.value || null,
            unite:         $('#unite').value.trim() || null,
            commission:    $('#commission').value || null,
            commission_type: $('#commission_type').value,
            taux_commission: $('#taux_commission').value || null,
            stock_requis:  $('#stock_requis').checked,
            est_activation: $('#est_activation').checked,
            date_debut:    $('#date_debut').value || null,
            date_fin:      $('#date_fin').value || null,
            statut:        $('#statut').value,
        };

        // Cas 1 : produit POS existant
        if (currentTab === 'pos' && $('#produit_id_pos').value) {
            payload.produit_id = parseInt($('#produit_id_pos').value);
        }

        // Cas 2b : nouveau produit POS
        if (!isEdit && currentTab === 'nouveau') {
            payload.creer_produit_pos = $('#stock_requis').checked;
            if (payload.creer_produit_pos) {
                payload.produit_pos_code_barre    = $('#produit_pos_code_barre').value.trim() || null;
                payload.produit_pos_type          = $('#produit_pos_type').value;
                payload.produit_pos_devise_id     = $('#produit_pos_devise_id').value || null;
                payload.produit_pos_gere_imei     = $('#produit_pos_gere_imei').checked;
                payload.produit_pos_est_perissable= $('#produit_pos_est_perissable').checked;
            }
        }

        UI.setLoading(btn, true);
        const res = isEdit
            ? await API.put('/dsa/produits/' + id, payload)
            : await API.post('/dsa/produits', payload);
        UI.setLoading(btn, false);

        if (res.ok) {
            UI.toast('success', isEdit ? 'Produit mis à jour' : 'Produit DSA créé');
            UI.closeModal('produit-modal');
            loadProduits(state.page);
        } else {
            const errors = res.data?.errors || res.data?.error || res.data?.message;
            if (typeof errors === 'object') UI.toast('danger', Object.values(errors).flat().join(' · '));
            else UI.toast('danger', errors || 'Erreur');
        }
    }

    function confirmDelete(id, name) {
        UI.confirm(`Désactiver le produit "${name}" ?`, async () => {
            const res = await API.delete('/dsa/produits/' + id);
            if (res.ok) { UI.toast('success', 'Produit désactivé'); loadProduits(state.page); }
            else UI.toast('danger', res.data?.error || 'Erreur');
        }, { title: 'Désactiver', confirmText: 'Désactiver' });
    }

    init();
})();