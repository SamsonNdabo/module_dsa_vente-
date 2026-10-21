(function () {
    'use strict';
    const page = document.getElementById('campagne-objectifs-agents-page');
    if (!page) return;

    const campagneId = parseInt(page.dataset.campagneId, 10);
    if (!campagneId) {
        console.error('[CAMPAGNES-OBJ-AGENTS] campagneId manquant');
        return;
    }

    let data = null;
    let attributions = {}; // clé = `${campagne_objectif_id}_${agent_dsa_id}` → { id?, valeur_cible }

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    function safeToast(type, msg) {
        if (window.UI && typeof UI.toast === 'function') UI.toast(type, msg);
        else { console.log(`[${type}]`, msg); if (type === 'danger') alert(msg); }
    }

    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await loadMatrice();
        attachEvents();
    }

    async function loadMatrice() {
        const res = await API.get(`/dsa/campagnes/${campagneId}/objectifs-agents`);
        console.log('[CAMPAGNES-OBJ-AGENTS] matrice →', res.status);

        if (!res.ok) {
            $('#matrice-container').innerHTML = `<div class="p-10 text-center text-red-500">Erreur ${res.status}</div>`;
            return;
        }

        data = res.data.data;

        // Header
        $('#c-nom').textContent = `${data.campagne.code} — ${data.campagne.nom}`;
        const metaParts = [];
        if (data.campagne.partenaire) metaParts.push(data.campagne.partenaire.nom);
        if (data.campagne.budget) metaParts.push('Budget : ' + Number(data.campagne.budget).toLocaleString('fr-FR') + ' ' + (data.campagne.devise || ''));
        if (data.campagne.statut) metaParts.push(data.campagne.statut);
        $('#c-meta').innerHTML = metaParts.map(p => `<span class="flex items-center gap-1"><i class="fas fa-circle text-[6px]"></i>${UI.escapeHtml(p)}</span>`).join('');

        // Construire l'index des attributions existantes
        attributions = {};
        (data.attributions || []).forEach(a => {
            attributions[`${a.campagne_objectif_id}_${a.agent_dsa_id}`] = {
                id: a.id,
                valeur_cible: a.valeur_cible,
            };
        });

        renderMatrice();
        updateStats();
        renderRecapitulatif();
    }

    function renderRecapitulatif() {
        const section = document.getElementById('recap-section');
        const container = document.getElementById('recap-container');
        if (!section || !container) return;

        const attributionsValides = Object.entries(attributions).filter(([k, v]) => v.valeur_cible > 0);

        if (!attributionsValides.length) {
            section.classList.add('hidden');
            return;
        }

        section.classList.remove('hidden');

        // Grouper par agent
        const parAgent = {};
        attributionsValides.forEach(([key, attr]) => {
            const [objId, agentId] = key.split('_').map(x => parseInt(x, 10));
            const objectif = data.objectifs.find(o => o.id === objId);
            const agent = data.agents.find(a => a.id === agentId);
            if (!agent || !objectif) return;

            if (!parAgent[agentId]) {
                parAgent[agentId] = { agent, objectifs: [] };
            }
            parAgent[agentId].objectifs.push({
                libelle: objectif.libelle,
                valeur_cible: attr.valeur_cible,
                unite: objectif.unite,
                type: objectif.type_objectif,
            });
        });

        container.innerHTML = Object.values(parAgent).map(({ agent, objectifs }) => {
            const initials = (agent.nom_complet || '?').split(' ').map(x => x[0]).slice(0, 2).join('').toUpperCase();

            return `
            <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-xl mb-2 border border-gray-100">
                <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold text-xs flex-shrink-0">
                    ${initials}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-sm text-gray-900">${UI.escapeHtml(agent.nom_complet)}</div>
                    <div class="flex flex-wrap gap-1.5 mt-1.5">
                        ${objectifs.map(o => `
                            <span class="inline-flex items-center gap-1 px-2 py-1 text-xs bg-white rounded-lg border border-gray-200">
                                <i class="fas ${getIconForType(o.type)} text-emerald-500 text-[10px]"></i>
                                <span class="text-gray-700">${UI.escapeHtml(o.libelle)}</span>
                                <span class="text-gray-400">:</span>
                                <strong class="text-indigo-600">${formatNum(o.valeur_cible)}</strong>
                                <span class="text-gray-500">${UI.escapeHtml(o.unite || '')}</span>
                            </span>
                        `).join('')}
                    </div>
                </div>
            </div>`;
        }).join('');
    }

    function renderMatrice() {
        const container = $('#matrice-container');

        if (!data.objectifs.length) {
            container.innerHTML = `<div class="p-10 text-center text-gray-500">
                Aucun objectif défini dans cette campagne.<br>
                <a href="${window.APP_BASE}/campagnes" class="text-indigo-600 underline mt-2 inline-block">Retour aux campagnes</a>
            </div>`;
            return;
        }

        if (!data.agents.length) {
            container.innerHTML = `<div class="p-10 text-center text-gray-500">
                Aucun agent affecté à cette campagne.<br>
                <a href="${window.APP_BASE}/campagnes" class="text-indigo-600 underline mt-2 inline-block">Retour aux campagnes</a>
            </div>`;
            return;
        }

        // Construction du tableau matriciel
        const table = `
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10">
                <tr>
                    <th class="px-4 py-3 text-left text-xs uppercase tracking-wider text-gray-600 font-bold sticky left-0 bg-gray-50 min-w-[220px]">
                        Objectif
                    </th>
                    ${data.agents.map(a => `
                        <th class="px-3 py-3 text-center text-xs font-semibold text-gray-700 min-w-[120px]">
                            <div class="flex flex-col items-center gap-1">
                                <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold text-xs">
                                    ${(a.nom_complet || '?').split(' ').map(x => x[0]).slice(0, 2).join('').toUpperCase()}
                                </div>
                                <div class="text-[10px]">${UI.escapeHtml(a.nom_complet)}</div>
                            </div>
                        </th>
                    `).join('')}
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                ${data.objectifs.map(o => {
                    const pct = o.valeur_cible > 0 ? Math.min(100, Math.round((o.valeur_realisee / o.valeur_cible) * 100)) : 0;
                    return `
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 sticky left-0 bg-white border-r border-gray-100">
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center flex-shrink-0">
                                    <i class="fas ${getIconForType(o.type_objectif)} text-xs"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="font-semibold text-gray-900 text-sm truncate">${UI.escapeHtml(o.libelle)}</div>
                                    <div class="text-xs text-gray-500">
                                        Cible : <strong>${formatNum(o.valeur_cible)}</strong> ${UI.escapeHtml(o.unite || '')}
                                    </div>
                                    <div class="mt-1 flex items-center gap-1">
                                        <div class="flex-1 bg-gray-100 rounded-full h-1.5 overflow-hidden">
                                            <div class="h-full ${pct >= 100 ? 'bg-emerald-500' : pct >= 50 ? 'bg-amber-500' : 'bg-red-500'}" style="width:${pct}%"></div>
                                        </div>
                                        <span class="text-[10px] text-gray-500">${pct}%</span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        ${data.agents.map(a => renderAgentCell(o, a)).join('')}
                    </tr>`;
                }).join('')}
            </tbody>
        </table>`;

        container.innerHTML = table;
        attachCellEvents();
    }

    function renderAgentCell(objectif, agent) {
        const key = `${objectif.id}_${agent.id}`;
        const attr = attributions[key];
        const isAssigned = !!attr;

        return `
        <td class="px-3 py-3 text-center align-top">
            <div class="flex flex-col items-center gap-2">
                <input type="checkbox" data-key="${key}" data-objectif="${objectif.id}" data-agent="${agent.id}"
                       ${isAssigned ? 'checked' : ''}
                       class="w-5 h-5 rounded accent-indigo-600 cell-checkbox cursor-pointer">
                <input type="number" step="0.01" min="0.01"
                       data-key="${key}"
                       value="${attr ? attr.valeur_cible : ''}"
                       placeholder="Cible"
                       class="w-full max-w-[100px] px-2 py-1 text-xs text-center border border-gray-200 rounded-lg cell-valeur ${isAssigned ? '' : 'hidden'}"
                       ${isAssigned ? '' : 'disabled'}>
            </div>
        </td>`;
    }

    function getIconForType(type) {
        return ({
            MONTANT: 'fa-money-bill-wave',
            VOLUME:  'fa-cubes',
            CLIENTS: 'fa-users',
            COMPTES: 'fa-id-card',
            VISITES: 'fa-map-marker-alt',
            PRODUIT: 'fa-box',
        })[type] || 'fa-bullseye';
    }

    function formatNum(n) {
        return Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    function attachCellEvents() {
        $$('.cell-checkbox').forEach(cb => {
            cb.addEventListener('change', e => {
                const key = e.target.dataset.key;
                const input = document.querySelector(`.cell-valeur[data-key="${key}"]`);

                if (e.target.checked) {
                    input.classList.remove('hidden');
                    input.disabled = false;
                    input.focus();
                    if (!attributions[key]) {
                        attributions[key] = { id: null, valeur_cible: 0 };
                    }
                } else {
                    input.classList.add('hidden');
                    input.disabled = true;
                    input.value = '';
                    delete attributions[key];
                }
                updateStats();
            });
        });

        $$('.cell-valeur').forEach(inp => {
            inp.addEventListener('input', e => {
                const key = e.target.dataset.key;
                if (attributions[key]) {
                    attributions[key].valeur_cible = parseFloat(e.target.value) || 0;
                }
            });
        });
    }

    function updateStats() {
        const total = data.objectifs.length * data.agents.length;
        const assigned = Object.values(attributions).filter(a => a.valeur_cible > 0).length;

        $('#stat-objectifs').textContent     = data.objectifs.length;
        $('#stat-agents').textContent        = data.agents.length;
        $('#stat-attributions').textContent  = assigned;
        $('#stat-a-assigner').textContent    = total - assigned;

        renderRecapitulatif();
    }

    async function saveAll() {
        // Construire le payload : uniquement les attributions avec valeur > 0
        const payload = [];
        Object.entries(attributions).forEach(([key, attr]) => {
            if (attr.valeur_cible > 0) {
                const [objectifId, agentId] = key.split('_').map(x => parseInt(x, 10));
                payload.push({
                    campagne_objectif_id: objectifId,
                    agent_dsa_id: agentId,
                    valeur_cible: attr.valeur_cible,
                });
            }
        });

        if (!payload.length) {
            safeToast('danger', 'Aucune attribution à enregistrer');
            return;
        }

        const btn = $('#btn-save-all');
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Enregistrement...';

        try {
            const res = await API.post(`/dsa/campagnes/${campagneId}/attribuer-objectifs`, { attributions: payload });
            console.log('[CAMPAGNES-OBJ-AGENTS] save →', res.status);

            if (res.ok) {
                safeToast('success', `${payload.length} attribution(s) enregistrée(s)`);
                await loadMatrice();
            } else {
                const err = res.data?.errors || res.data?.message;
                safeToast('danger', typeof err === 'object' ? Object.values(err).flat().join(' · ') : (err || 'Erreur'));
            }
        } catch (e) {
            console.error(e);
            safeToast('danger', 'Erreur réseau');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }

    function attachEvents() {
        $('#btn-save-all')?.addEventListener('click', saveAll);
    }

    init();
})();