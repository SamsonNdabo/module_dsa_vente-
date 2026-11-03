(function () {
    'use strict';
    if (!document.getElementById('search-client')) return;

    const state = {
        client: null,
        kycOk: false,
        typeProduit: 'STOCK',
        partenaireId: null,
        produits: [],
        panier: [],
        epargneProduit: null,
    };

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    // ═══════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════
    function toast(type, msg) {
        if (window.UI && typeof UI.toast === 'function') UI.toast(type, msg);
        else { console.log(`[${type}]`, msg); if (type === 'danger') alert(msg); }
    }

    function setLoading(btn, loading) {
        if (!btn) return;
        const spinner = btn.querySelector('.spinner');
        if (spinner) spinner.classList.toggle('hidden', !loading);
        btn.disabled = loading;
        btn.classList.toggle('opacity-60', loading);
    }

    function formatMoney(amount, devise) {
        const num = parseFloat(amount || 0);
        return num.toFixed(2) + (devise ? ' ' + devise : '');
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

    // ═══════════════════════════════════════════════════════════
    // CALCUL AUTO DATE FIN RÉCURRENCE
    // ═══════════════════════════════════════════════════════════
    function calculerDateFinRecurrence() {
        const type = $('#recurrence_type')?.value;
        const intervalle = parseInt($('#recurrence_intervalle')?.value, 10) || 1;
        const nbOccurrences = parseInt($('#recurrence_nb_occurrences')?.value, 10) || 0;
        const dateDebut = $('#date_livraison_souhaitee')?.value;

        const apercu = $('#recurrence-apercu');
        const dateEl = $('#recurrence-apercu-date');
        const detailEl = $('#recurrence-apercu-detail');

        if (!type || type === 'AUCUNE' || nbOccurrences < 1) {
            apercu?.classList.add('hidden');
            return;
        }

        const debut = dateDebut ? new Date(dateDebut) : new Date();
        let dateFin = new Date(debut);
        const nbPeriodes = nbOccurrences - 1;

        if (type === 'HEBDOMADAIRE') {
            dateFin.setDate(dateFin.getDate() + (7 * intervalle * nbPeriodes));
        } else if (type === 'MENSUEL') {
            dateFin.setMonth(dateFin.getMonth() + (intervalle * nbPeriodes));
        } else if (type === 'TRIMESTRIEL') {
            dateFin.setMonth(dateFin.getMonth() + (3 * intervalle * nbPeriodes));
        } else if (type === 'ANNUEL') {
            dateFin.setFullYear(dateFin.getFullYear() + (intervalle * nbPeriodes));
        }

        const formatDate = d => {
            const dd = String(d.getDate()).padStart(2, '0');
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const yyyy = d.getFullYear();
            return `${dd}/${mm}/${yyyy}`;
        };

        if (dateEl) dateEl.textContent = formatDate(dateFin);
        if (detailEl) {
            const types = {
                HEBDOMADAIRE: 'semaine',
                MENSUEL: 'mois',
                TRIMESTRIEL: 'trimestre',
                ANNUEL: 'an',
            };
            const plural = intervalle > 1 ? 's' : '';
            detailEl.textContent = `${nbOccurrences} livraisons — tous les ${intervalle} ${types[type]}${plural} (début ${formatDate(debut)})`;
        }
        apercu?.classList.remove('hidden');
    }

    // ═══════════════════════════════════════════════════════════
    // INIT
    // ═══════════════════════════════════════════════════════════
    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await loadPartenaires();
        attachEvents();
    }

    async function loadPartenaires() {
        try {
            const res = await API.get('/dsa/partenaires/options');
            if (res.ok && res.data?.data) {
                const sel = $('#partenaire_id');
                res.data.data.forEach(p => {
                    sel.insertAdjacentHTML('beforeend',
                        `<option value="${p.id}">${UI.escapeHtml(p.nom)}</option>`);
                });
            }
        } catch (e) { console.error(e); }
    }

    // ═══════════════════════════════════════════════════════════
    // RECHERCHE CLIENT
    // ═══════════════════════════════════════════════════════════
    async function searchClients(q) {
        const container = $('#search-client-results');
        if (!q || q.length < 2) {
            container.classList.add('hidden');
            return;
        }

        try {
            const res = await API.get(`/dsa/clients?q=${encodeURIComponent(q)}&per_page=10`);
            if (!res.ok) return;

            const clients = res.data?.data || [];

            if (!clients.length) {
                container.innerHTML = '<div class="p-3 text-sm text-gray-500">Aucun client trouvé</div>';
                container.classList.remove('hidden');
                return;
            }

            container.innerHTML = clients.map(c => {
                const kycBadge = c.kyc_statut === 'VALIDE'
                    ? 'bg-emerald-100 text-emerald-700'
                    : 'bg-amber-100 text-amber-700';

                return `
                    <div class="p-3 border-b border-gray-100 hover:bg-indigo-50 cursor-pointer" data-client-id="${c.id}">
                        <div class="flex items-center justify-between">
                            <div class="font-medium text-gray-900">${UI.escapeHtml(c.nom_complet)}</div>
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold ${kycBadge}">
                                ${UI.escapeHtml(c.kyc_statut || 'AUCUN')}
                            </span>
                        </div>
                        <div class="text-xs text-gray-500 mt-0.5">
                            ${UI.escapeHtml(c.code_client || '')} · ${UI.escapeHtml(c.telephone || '')}
                        </div>
                    </div>
                `;
            }).join('');

            container.classList.remove('hidden');

            container.querySelectorAll('[data-client-id]').forEach(el => {
                el.addEventListener('click', () => {
                    const id = parseInt(el.dataset.clientId, 10);
                    const client = clients.find(x => x.id === id);
                    if (client) selectClient(client);
                });
            });
        } catch (e) { console.error(e); }
    }

    function selectClient(client) {
        state.client = client;
        state.kycOk = client.kyc_statut === 'VALIDE';
        state.panier = [];
        state.epargneProduit = null;

        $('#client-nom').textContent = client.nom_complet;
        $('#client-code').textContent = `${client.code_client} · ${client.telephone || ''}`;
        $('#client-selectionne').classList.remove('hidden');
        $('#search-client-results').classList.add('hidden');
        $('#search-client').value = client.nom_complet;

        $('#produit-bloc').classList.remove('hidden');
        loadProduits();
    }

    // ═══════════════════════════════════════════════════════════
    // PRODUITS
    // ═══════════════════════════════════════════════════════════
    async function loadProduits() {
        const container = $('#produits-liste');
        const type = state.typeProduit;

        container.innerHTML = `
            <div class="col-span-2 text-sm text-gray-400 italic text-center py-4">
                <i class="fas fa-spinner fa-spin mr-1"></i>Chargement...
            </div>`;

        try {
            let url = `/dsa/souscriptions/produits?type=${type}`;
            if (state.partenaireId !== null && state.partenaireId !== '') {
                url += `&partenaire_id=${state.partenaireId}`;
            }

            const res = await API.get(url);
            if (!res.ok) {
                container.innerHTML = '<div class="col-span-2 text-sm text-red-500 text-center py-4">Erreur de chargement.</div>';
                return;
            }

            state.produits = res.data?.data || [];

            if (!state.produits.length) {
                container.innerHTML = `
                    <div class="col-span-2 text-sm text-gray-400 italic text-center py-4">
                        Aucun produit disponible.
                    </div>`;
                return;
            }

            container.innerHTML = state.produits.map(p => {
                const info = type === 'STOCK'
                    ? (p.prix_vente ? formatMoney(p.prix_vente_TTC ?? p.prix_vente, p.devise?.code) : '')
                    : (p.solde_minimum ? `Min. ${p.solde_minimum}` : '');

                const badge = p.est_partenaire
                    ? `<span class="inline-block mt-1 px-2 py-0.5 text-[10px] font-semibold rounded-full bg-purple-100 text-purple-700">
                           <i class="fas fa-handshake mr-0.5"></i>Partenaire
                       </span>`
                    : `<span class="inline-block mt-1 px-2 py-0.5 text-[10px] font-semibold rounded-full bg-gray-100 text-gray-600">
                           Standard
                       </span>`;

                const stockInfo = type === 'STOCK' && p.stock_disponible !== null && p.stock_disponible !== undefined
                    ? `<div class="text-xs text-gray-500 mt-1">Stock : ${p.stock_disponible ?? 0}</div>`
                    : '';

                return `
                    <div class="produit-card p-3 border border-gray-200 rounded-lg cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition"
                         data-produit-id="${p.id}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-sm text-gray-900 truncate">${UI.escapeHtml(p.nom)}</div>
                                <div class="text-xs text-gray-500 font-mono">${UI.escapeHtml(p.code || '')}</div>
                                ${info ? `<div class="text-xs text-indigo-600 mt-1">${UI.escapeHtml(info)}</div>` : ''}
                                ${stockInfo}
                                ${badge}
                            </div>
                            <i class="fas fa-plus-circle text-indigo-400 text-xl mt-1"></i>
                        </div>
                    </div>
                `;
            }).join('');

            container.querySelectorAll('[data-produit-id]').forEach(el => {
                el.addEventListener('click', () => {
                    const id = parseInt(el.dataset.produitId, 10);
                    const produit = state.produits.find(x => x.id === id);
                    if (produit) handleProduitClick(produit);
                });
            });
        } catch (e) {
            console.error('[SOUSCRIPTION] Erreur loadProduits:', e);
            container.innerHTML = '<div class="col-span-2 text-sm text-red-500 text-center py-4">Erreur réseau.</div>';
        }
    }

    function handleProduitClick(produit) {
        if (state.typeProduit === 'STOCK') {
            ajouterAuPanier(produit);
        } else {
            selectEpargneProduit(produit);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // PANIER
    // ═══════════════════════════════════════════════════════════
    function ajouterAuPanier(produit) {
        const existant = state.panier.find(p => p.id_produit === produit.id);

        if (existant) {
            existant.quantite += 1;
        } else {
            state.panier.push({
                id_produit:       produit.id,
                nom:              produit.nom,
                code:             produit.code || '',
                quantite:         1,
                prix:             parseFloat(produit.prix_vente_TTC ?? produit.prix_vente ?? 0),
                prix_ht:          parseFloat(produit.prix_vente ?? 0),
                devise:           produit.devise?.code || '',
                est_partenaire:   !!produit.est_partenaire,
                partenaire_id:    produit.partenaire_id || null,
                stock_disponible: produit.stock_disponible ?? 0,
            });
        }

        renderPanier();
        toast('success', `${produit.nom} ajouté au panier`);
    }

    function modifierQuantite(index, delta) {
        if (!state.panier[index]) return;
        const newQ = state.panier[index].quantite + delta;
        if (newQ < 1) { retirerDuPanier(index); return; }
        state.panier[index].quantite = newQ;
        renderPanier();
    }

    function retirerDuPanier(index) {
        state.panier.splice(index, 1);
        renderPanier();
    }

    function renderPanier() {
        const bloc = $('#panier-bloc');
        const liste = $('#panier-liste');

        if (!state.panier.length) {
            bloc.classList.add('hidden');
            return;
        }

        bloc.classList.remove('hidden');

        liste.innerHTML = state.panier.map((l, i) => `
            <div class="flex items-center justify-between p-3 bg-gray-50 border border-gray-200 rounded-lg">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-900">${UI.escapeHtml(l.nom)}</span>
                        ${l.est_partenaire
                            ? '<span class="px-1.5 py-0.5 text-[10px] font-semibold rounded-full bg-purple-100 text-purple-700">Partenaire</span>'
                            : '<span class="px-1.5 py-0.5 text-[10px] font-semibold rounded-full bg-gray-100 text-gray-600">Standard</span>'}
                    </div>
                    <div class="text-xs text-gray-500 font-mono">${UI.escapeHtml(l.code)}</div>
                    <div class="text-xs text-gray-500 mt-0.5">
                        Stock : ${l.stock_disponible}
                        ${l.quantite > l.stock_disponible
                            ? '<span class="text-amber-600 font-semibold ml-1">(stock insuffisant)</span>'
                            : ''}
                    </div>
                </div>

                <div class="flex items-center gap-3 ml-3">
                    <div class="flex items-center border border-gray-300 rounded-lg overflow-hidden">
                        <button type="button" data-action="minus" data-index="${i}" class="w-7 h-7 flex items-center justify-center hover:bg-gray-100 text-gray-600">−</button>
                        <span class="w-8 text-center text-sm font-semibold">${l.quantite}</span>
                        <button type="button" data-action="plus" data-index="${i}" class="w-7 h-7 flex items-center justify-center hover:bg-gray-100 text-gray-600">+</button>
                    </div>
                    <div class="text-sm font-bold text-indigo-600 min-w-[80px] text-right">
                        ${formatMoney(l.prix * l.quantite, l.devise)}
                    </div>
                    <button type="button" data-action="remove" data-index="${i}" class="text-red-500 hover:text-red-700">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `).join('');

        const total = state.panier.reduce((sum, l) => sum + (l.prix * l.quantite), 0);
        $('#panier-total').textContent = formatMoney(total, state.panier[0]?.devise || '');

        updateLivraisonVisibility();

        liste.querySelectorAll('[data-action]').forEach(btn => {
            btn.addEventListener('click', () => {
                const index = parseInt(btn.dataset.index, 10);
                const action = btn.dataset.action;
                if (action === 'plus')    modifierQuantite(index, 1);
                if (action === 'minus')   modifierQuantite(index, -1);
                if (action === 'remove')  retirerDuPanier(index);
            });
        });
    }

    function updateLivraisonVisibility() {
        const mode = document.querySelector('input[name="mode_execution"]:checked')?.value || 'AUTO';
        const wrapper = $('#livraison-wrapper');
        if (!wrapper) return;

        const stockKo = state.panier.some(l => l.quantite > l.stock_disponible);

        if (mode === 'COMMANDE' || (mode === 'AUTO' && stockKo)) {
            wrapper.classList.remove('hidden');
        } else {
            wrapper.classList.add('hidden');
        }
    }

    // ═══════════════════════════════════════════════════════════
    // ÉPARGNE
    // ═══════════════════════════════════════════════════════════
    function selectEpargneProduit(produit) {
        state.epargneProduit = produit;

        $('#epargne-bloc').classList.remove('hidden');

        const badge = produit.est_partenaire
            ? 'bg-purple-50 border-purple-200 text-purple-700'
            : 'bg-indigo-50 border-indigo-200 text-indigo-700';

        $('#epargne-produit-info').innerHTML = `
            <div class="p-3 border rounded-lg ${badge}">
                <div class="text-xs font-semibold uppercase">
                    ${produit.est_partenaire ? 'Produit partenaire' : 'Produit standard'}
                </div>
                <div class="font-bold text-gray-900 mt-1">${UI.escapeHtml(produit.nom)}</div>
                <div class="text-xs text-gray-500 font-mono">${UI.escapeHtml(produit.code)}</div>
                <div class="text-xs mt-1">Solde minimum : <strong>${produit.solde_minimum ?? 0}</strong></div>
            </div>`;

        $('#montant_initial').value = produit.solde_minimum || 0;
        $('#epargne-bloc').scrollIntoView({ behavior: 'smooth' });
    }

    // ═══════════════════════════════════════════════════════════
    // SOUMISSION
    // ═══════════════════════════════════════════════════════════
    async function soumettre() {
        if (!state.client) { toast('danger', 'Sélectionnez un client.'); return; }
        if (!state.panier.length) { toast('danger', 'Le panier est vide.'); return; }

        const btn = $('#btn-soumettre');
        const modeExecution = document.querySelector('input[name="mode_execution"]:checked')?.value || 'AUTO';

        // 🆕 Si nb_occurrences renseigné → date_fin calculée backend, on n'envoie PAS date_fin
        const nbOccurrences = parseInt($('#recurrence_nb_occurrences')?.value, 10) || null;
        const dateFinManuelle = $('#recurrence_date_fin')?.value || null;

        const payload = {
            client_id:      state.client.id,
            type_produit:   'STOCK',
            mode_execution: modeExecution,
            items: state.panier.map(l => ({
                id_produit:    l.id_produit,
                quantite:      l.quantite,
                id_partenaire: l.partenaire_id,
            })),
            mode_livraison:           $('#mode_livraison')?.value,
            telephone_livraison:      $('#telephone_livraison')?.value.trim() || null,
            adresse_livraison:        $('#adresse_livraison')?.value.trim() || null,
            date_livraison_souhaitee: $('#date_livraison_souhaitee')?.value || null,
            est_recurrente:           $('#est_recurrente')?.checked || false,
            recurrence_type:          $('#recurrence_type')?.value || 'AUCUNE',
            recurrence_intervalle:    parseInt($('#recurrence_intervalle')?.value, 10) || null,
            recurrence_date_fin:      nbOccurrences ? null : dateFinManuelle,   // ← Calculé auto si nb_occurrences
            recurrence_nb_occurrences: nbOccurrences,
            notes:                    $('#notes')?.value.trim() || null,
        };

        setLoading(btn, true);

        try {
            const res = await API.post('/dsa/souscriptions', payload);
            setLoading(btn, false);

            if (res.ok) {
                afficherResultat(res.data.data);
                toast('success', 'Souscription enregistrée !');
                state.panier = [];
                renderPanier();
                $('#panier-bloc').classList.add('hidden');
            } else {
                const err = res.data?.errors || res.data?.message;
                toast('danger', typeof err === 'object' ? Object.values(err).flat().join(' · ') : (err || 'Erreur'));
            }
        } catch (e) {
            setLoading(btn, false);
            console.error(e);
            toast('danger', 'Erreur réseau');
        }
    }

    async function soumettreEpargne() {
        if (!state.client) { toast('danger', 'Sélectionnez un client.'); return; }
        if (!state.epargneProduit) { toast('danger', 'Sélectionnez un produit épargne.'); return; }

        const btn = $('#btn-soumettre-epargne');
        const payload = {
            client_id:       state.client.id,
            type_produit:    'EPARGNE',
            id_produit:      state.epargneProduit.id,
            id_partenaire:   state.epargneProduit.partenaire_id || null,
            montant_initial: parseFloat($('#montant_initial').value) || 0,
            notes:           $('#notes')?.value.trim() || null,
        };

        setLoading(btn, true);

        try {
            const res = await API.post('/dsa/souscriptions', payload);
            setLoading(btn, false);

            if (res.ok) {
                afficherResultat(res.data.data);
                toast('success', 'Compte épargne créé !');
                $('#epargne-bloc').classList.add('hidden');
                state.epargneProduit = null;
            } else {
                const err = res.data?.errors || res.data?.message;
                toast('danger', typeof err === 'object' ? Object.values(err).flat().join(' · ') : (err || 'Erreur'));
            }
        } catch (e) {
            setLoading(btn, false);
            console.error(e);
            toast('danger', 'Erreur réseau');
        }
    }

    // ═══════════════════════════════════════════════════════════
    // RÉSULTAT
    // ═══════════════════════════════════════════════════════════
    function afficherResultat(data) {
        const bloc = $('#resultat-bloc');
        let html = '';

        if (data.type === 'VENTE') {
            html = `
                <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-lg mb-3">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-check-circle text-emerald-600 text-2xl"></i>
                        <div class="font-bold text-emerald-800">Vente(s) enregistrée(s)</div>
                    </div>
                </div>`;

            // Vente standard
            if (data.facture_ref) {
                const caisseNom = data.caisse_nom || 'Caisse succursale';
                const caisseId  = data.caisse_id || '';

                html += `
                    <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg mb-2">
                        <div class="text-xs text-amber-600 uppercase font-semibold">Facture standard — En attente de paiement</div>
                        <div class="font-bold text-gray-900 mt-1">${UI.escapeHtml(data.facture_ref)}</div>
                        <div class="text-sm">Total : <strong>${data.total_ventes}</strong></div>
                        <div class="text-sm">Caisse : <strong>${UI.escapeHtml(caisseNom)}</strong></div>
                        <div class="text-sm">Statut : <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-xs font-semibold">EN_ATTENTE_PAIEMENT</span></div>

                        <button type="button" data-action="finaliser"
                                data-facture-id="${data.facture_id}"
                                data-caisse-id="${caisseId}"
                                data-caisse-nom="${UI.escapeHtml(caisseNom)}"
                                data-facture-ref="${UI.escapeHtml(data.facture_ref)}"
                                data-total-ttc="${data.total_ventes}"
                                class="mt-3 px-4 py-2 text-sm font-semibold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700">
                            <i class="fas fa-cash-register mr-1"></i> Finaliser la vente (payer)
                        </button>
                    </div>`;
            }

            // Ventes hors-caisse
            if (data.ventes_hors_caisse && data.ventes_hors_caisse.length) {
                html += `<div class="p-3 bg-purple-50 border border-purple-200 rounded-lg mt-2">
                    <div class="text-xs text-purple-600 uppercase font-semibold mb-2">
                        <i class="fas fa-handshake mr-1"></i>Ventes hors-caisse (partenaires)
                    </div>`;
                data.ventes_hors_caisse.forEach(v => {
                    html += `
                        <div class="py-2 border-b border-purple-100 last:border-0">
                            <div class="flex justify-between">
                                <div>
                                    <div class="font-medium">${UI.escapeHtml(v.produit_nom)}</div>
                                    <div class="text-xs text-gray-500 font-mono">${UI.escapeHtml(v.facture_ref)}</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold">${v.montant_total}</div>
                                    <div class="text-xs">× ${v.quantite}</div>
                                </div>
                            </div>
                            <button type="button" data-action="finaliser"
                                    data-facture-id="${v.facture_id}"
                                    data-facture-ref="${UI.escapeHtml(v.facture_ref)}"
                                    data-total-ttc="${v.montant_total}"
                                    class="mt-2 px-3 py-1.5 text-xs font-semibold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700">
                                <i class="fas fa-cash-register mr-1"></i> Finaliser
                            </button>
                        </div>`;
                });
                html += `</div>`;
            }
        } else if (data.type === 'COMMANDE') {
            html = `
                <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg mb-3">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-shopping-cart text-amber-600 text-2xl"></i>
                        <div class="font-bold text-amber-800">Commande(s) enregistrée(s)</div>
                    </div>
                </div>`;

            if (data.commande_ref) {
                html += `
                    <div class="p-3 bg-white border border-gray-200 rounded-lg mb-2">
                        <div class="text-xs text-gray-500 uppercase">Commande standard</div>
                        <div class="font-bold text-gray-900">${UI.escapeHtml(data.commande_ref)}</div>
                        <div class="text-sm">Total : <strong>${data.total_commandes}</strong></div>
                    </div>`;
            }

            if (data.commandes_hors_caisse && data.commandes_hors_caisse.length) {
                html += `<div class="p-3 bg-purple-50 border border-purple-200 rounded-lg mt-2">
                    <div class="text-xs text-purple-600 uppercase font-semibold mb-2">
                        <i class="fas fa-handshake mr-1"></i>Commandes hors-caisse (partenaires)
                    </div>`;
                data.commandes_hors_caisse.forEach(c => {
                    html += `
                        <div class="flex justify-between py-1 border-b border-purple-100 last:border-0">
                            <div>
                                <div class="font-medium">${UI.escapeHtml(c.produit_nom)}</div>
                                <div class="text-xs text-gray-500 font-mono">${UI.escapeHtml(c.commande_ref)}</div>
                                ${c.recurrence_date_fin ? `<div class="text-xs text-indigo-600">Fin récurrence : ${c.recurrence_date_fin}</div>` : ''}
                            </div>
                            <div class="text-right">
                                <div class="font-bold">${c.montant_total}</div>
                                <div class="text-xs">× ${c.quantite}</div>
                            </div>
                        </div>`;
                });
                html += `</div>`;
            }
        } else if (data.type_operation === 'COMPTE_EPARGNE') {
            html = `
                <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
                    <div class="flex items-center gap-3 mb-3">
                        <i class="fas fa-piggy-bank text-emerald-600 text-2xl"></i>
                        <div class="font-bold text-emerald-800">Compte d'épargne créé</div>
                    </div>
                    <div class="text-sm text-gray-700 space-y-1">
                        <div><strong>N° compte :</strong> <span class="font-mono">${UI.escapeHtml(data.numero_compte)}</span></div>
                        <div><strong>Produit :</strong> ${UI.escapeHtml(data.produit_nom)}</div>
                        <div><strong>Solde initial :</strong> ${data.solde_initial}</div>
                    </div>
                </div>`;
        }

        bloc.innerHTML = html + `
            <div class="mt-4 flex justify-end">
                <button type="button" onclick="location.reload()"
                        class="px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    <i class="fas fa-plus mr-1"></i> Nouvelle souscription
                </button>
            </div>`;
        bloc.classList.remove('hidden');
        bloc.scrollIntoView({ behavior: 'smooth' });
    }

    // ═══════════════════════════════════════════════════════════
    // FINALISER LA VENTE
    // ═══════════════════════════════════════════════════════════
    function ouvrirFinaliserModal(factureId, caisseId, caisseNom, factureRef, totalTTC) {
        openModal('finaliser-modal');

        $('#finaliser-facture-id').value = factureId;
        $('#finaliser-facture-ref').textContent = factureRef || '';
        $('#finaliser-caisse-info').textContent = caisseNom || 'Caisse de la succursale';

        $('#finaliser-montant').value = totalTTC || '';
        $('#finaliser-notes').value = '';
        $('#finaliser-mode').value = 'ESPECES';
    }

    async function finaliserVente(e) {
        e.preventDefault();
        const btn = $('#btn-finaliser');
        const factureId = parseInt($('#finaliser-facture-id').value, 10);

        if (!factureId) {
            toast('danger', 'Facture introuvable.');
            return;
        }

        const payload = {
            mode_paiement: $('#finaliser-mode').value,
            montant:       parseFloat($('#finaliser-montant').value) || null,
            notes:         $('#finaliser-notes').value.trim() || null,
        };

        setLoading(btn, true);

        try {
            const res = await API.post(`/dsa/souscriptions/${factureId}/finaliser`, payload);
            setLoading(btn, false);

            if (res.ok) {
                toast('success', 'Vente finalisée !');
                closeModal('finaliser-modal');
                if (res.data?.data) {
                    afficherResultatFinalisation(res.data.data);
                }
            } else {
                const err = res.data?.errors || res.data?.message;
                toast('danger', typeof err === 'object' ? Object.values(err).flat().join(' · ') : (err || 'Erreur'));
            }
        } catch (e) {
            setLoading(btn, false);
            console.error(e);
            toast('danger', 'Erreur réseau');
        }
    }

    function afficherResultatFinalisation(data) {
        const bloc = $('#resultat-bloc');
        bloc.innerHTML = `
            <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
                <div class="flex items-center gap-3 mb-3">
                    <i class="fas fa-check-circle text-emerald-600 text-2xl"></i>
                    <div class="font-bold text-emerald-800">Vente finalisée</div>
                </div>
                <div class="text-sm text-gray-700 space-y-1">
                    <div><strong>Facture :</strong> <span class="font-mono">${UI.escapeHtml(data.facture_ref)}</span></div>
                    <div><strong>Statut :</strong> <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-xs font-semibold">${UI.escapeHtml(data.statut)}</span></div>
                    <div><strong>Total :</strong> ${data.total}</div>
                    <div><strong>Payé :</strong> ${data.montant_regle}</div>
                    <div><strong>Caisse :</strong> ${UI.escapeHtml(data.caisse_nom || '—')}</div>
                    <div><strong>Circuit :</strong> ${UI.escapeHtml(data.circuit || '—')}</div>
                    <div><strong>Mode :</strong> ${UI.escapeHtml(data.paiement?.mode_paiement || '—')}</div>
                </div>
                <div class="mt-4 flex justify-end">
                    <button type="button" onclick="location.reload()"
                            class="px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                        <i class="fas fa-plus mr-1"></i> Nouvelle souscription
                    </button>
                </div>
            </div>`;
        bloc.classList.remove('hidden');
        bloc.scrollIntoView({ behavior: 'smooth' });
    }

    // ═══════════════════════════════════════════════════════════
    // ÉVÉNEMENTS
    // ═══════════════════════════════════════════════════════════
    function attachEvents() {
        let searchTimer;
        $('#search-client').addEventListener('input', e => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => searchClients(e.target.value.trim()), 300);
        });

        $('#btn-clear-client').addEventListener('click', () => {
            state.client = null;
            state.kycOk = false;
            state.panier = [];
            state.epargneProduit = null;

            $('#client-selectionne').classList.add('hidden');
            $('#produit-bloc').classList.add('hidden');
            $('#panier-bloc').classList.add('hidden');
            $('#epargne-bloc').classList.add('hidden');
            $('#resultat-bloc').classList.add('hidden');
            $('#search-client').value = '';
        });

        // Onglets
        $$('.souscription-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                state.typeProduit = tab.dataset.tab;

                $$('.souscription-tab').forEach(t => {
                    const active = t.dataset.tab === state.typeProduit;
                    t.classList.toggle('border-indigo-600', active);
                    t.classList.toggle('text-indigo-600', active);
                    t.classList.toggle('border-transparent', !active);
                    t.classList.toggle('text-gray-500', !active);
                });

                if (state.typeProduit === 'STOCK') {
                    $('#panier-bloc').classList.toggle('hidden', state.panier.length === 0);
                    $('#epargne-bloc').classList.add('hidden');
                } else {
                    $('#panier-bloc').classList.add('hidden');
                    $('#epargne-bloc').classList.add('hidden');
                }

                if (state.client) loadProduits();
            });
        });

        // Filtre partenaire
        $('#partenaire_id').addEventListener('change', e => {
            state.partenaireId = e.target.value ? parseInt(e.target.value, 10) : null;
            loadProduits();
        });

        // Mode d'exécution
        $$('input[name="mode_execution"]').forEach(radio => {
            radio.addEventListener('change', updateLivraisonVisibility);
        });

        // Toggle récurrence
        $('#est_recurrente')?.addEventListener('change', e => {
            const wrapper = $('#recurrence-wrapper');
            if (wrapper) wrapper.classList.toggle('hidden', !e.target.checked);
            if (e.target.checked) calculerDateFinRecurrence();
        });

        // 🆕 Recalcul date fin en temps réel
        $('#recurrence_type')?.addEventListener('change', calculerDateFinRecurrence);
        $('#recurrence_intervalle')?.addEventListener('input', calculerDateFinRecurrence);
        $('#recurrence_nb_occurrences')?.addEventListener('input', calculerDateFinRecurrence);
        $('#date_livraison_souhaitee')?.addEventListener('change', calculerDateFinRecurrence);

        // Boutons panier
        $('#btn-annuler').addEventListener('click', () => {
            state.panier = [];
            renderPanier();
            $('#panier-bloc').classList.add('hidden');
        });

        $('#btn-annuler-epargne').addEventListener('click', () => {
            state.epargneProduit = null;
            $('#epargne-bloc').classList.add('hidden');
        });

        // Submit
        $('#btn-soumettre').addEventListener('click', soumettre);
        $('#btn-soumettre-epargne').addEventListener('click', soumettreEpargne);
        $('#finaliser-form')?.addEventListener('submit', finaliserVente);

        // Bouton Finaliser (délégation)
        document.addEventListener('click', e => {
            const btn = e.target.closest('[data-action="finaliser"]');
            if (!btn) return;

            const factureId  = parseInt(btn.dataset.factureId, 10);
            const caisseId   = btn.dataset.caisseId ? parseInt(btn.dataset.caisseId, 10) : null;
            const caisseNom  = btn.dataset.caisseNom || '';
            const factureRef = btn.dataset.factureRef || '';
            const totalTTC   = parseFloat(btn.dataset.totalTtc) || 0;

            if (factureId) ouvrirFinaliserModal(factureId, caisseId, caisseNom, factureRef, totalTTC);
        });

        // Fermeture modals
        $$('[data-close-modal]').forEach(b => {
            b.addEventListener('click', () => {
                const mid = b.dataset.closeModal;
                if (mid) closeModal(mid);
            });
        });

        $('#finaliser-modal')?.addEventListener('click', e => {
            if (e.target.id === 'finaliser-modal') closeModal('finaliser-modal');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();