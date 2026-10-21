(function () {
    'use strict';
    const page = document.getElementById('objectifs-agent-page');
    if (!page) {
        console.warn('[OBJ-AGENT] #objectifs-agent-page introuvable');
        return;
    }

    const campagneId = parseInt(page.dataset.campagneId, 10) || null;
    const agentId    = parseInt(page.dataset.agentId, 10) || null;

    console.log('[OBJ-AGENT] init', { campagneId, agentId });

    let objectifs = [];
    let campagne  = null;  // ← AJOUT : stocke les infos campagne (pour le statut)

    const $  = sel => document.querySelector(sel);

    function safeToast(type, msg) {
        if (window.UI && typeof UI.toast === 'function') UI.toast(type, msg);
        else { console.log(`[${type}]`, msg); if (type === 'danger') alert(msg); }
    }

    function formatNum(n) {
        return Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    // ══════════════════════════════════════════════════════════
    // INIT
    // ══════════════════════════════════════════════════════════
    async function init() {
        if (!API.isAuthenticated()) {
            console.warn('[OBJ-AGENT] Non authentifié → login');
            window.location.href = window.APP_BASE + '/login';
            return;
        }

        try {
            // 1. Charger la campagne d'abord (pour connaître son statut)
            if (campagneId) {
                await loadCampagne();
            }
            // 2. Charger les objectifs
            await loadObjectifs();
        } catch (e) {
            console.error('[OBJ-AGENT] init error:', e);
            renderError('Erreur de chargement');
        }

        attachEvents();
        console.log('[OBJ-AGENT] init terminé');
    }

    // ══════════════════════════════════════════════════════════
    // CHARGEMENT CAMPAGNE
    // ══════════════════════════════════════════════════════════
    async function loadCampagne() {
        if (!campagneId) return;

        try {
            const res = await API.get('/dsa/campagnes/' + campagneId);
            if (res.ok) {
                campagne = res.data.data || res.data;
                console.log('[OBJ-AGENT] campagne chargée:', campagne.code, campagne.statut);

                if ($('#campagne-nom')) {
                    $('#campagne-nom').textContent = `${campagne.code} — ${campagne.nom}`;
                }
            }
        } catch (e) {
            console.warn('[OBJ-AGENT] loadCampagne error:', e);
        }
    }

    // ══════════════════════════════════════════════════════════
    // CHARGEMENT OBJECTIFS
    // ══════════════════════════════════════════════════════════
    async function loadObjectifs() {
        const listEl = $('#objectifs-list');
        if (!listEl) return;

        listEl.innerHTML = `<div class="text-sm text-gray-500 italic text-center p-6">
            <i class="fas fa-spinner fa-spin text-2xl mb-2 block"></i>
            Chargement des objectifs...
        </div>`;

        const params = new URLSearchParams();
        if (campagneId) params.append('campagne_id', campagneId);
        if (agentId)    params.append('agent_dsa_id', agentId);

        try {
            console.log('[OBJ-AGENT] fetch /dsa/objectifs?' + params);

            const res = await API.get('/dsa/objectifs?' + params);
            console.log('[OBJ-AGENT] response →', res.status, res.data);

            if (!res.ok) {
                renderError(`Erreur ${res.status} : ${res.data?.message || res.data?.error || 'Impossible de charger'}`);
                return;
            }

            // Structure paginée : { data: { data: [...] } } ou { data: [...] }
            let items = [];
            if (Array.isArray(res.data)) {
                items = res.data;
            } else if (Array.isArray(res.data?.data)) {
                items = res.data.data;
            } else if (Array.isArray(res.data?.data?.data)) {
                items = res.data.data.data;
            }

            objectifs = items;
            console.log('[OBJ-AGENT] objectifs chargés:', objectifs.length);

            renderListe();
        } catch (e) {
            console.error('[OBJ-AGENT] loadObjectifs exception:', e);
            renderError('Exception : ' + e.message);
        }
    }

    function renderError(msg) {
        const listEl = $('#objectifs-list');
        if (!listEl) return;
        listEl.innerHTML = `
            <div class="bg-white rounded-2xl shadow-sm border border-red-200 p-6 text-center">
                <div class="w-12 h-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-2">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="text-sm text-red-600 font-medium">${UI.escapeHtml(msg)}</div>
            </div>`;
    }

    // ══════════════════════════════════════════════════════════
    // LISTE DES OBJECTIFS
    // ══════════════════════════════════════════════════════════
    function renderListe() {
        const listEl = $('#objectifs-list');
        if (!listEl) return;

        if (!objectifs.length) {
            listEl.innerHTML = `
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-10 text-center">
                    <div class="w-16 h-16 rounded-full bg-indigo-100 text-indigo-500 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-inbox text-2xl"></i>
                    </div>
                    <div class="text-gray-600 font-medium">Aucun objectif assigné</div>
                    <div class="text-sm text-gray-500 mt-1">Contactez votre superviseur pour l'attribution.</div>
                </div>`;
            return;
        }

        listEl.innerHTML = objectifs.map(o => renderObjectifCard(o)).join('');

        attachRowEvents();
    }

    // ══════════════════════════════════════════════════════════
    // RENDU D'UNE CARTE OBJECTIF
    // ══════════════════════════════════════════════════════════
    function renderObjectifCard(o) {
        const pct = o.valeur_cible > 0
            ? Math.min(100, Math.round((o.valeur_realisee / o.valeur_cible) * 100))
            : 0;

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

        const label = o.libelle || o.type_objectif;

        // ⚠️ Vérification du statut campagne
        const campagneStatut = campagne?.statut || 'PLANIFIEE';
        const peutEditer = campagneStatut === 'ACTIVE' && o.statut !== 'ANNULE';

        // Input éditable OU valeur figée
        const inputHtml = peutEditer
            ? `<input type="number" step="0.01" min="0"
                     data-objectif-realise="${o.id}"
                     value="${o.valeur_realisee}"
                     class="w-full text-center text-sm font-bold text-emerald-700 bg-transparent border-0 focus:outline-none focus:bg-white focus:ring-2 focus:ring-emerald-300 rounded">`
            : `<div class="text-sm font-bold ${o.statut === 'ANNULE' ? 'text-gray-400 line-through' : 'text-gray-500'}">${o.valeur_realisee}</div>`;

        // Bandeau "campagne non active"
        // Message contextuel selon statut
            let warningMsg = '';
            let warningClass = '';

            if (o.statut === 'ANNULE') {
                warningMsg = 'Objectif annulé — définitivement figé';
                warningClass = 'bg-gray-100 border-gray-200 text-gray-600';
            } else if (campagneStatut === 'PLANIFIEE') {
                warningMsg = 'Campagne en planification — objectif non encore actif';
                warningClass = 'bg-sky-50 border-sky-200 text-sky-700';
            } else if (campagneStatut === 'SUSPENDUE') {
                warningMsg = 'Campagne suspendue — modification bloquée';
                warningClass = 'bg-amber-50 border-amber-200 text-amber-700';
            } else if (campagneStatut === 'TERMINEE') {
                warningMsg = 'Campagne terminée — objectif figé';
                warningClass = 'bg-blue-50 border-blue-200 text-blue-700';
            }

            const warningHtml = warningMsg
                ? `<div class="mt-2 flex items-center gap-1.5 px-2 py-1 ${warningClass} border rounded-lg text-[10px] font-semibold">
                       <i class="fas fa-lock text-[9px]"></i>
                       <span>${warningMsg}</span>
                   </div>`
                : '';

        // Bandeau "objectif annulé"
        const warningAnnule = o.statut === 'ANNULE'
            ? `<div class="mt-2 flex items-center gap-1.5 px-2 py-1 bg-gray-100 border border-gray-200 rounded-lg text-[10px] text-gray-600 font-semibold">
                   <i class="fas fa-ban text-[9px]"></i>
                   <span>Objectif annulé — définitivement figé</span>
               </div>`
            : '';

        return `
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 hover:border-indigo-200 transition-colors ${!peutEditer ? 'opacity-90' : ''}">
            <div class="flex items-start justify-between gap-3 mb-3">
                <div class="flex items-start gap-3 flex-1 min-w-0">
                    <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center flex-shrink-0">
                        <i class="fas ${icon}"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-gray-900 text-sm">${UI.escapeHtml(label)}</div>
                        ${o.campagne ? `<div class="text-xs text-gray-500 mt-0.5">${UI.escapeHtml(o.campagne.code)} — ${UI.escapeHtml(o.campagne.nom)}</div>` : ''}
                        ${o.produit ? `<div class="text-xs text-blue-600 mt-0.5"><i class="fas fa-box mr-1"></i>${UI.escapeHtml(o.produit.code)} — ${UI.escapeHtml(o.produit.nom)}</div>` : ''}
                    </div>
                </div>
                <span class="px-2 py-1 text-xs font-semibold rounded-full border ${badge} flex-shrink-0">${o.statut}</span>
            </div>

            <div class="grid grid-cols-3 gap-2 mb-3">
                <div class="text-center bg-gray-50 rounded-lg p-2">
                    <div class="text-[10px] uppercase text-gray-500 font-semibold">Cible</div>
                    <div class="text-sm font-bold text-gray-800 truncate">${formatNum(o.valeur_cible)} ${UI.escapeHtml(o.unite || '')}</div>
                </div>
                <div class="text-center bg-emerald-50 rounded-lg p-2">
                    <div class="text-[10px] uppercase text-emerald-600 font-semibold">Réalisé</div>
                    ${inputHtml}
                </div>
                <div class="text-center bg-gray-50 rounded-lg p-2">
                    <div class="text-[10px] uppercase text-gray-500 font-semibold">Taux</div>
                    <div class="text-sm font-bold text-gray-800">${pct}%</div>
                </div>
            </div>

            <div class="bg-gray-100 rounded-full h-2 overflow-hidden">
                <div class="h-full ${barColor} transition-all duration-500" style="width:${pct}%"></div>
            </div>

            ${warningHtml}
        </div>`;
    }

    // ══════════════════════════════════════════════════════════
    // SAISIE VALEUR RÉALISÉE
    // ══════════════════════════════════════════════════════════
    function attachRowEvents() {
        document.querySelectorAll('[data-objectif-realise]').forEach(inp => {
            inp.addEventListener('change', async e => {
                const id = parseInt(e.target.dataset.objectifRealise, 10);
                const valeur = parseFloat(e.target.value);

                if (isNaN(valeur) || valeur < 0) {
                    safeToast('danger', 'Valeur invalide');
                    e.target.value = e.target.defaultValue;
                    return;
                }

                console.log('[OBJ-AGENT] update', id, valeur);

                try {
                    const res = await API.post(`/dsa/objectifs/${id}/set-realisee`, { valeur });
                    console.log('[OBJ-AGENT] update response →', res.status);

                    if (res.ok) {
                        safeToast('success', 'Avancement enregistré');
                        await loadObjectifs();
                    } else {
                        const err = res.data?.errors || res.data?.message || res.data?.error;
                        safeToast('danger', typeof err === 'object' ? Object.values(err).flat().join(' · ') : (err || 'Erreur'));
                        // Restaurer la valeur précédente en cas d'erreur
                        await loadObjectifs();
                    }
                } catch (err) {
                    console.error('[OBJ-AGENT] update error:', err);
                    safeToast('danger', 'Erreur réseau');
                    await loadObjectifs();
                }
            });
        });
    }

    function attachEvents() {
        console.log('[OBJ-AGENT] attachEvents');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();