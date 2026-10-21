(function () {
    'use strict';
    const page = document.getElementById('campagne-objectifs-mesurables-page');
    if (!page) return;

    const campagneId = parseInt(page.dataset.campagneId, 10);
    if (!campagneId) {
        console.error('[OBJ-MES] campagneId manquant');
        return;
    }

    let objectifs = [];
    let campagne = null;
    let filtreAgent = '';
    let filtreStatut = '';
    let filtreType = '';
    let agentsMap = {};

    // Pour la modal clients
    let currentObjectifIdForClients = null;

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    function safeToast(type, msg) {
        if (window.UI && typeof UI.toast === 'function') UI.toast(type, msg);
        else { console.log(`[${type}]`, msg); if (type === 'danger') alert(msg); }
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

        // Charger la campagne
        try {
            const resC = await API.get('/dsa/campagnes/' + campagneId);
            if (resC.ok) {
                campagne = resC.data.data || resC.data;
                $('#c-nom').textContent = `${campagne.code} — ${campagne.nom}`;
                const meta = [];
                if (campagne.partenaire) meta.push(campagne.partenaire.nom);
                if (campagne.budget) meta.push('Budget : ' + formatNumber(campagne.budget) + ' ' + (campagne.devise?.code || ''));
                if (campagne.statut) meta.push(campagne.statut);
                $('#c-meta').textContent = meta.join(' · ');

                const linkAttr = $('#btn-attribuer');
                if (linkAttr) linkAttr.href = window.APP_BASE + '/campagnes/' + campagneId + '/objectifs-agents';
            }
        } catch (e) {
            console.warn('[OBJ-MES] loadCampagne error:', e);
        }

        await loadObjectifs();
        attachEvents();
        initClientsModal();
    }

    // ══════════════════════════════════════════════════════════
    // CHARGEMENT OBJECTIFS
    // ══════════════════════════════════════════════════════════
    async function loadObjectifs() {
        const container = $('#objectifs-container');
        container.innerHTML = `<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></div>`;

        const params = new URLSearchParams();
        if (filtreAgent)  params.append('agent_dsa_id', filtreAgent);
        if (filtreStatut) params.append('statut', filtreStatut);
        if (filtreType)   params.append('type', filtreType);

        try {
            const res = await API.get(`/dsa/campagnes/${campagneId}/objectifs-mesurables?${params}`);
            console.log('[OBJ-MES] list →', res.status);

            if (!res.ok) {
                container.innerHTML = `<div class="bg-white rounded-2xl shadow-sm p-10 text-center text-red-500">Erreur ${res.status}</div>`;
                return;
            }

            objectifs = res.data.data || [];
            renderListe();
            computeStats();
            populateAgentsFilter();
        } catch (e) {
            console.error(e);
            container.innerHTML = `<div class="bg-white rounded-2xl shadow-sm p-10 text-center text-red-500">Exception : ${UI.escapeHtml(e.message)}</div>`;
        }
    }

    function populateAgentsFilter() {
        const sel = $('#filter-agent');
        if (!sel) return;
        if (sel.dataset.filled === '1') return;

        agentsMap = {};
        objectifs.forEach(o => {
            if (o.agent && !agentsMap[o.agent.id]) {
                agentsMap[o.agent.id] = o.agent;
            }
        });

        sel.innerHTML = '<option value="">Tous les agents</option>';
        Object.values(agentsMap).forEach(a => {
            sel.insertAdjacentHTML('beforeend', `<option value="${a.id}">${UI.escapeHtml(a.nom_complet)}</option>`);
        });
        sel.dataset.filled = '1';
    }

    function computeStats() {
        const el = id => document.getElementById(id);
        if (el('stat-total'))     el('stat-total').textContent     = objectifs.length;
        if (el('stat-atteints'))  el('stat-atteints').textContent  = objectifs.filter(o => ['ATTEINT', 'DEPASSE'].includes(o.statut)).length;
        if (el('stat-en-cours'))  el('stat-en-cours').textContent  = objectifs.filter(o => o.statut === 'ACTIF').length;
        if (el('stat-agents'))    el('stat-agents').textContent    = new Set(objectifs.map(o => o.agent_dsa_id)).size;

        computeProgressionGlobale();
    }

    function computeProgressionGlobale() {
        if (!objectifs.length) {
            updateProgression(0);
            return;
        }

        let totalCible = 0;
        let totalRealise = 0;

        objectifs.forEach(o => {
            totalCible += parseFloat(o.valeur_cible) || 0;
            totalRealise += parseFloat(o.valeur_realisee) || 0;
        });

        const pct = totalCible > 0 ? Math.min(100, Math.round((totalRealise / totalCible) * 100)) : 0;
        updateProgression(pct);
    }

    function updateProgression(pct) {
        const bar = document.getElementById('progression-bar');
        const label = document.getElementById('progression-pct');
        if (bar) bar.style.width = pct + '%';
        if (label) label.textContent = pct + '%';
    }

    // ══════════════════════════════════════════════════════════
    // RENDU LISTE (groupée par agent)
    // ══════════════════════════════════════════════════════════
    function renderListe() {
        const container = $('#objectifs-container');

        if (!objectifs.length) {
            container.innerHTML = `
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-10 text-center">
                    <div class="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-inbox text-2xl"></i>
                    </div>
                    <div class="text-gray-600">Aucun objectif attribué à cette campagne.</div>
                    <div class="text-sm text-gray-500 mt-1">
                        <a href="${window.APP_BASE}/campagnes/${campagneId}/objectifs-agents" class="text-emerald-600 underline">Attribuer des objectifs aux agents</a>
                    </div>
                </div>`;
            return;
        }

        const parAgent = {};
        objectifs.forEach(o => {
            if (!parAgent[o.agent_dsa_id]) {
                parAgent[o.agent_dsa_id] = { agent: o.agent, objectifs: [] };
            }
            parAgent[o.agent_dsa_id].objectifs.push(o);
        });

        container.innerHTML = Object.values(parAgent).map(group => {
            const agent = group.agent;
            const initials = (agent?.nom_complet || '?').split(' ').map(x => x[0]).slice(0, 2).join('').toUpperCase();
            const nbAtteints = group.objectifs.filter(o => ['ATTEINT', 'DEPASSE'].includes(o.statut)).length;

            return `
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-4">

                <!-- Header agent -->
                <div class="px-4 py-3 bg-gradient-to-r from-gray-50 to-white border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold text-xs">
                            ${initials}
                        </div>
                        <div>
                            <div class="font-semibold text-gray-900 text-sm">${UI.escapeHtml(agent?.nom_complet || 'Agent inconnu')}</div>
                            <div class="text-[11px] text-gray-500 font-mono">${UI.escapeHtml(agent?.code || '')}</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-indigo-50 text-indigo-700">
                            ${group.objectifs.length} obj.
                        </span>
                        <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-emerald-50 text-emerald-700">
                            ${nbAtteints} atteint${nbAtteints > 1 ? 's' : ''}
                        </span>
                    </div>
                </div>

                <!-- Objectifs (grille compacte) -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 p-3">
                    ${group.objectifs.map(o => renderObjectifCard(o)).join('')}
                </div>
            </div>`;
        }).join('');

        attachRowEvents();
    }

    function renderObjectifCard(o) {
        const pct = o.valeur_cible > 0 ? Math.min(100, Math.round((o.valeur_realisee / o.valeur_cible) * 100)) : 0;
        const barColor = pct >= 100 ? 'bg-emerald-500' : pct >= 50 ? 'bg-amber-500' : 'bg-red-500';

        const badge = {
            PLANIFIE: 'bg-sky-50 text-sky-700 border-sky-200',
            ACTIF:    'bg-blue-50 text-blue-700 border-blue-200',
            ATTEINT:  'bg-emerald-50 text-emerald-700 border-emerald-200',
            DEPASSE:  'bg-purple-50 text-purple-700 border-purple-200',
            ANNULE:   'bg-gray-100 text-gray-600 border-gray-200',
        }[o.statut] || 'bg-gray-100 text-gray-700 border-gray-200';

        const icon = {
            MONTANT: 'fa-money-bill-wave',
            VOLUME:  'fa-cubes',
            CLIENTS: 'fa-users',
            COMPTES: 'fa-id-card',
            VISITES: 'fa-map-marker-alt',
            PRODUIT: 'fa-box',
        }[o.type_objectif] || 'fa-bullseye';

        const produitsLabel = o.produit
            ? `<div class="text-[11px] text-gray-500 mt-0.5 truncate"><i class="fas fa-box mr-1 text-blue-400"></i>${UI.escapeHtml(o.produit.code)} — ${UI.escapeHtml(o.produit.nom)}</div>`
            : '';

        // ⚠️ Bouton "Voir les clients" uniquement pour le type CLIENTS
        const actionClients = o.type_objectif === 'CLIENTS' ? `
            <button type="button" data-show-clients="${o.id}"
                    class="mt-2 w-full px-2 py-1.5 text-[11px] font-semibold text-indigo-700 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition-colors">
                <i class="fas fa-users mr-1"></i> Voir les clients créés (${o.valeur_realisee})
            </button>
        ` : '';

        return `
        <div class="border border-gray-100 rounded-xl p-3 hover:border-emerald-300 hover:shadow-sm transition-all">
            <div class="flex items-start gap-2">
                <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center flex-shrink-0">
                    <i class="fas ${icon} text-xs"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-900 text-xs truncate">${UI.escapeHtml(o.libelle)}</div>
                            ${produitsLabel}
                        </div>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full border ${badge} flex-shrink-0">${o.statut || 'PLANIFIE'}</span>
                    </div>
                </div>
            </div>

            <div class="mt-2 grid grid-cols-3 gap-1.5">
                <div class="text-center bg-gray-50 rounded py-1">
                    <div class="text-[9px] uppercase text-gray-500 font-semibold">Cible</div>
                    <div class="text-[11px] font-bold text-gray-800 truncate">${formatNumber(o.valeur_cible)} ${UI.escapeHtml(o.unite || '')}</div>
                </div>
                <div class="text-center bg-emerald-50 rounded py-1">
                    <div class="text-[9px] uppercase text-emerald-600 font-semibold">Réalisé</div>
                    <input type="number" step="0.01" min="0"
                           data-objectif-realise="${o.id}"
                           value="${o.valeur_realisee}"
                           class="w-full text-center text-[11px] font-bold text-emerald-700 bg-transparent border-0 focus:outline-none focus:bg-white rounded">
                </div>
                <div class="text-center bg-gray-50 rounded py-1">
                    <div class="text-[9px] uppercase text-gray-500 font-semibold">Taux</div>
                    <div class="text-[11px] font-bold text-gray-800">${pct}%</div>
                </div>
            </div>

            <div class="mt-2 bg-gray-100 rounded-full h-1.5 overflow-hidden">
                <div class="h-full ${barColor} transition-all duration-500" style="width:${pct}%"></div>
            </div>

            ${actionClients}
        </div>`;
    }

    // ══════════════════════════════════════════════════════════
    // SAISIE VALEUR RÉALISÉE
    // ══════════════════════════════════════════════════════════
    function attachRowEvents() {
        $$('[data-objectif-realise]').forEach(inp => {
            inp.addEventListener('change', async e => {
                const id = parseInt(e.target.dataset.objectifRealise, 10);
                const valeur = parseFloat(e.target.value);

                if (isNaN(valeur) || valeur < 0) {
                    safeToast('danger', 'Valeur invalide');
                    return;
                }

                const res = await API.post(`/dsa/objectifs/${id}/set-realisee`, {
                    valeur: valeur,
                });

                if (res.ok) {
                    safeToast('success', 'Avancement mis à jour');
                    await loadObjectifs();
                } else {
                    const err = res.data?.errors || res.data?.message;
                    safeToast('danger', typeof err === 'object' ? Object.values(err).flat().join(' · ') : (err || 'Erreur'));
                    await loadObjectifs();
                }
            });
        });

        // ⚠️ Bouton "Voir clients"
        $$('[data-show-clients]').forEach(btn => {
            btn.addEventListener('click', () => openClientsModal(parseInt(btn.dataset.showClients, 10)));
        });
    }

    // ══════════════════════════════════════════════════════════
    // MODAL CLIENTS
    // ══════════════════════════════════════════════════════════
    function initClientsModal() {
        // Créer le modal s'il n'existe pas (une fois)
        if (document.getElementById('clients-modal')) return;

        const modal = document.createElement('div');
        modal.id = 'clients-modal';
        modal.className = 'hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto';
        modal.style.display = 'none';
        modal.innerHTML = `
            <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-2xl w-full my-8 transition-transform scale-95">
                <div class="flex items-center justify-between p-5 border-b border-gray-100">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Clients créés</h3>
                            <p id="clients-modal-subtitle" class="text-xs text-gray-500">—</p>
                        </div>
                    </div>
                    <button type="button" id="clients-modal-close"
                            class="text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg w-9 h-9 flex items-center justify-center transition-colors">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="p-5 max-h-[70vh] overflow-y-auto">
                    <input type="text" id="clients-search" placeholder="Rechercher par nom, code, téléphone, email..."
                           class="w-full px-3 py-2 mb-3 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    <div id="clients-list" class="space-y-2">
                        <div class="p-6 text-center text-gray-400">
                            <i class="fas fa-spinner fa-spin text-xl"></i>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
                    <button type="button" id="clients-modal-footer-close"
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                        Fermer
                    </button>
                </div>
            </div>`;
        document.body.appendChild(modal);

        // Fermeture
        modal.querySelector('#clients-modal-close').addEventListener('click', closeClientsModal);
        modal.querySelector('#clients-modal-footer-close').addEventListener('click', closeClientsModal);
        modal.addEventListener('click', e => {
            if (e.target.id === 'clients-modal') closeClientsModal();
        });

        // Recherche (debounce)
        let searchTimer;
        modal.querySelector('#clients-search').addEventListener('input', e => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                if (currentObjectifIdForClients) {
                    loadClientsPourObjectif(currentObjectifIdForClients, e.target.value.trim());
                }
            }, 350);
        });
    }

    function openClientsModal(objectifId) {
        initClientsModal();
        currentObjectifIdForClients = objectifId;

        const modal = document.getElementById('clients-modal');
        if (!modal) return;

        // Reset recherche
        const searchInput = document.getElementById('clients-search');
        if (searchInput) searchInput.value = '';

        // Afficher modal
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.style.display = 'flex';
        modal.querySelector('.modal-content')?.classList.replace('scale-95', 'scale-100');

        // Charger
        loadClientsPourObjectif(objectifId, '');
    }

    function closeClientsModal() {
        const modal = document.getElementById('clients-modal');
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        modal.style.display = 'none';
        modal.querySelector('.modal-content')?.classList.replace('scale-100', 'scale-95');
    }

    async function loadClientsPourObjectif(objectifId, search) {
        const listEl = document.getElementById('clients-list');
        if (!listEl) return;

        listEl.innerHTML = `<div class="p-6 text-center text-gray-400"><i class="fas fa-spinner fa-spin text-xl"></i></div>`;

        const params = new URLSearchParams();
        if (search) params.append('q', search);
        params.append('per_page', '50');

        try {
            const res = await API.get(`/dsa/objectifs/${objectifId}/clients?${params}`);
            console.log('[OBJ-CLIENTS] →', res.status);

            if (!res.ok) {
                listEl.innerHTML = `<div class="p-6 text-center text-red-500">Erreur ${res.status}</div>`;
                return;
            }

            const data = res.data.data || [];
            const meta = res.data.meta || {};

            const subtitle = document.getElementById('clients-modal-subtitle');
            if (subtitle) {
                const agent = meta.agent?.nom_complet || 'Agent';
                const campagne = meta.campagne?.code || '';
                subtitle.textContent = `${agent} · ${meta.total || 0} client(s) · Campagne ${campagne}`;
            }

            if (!data.length) {
                listEl.innerHTML = `
                    <div class="p-6 text-center text-gray-500">
                        <i class="fas fa-inbox text-2xl mb-2 block"></i>
                        Aucun client trouvé pour cet objectif.
                    </div>`;
                return;
            }

            listEl.innerHTML = data.map(c => {
                const initials = (c.nom_complet || '?').split(' ').map(x => x[0]).slice(0, 2).join('').toUpperCase();
                const kycBadge = {
                    AUCUN:      'bg-gray-100 text-gray-600',
                    EN_ATTENTE: 'bg-amber-50 text-amber-700',
                    EN_COURS:   'bg-blue-50 text-blue-700',
                    VALIDE:     'bg-emerald-50 text-emerald-700',
                    REJETE:     'bg-red-50 text-red-700',
                }[c.kyc_statut] || 'bg-gray-100 text-gray-600';

                return `
                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                    <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold text-sm flex-shrink-0">
                        ${initials}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-sm text-gray-900 truncate">${UI.escapeHtml(c.nom_complet)}</div>
                        <div class="text-xs text-gray-500 truncate">
                            ${UI.escapeHtml(c.code_client || '')}
                            · ${UI.escapeHtml(c.telephone || '')}
                            ${c.email ? '· ' + UI.escapeHtml(c.email) : ''}
                        </div>
                        <div class="text-[10px] text-gray-400 mt-0.5">
                            Créé le ${new Date(c.created_at).toLocaleDateString('fr-FR')}
                        </div>
                    </div>
                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full ${kycBadge}">
                        ${c.kyc_statut || 'AUCUN'}
                    </span>
                </div>`;
            }).join('');
        } catch (e) {
            console.error('[OBJ-CLIENTS] exception:', e);
            listEl.innerHTML = `<div class="p-6 text-center text-red-500">Exception : ${UI.escapeHtml(e.message)}</div>`;
        }
    }

    // ══════════════════════════════════════════════════════════
    // ÉVÉNEMENTS
    // ══════════════════════════════════════════════════════════
    function attachEvents() {
        $('#filter-agent')?.addEventListener('change', e => {
            filtreAgent = e.target.value;
            updateResetButton();
            loadObjectifs();
        });
        $('#filter-statut')?.addEventListener('change', e => {
            filtreStatut = e.target.value;
            updateResetButton();
            loadObjectifs();
        });
        $('#filter-type')?.addEventListener('change', e => {
            filtreType = e.target.value;
            updateResetButton();
            loadObjectifs();
        });
        $('#btn-refresh')?.addEventListener('click', () => loadObjectifs());

        $('#btn-reset-filters')?.addEventListener('click', () => {
            $('#filter-agent').value = '';
            $('#filter-statut').value = '';
            $('#filter-type').value = '';
            filtreAgent = '';
            filtreStatut = '';
            filtreType = '';
            updateResetButton();
            loadObjectifs();
        });
    }

    function updateResetButton() {
        const btn = $('#btn-reset-filters');
        if (!btn) return;
        const hasFilter = filtreAgent || filtreStatut || filtreType;
        btn.classList.toggle('hidden', !hasFilter);
    }

    init();
})();