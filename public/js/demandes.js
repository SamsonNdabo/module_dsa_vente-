(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    let state = { page: 1, type: '', statut: '', agent_dsa_id: '' };
    let currentDemande = null;
    let agents = [];

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    const WORKFLOW = {
        KYC: ['BROUILLON', 'SOUMIS', 'VALIDE'],
        OUVERTURE_COMPTE: ['PROSPECT', 'DOSSIER_SOUMIS', 'KYC_VALIDE', 'COMPTE_OUVERT', 'ACTIF'],
    };

    const TRANSITIONS_LABELS = {
        SOUMIS:         { color: 'bg-blue-600 hover:bg-blue-700',    icon: 'paper-plane', label: 'Soumettre' },
        VALIDE:         { color: 'bg-emerald-600 hover:bg-emerald-700', icon: 'check', label: 'Valider' },
        A_CORRIGER:     { color: 'bg-amber-600 hover:bg-amber-700',  icon: 'edit', label: 'À corriger' },
        REJETE:         { color: 'bg-red-600 hover:bg-red-700',      icon: 'ban', label: 'Rejeter' },
        DOSSIER_SOUMIS: { color: 'bg-indigo-600 hover:bg-indigo-700', icon: 'file-export', label: 'Soumettre le dossier' },
        COMPTE_OUVERT:  { color: 'bg-emerald-600 hover:bg-emerald-700', icon: 'unlock', label: 'Ouvrir le compte' },
        ACTIF:          { color: 'bg-emerald-700 hover:bg-emerald-800', icon: 'check-circle', label: 'Activer' },
        KYC_VALIDE:     { color: 'bg-purple-600 hover:bg-purple-700', icon: 'shield-check', label: 'KYC validé' },
    };

    function safeToast(type, msg) {
        if (window.UI && typeof UI.toast === 'function') UI.toast(type, msg);
        else { console.log(`[${type}]`, msg); if (type === 'danger') alert(msg); }
    }

    function openModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('hidden'); el.classList.add('flex'); el.style.display = 'flex';
        el.querySelector('.modal-content')?.classList.replace('scale-95', 'scale-100');
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.add('hidden'); el.classList.remove('flex'); el.style.display = 'none';
        el.querySelector('.modal-content')?.classList.replace('scale-100', 'scale-95');
    }

    async function init() {
        if (!API.isAuthenticated()) { window.location.href = window.APP_BASE + '/login'; return; }
        await Promise.allSettled([loadStatuts(), loadAgents()]);
        await loadDemandes();
        attachEvents();
    }

    async function loadStatuts() {
        try {
            const res = await API.get('/dsa/demandes/options');
            if (res.ok && res.data?.data?.statuts) {
                const sel = $('#filter-statut');
                res.data.data.statuts.forEach(s => {
                    if (sel) sel.insertAdjacentHTML('beforeend', `<option value="${s}">${s}</option>`);
                });
            }
        } catch (e) { console.error(e); }
    }

    async function loadAgents() {
        try {
            const res = await API.get('/dsa/agents/options');
            if (res.ok && res.data?.data) {
                agents = res.data.data;
                const sel = $('#filter-agent');
                agents.forEach(a => {
                    if (sel) sel.insertAdjacentHTML('beforeend', `<option value="${a.id}">${UI.escapeHtml(a.nom_complet)}</option>`);
                });
            }
        } catch (e) { console.error(e); }
    }

    async function loadDemandes(page = 1) {
        state.page = page;
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-gray-400"><i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page, per_page: 15,
            ...(state.type      && { type: state.type }),
            ...(state.statut    && { statut: state.statut }),
            ...(state.agent_dsa_id && { agent_dsa_id: state.agent_dsa_id }),
        });

        try {
            const res = await API.get('/dsa/demandes?' + params);
            console.log('[DEMANDES] list →', res.status);
            if (!res.ok) {
                tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-red-500">Erreur ${res.status}</td></tr>`;
                return;
            }
            const pag = res.data.data || res.data;
            const items = pag.data || [];
            renderTable(items);
            computeStats(items);
            if (UI.renderPagination) UI.renderPagination($('#pagination'), pag, p => loadDemandes(p));
        } catch (e) {
            console.error(e);
            tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-red-500">Exception : ${UI.escapeHtml(e.message)}</td></tr>`;
        }
    }

    function computeStats(items) {
        $('#stat-total').textContent    = items.length;
        $('#stat-en-cours').textContent = items.filter(d => !['VALIDE', 'REJETE', 'ACTIF'].includes(d.statut)).length;
        $('#stat-validees').textContent = items.filter(d => ['VALIDE', 'ACTIF'].includes(d.statut)).length;
        $('#stat-rejetees').textContent = items.filter(d => d.statut === 'REJETE').length;
    }

    function renderTable(items) {
        const tbody = $('#table-body');
        if (!items.length) {
            tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-gray-400"><i class="fas fa-inbox text-3xl mb-2 block"></i>Aucune demande</td></tr>`;
            return;
        }

        tbody.innerHTML = items.map(d => {
            const badge = {
                BROUILLON:      'bg-gray-100 text-gray-700 border-gray-200',
                SOUMIS:         'bg-blue-50 text-blue-700 border-blue-200',
                A_CORRIGER:     'bg-amber-50 text-amber-700 border-amber-200',
                VALIDE:         'bg-emerald-50 text-emerald-700 border-emerald-200',
                REJETE:         'bg-red-50 text-red-700 border-red-200',
                PROSPECT:       'bg-gray-100 text-gray-700 border-gray-200',
                DOSSIER_SOUMIS: 'bg-indigo-50 text-indigo-700 border-indigo-200',
                KYC_VALIDE:     'bg-purple-50 text-purple-700 border-purple-200',
                COMPTE_OUVERT:  'bg-emerald-50 text-emerald-700 border-emerald-200',
                ACTIF:          'bg-emerald-100 text-emerald-800 border-emerald-300',
            }[d.statut] || 'bg-gray-100 text-gray-700 border-gray-200';

            const typeLabel = d.type === 'KYC' ? 'KYC' : 'Ouverture compte';
            const typeBadge = d.type === 'KYC'
                ? 'bg-indigo-50 text-indigo-700 border-indigo-200'
                : 'bg-blue-50 text-blue-700 border-blue-200';

            return `
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(d.reference || '')}</td>
                <td class="px-4 py-3"><span class="px-2 py-1 text-xs font-semibold rounded-full border ${typeBadge}">${typeLabel}</span></td>
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-900 text-sm">${d.client ? UI.escapeHtml(d.client.nom_complet) : '—'}</div>
                    <div class="text-xs text-gray-500">${d.client ? UI.escapeHtml(d.client.code_client || '') : ''}</div>
                </td>
                <td class="px-4 py-3 text-xs text-gray-600">${d.agent ? UI.escapeHtml(d.agent.prenom + ' ' + d.agent.nom) : '—'}</td>
                <td class="px-4 py-3 text-xs text-gray-600">${d.partenaire ? UI.escapeHtml(d.partenaire.nom) : '—'}</td>
                <td class="px-4 py-3"><span class="px-2 py-1 text-xs font-semibold rounded-full border ${badge}">${UI.escapeHtml(d.statut || '')}</span></td>
                <td class="px-4 py-3 text-xs text-gray-500">${new Date(d.created_at).toLocaleDateString('fr-FR')}</td>
                <td class="px-4 py-3 text-right">
                    <button data-action="view" data-id="${d.id}"
                            class="inline-flex items-center gap-1 px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100 transition-colors">
                        <i class="fas fa-eye"></i> Voir
                    </button>
                </td>
            </tr>`;
        }).join('');
    }

    async function openDetail(id) {
        try {
            const [rDemande, rHist] = await Promise.all([
                API.get('/dsa/demandes/' + id),
                API.get('/dsa/demandes/' + id + '/historiques'),
            ]);
            if (!rDemande.ok) { safeToast('danger', 'Demande introuvable'); return; }

            currentDemande = rDemande.data.data || rDemande.data;

            $('#modal-title').textContent = 'Demande';
            $('#modal-subtitle').textContent = currentDemande.reference;
            $('#d-type').textContent = currentDemande.type === 'KYC' ? 'KYC' : 'Ouverture de compte';
            $('#d-statut').innerHTML = `<span class="px-2 py-1 rounded-full text-xs ${{
                BROUILLON: 'bg-gray-100 text-gray-700', SOUMIS: 'bg-blue-50 text-blue-700',
                A_CORRIGER: 'bg-amber-50 text-amber-700', VALIDE: 'bg-emerald-50 text-emerald-700',
                REJETE: 'bg-red-50 text-red-700', PROSPECT: 'bg-gray-100 text-gray-700',
                DOSSIER_SOUMIS: 'bg-indigo-50 text-indigo-700', KYC_VALIDE: 'bg-purple-50 text-purple-700',
                COMPTE_OUVERT: 'bg-emerald-50 text-emerald-700', ACTIF: 'bg-emerald-100 text-emerald-800',
            }[currentDemande.statut] || 'bg-gray-100 text-gray-700'}">${currentDemande.statut}</span>`;
            $('#d-client').textContent = currentDemande.client?.nom_complet || '—';
            $('#d-partenaire').textContent = currentDemande.partenaire?.nom || '—';
            $('#d-agent').textContent = currentDemande.agent
                ? currentDemande.agent.prenom + ' ' + currentDemande.agent.nom : '—';
            $('#d-date').textContent = new Date(currentDemande.created_at).toLocaleString('fr-FR');

            renderWorkflowVisuel();
            renderActions();
            renderHistorique(rHist.ok ? (rHist.data.data || []) : []);

            openModal('demande-modal');
        } catch (e) {
            console.error('[DEMANDES] openDetail:', e);
            safeToast('danger', 'Erreur de chargement');
        }
    }

    function renderWorkflowVisuel() {
        const steps = WORKFLOW[currentDemande.type] || [];
        const currentIdx = steps.indexOf(currentDemande.statut);

        $('#workflow-steps').innerHTML = steps.map((step, i) => {
            const done   = i < currentIdx;
            const active = i === currentIdx;
            const cls = done
                ? 'bg-emerald-500 text-white border-emerald-500'
                : active
                    ? 'bg-indigo-600 text-white border-indigo-600 ring-2 ring-indigo-200'
                    : 'bg-white text-gray-400 border-gray-300';

            return `
            <div class="flex items-center gap-2">
                <div class="px-3 py-1.5 text-xs font-semibold rounded-full border ${cls}">${step}</div>
                ${i < steps.length - 1 ? `<i class="fas fa-arrow-right text-gray-300 text-xs"></i>` : ''}
            </div>`;
        }).join('');
    }

    function renderActions() {
        const el = $('#actions-dispo');
        const type = currentDemande.type;
        const statut = currentDemande.statut;

        const transitions = type === 'KYC'
            ? {
                BROUILLON:  ['SOUMIS'],
                SOUMIS:     ['VALIDE', 'REJETE', 'A_CORRIGER'],
                A_CORRIGER: ['SOUMIS'],
            }[statut] || []
            : {
                PROSPECT:       ['DOSSIER_SOUMIS', 'REJETE'],
                DOSSIER_SOUMIS: ['REJETE'],
                KYC_VALIDE:     ['COMPTE_OUVERT', 'REJETE'],
                COMPTE_OUVERT:  ['ACTIF', 'REJETE'],
            }[statut] || [];

        if (!transitions.length) {
            el.innerHTML = '<div class="text-sm text-gray-500 italic p-2">Aucune action disponible.</div>';
            return;
        }

        el.innerHTML = transitions.map(t => {
            const m = TRANSITIONS_LABELS[t] || { color: 'bg-gray-600 hover:bg-gray-700', icon: 'circle', label: t };
            return `<button data-transition="${t}"
                        class="px-4 py-2 text-sm font-semibold text-white ${m.color} rounded-lg transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-${m.icon}"></i> ${m.label}
                    </button>`;
        }).join('');

        el.querySelectorAll('[data-transition]').forEach(b => {
            b.addEventListener('click', () => transitionner(b.dataset.transition));
        });
    }

    function renderHistorique(hist) {
        const el = $('#d-historique');
        if (!hist.length) {
            el.innerHTML = '<div class="text-xs text-gray-500 italic p-2">Aucun historique</div>';
            return;
        }

        el.innerHTML = hist.map(h => `
            <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg border border-gray-100">
                <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-circle text-[6px]"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-900">
                        <span class="text-gray-500">${UI.escapeHtml(h.ancien_statut || '—')}</span>
                        <i class="fas fa-arrow-right text-xs text-gray-400 mx-1"></i>
                        <span class="text-indigo-600">${UI.escapeHtml(h.nouveau_statut)}</span>
                    </div>
                    <div class="text-xs text-gray-500 mt-0.5">
                        ${h.acteur ? UI.escapeHtml(h.acteur.nom_complet) : 'Système'} · ${new Date(h.created_at).toLocaleString('fr-FR')}
                    </div>
                    ${h.motif ? `<div class="text-xs text-gray-600 italic mt-1 p-2 bg-white rounded border border-gray-200">« ${UI.escapeHtml(h.motif)} »</div>` : ''}
                </div>
            </div>
        `).join('');
    }

    async function transitionner(cible) {
        if (!currentDemande) return;

        const motif = ['REJETE', 'A_CORRIGER'].includes(cible)
            ? prompt('Motif (obligatoire) :')
            : null;
        if (['REJETE', 'A_CORRIGER'].includes(cible) && !motif) return;

        const body = { statut: cible, motif };
        if (cible === 'COMPTE_OUVERT') {
            const numero = prompt('Numéro de compte (obligatoire) :');
            if (!numero) return;
            body.numero_compte = numero;
            const ref = prompt('Référence partenaire (optionnel) :');
            if (ref) body.reference_partenaire = ref;
        }

        try {
            const res = await API.post('/dsa/demandes/' + currentDemande.id + '/transition', body);
            console.log('[DEMANDES] transition →', res.status);
            if (res.ok) {
                safeToast('success', `Statut → ${cible}`);
                closeModal('demande-modal');
                loadDemandes(state.page);
            } else {
                const err = res.data?.errors || res.data?.message;
                safeToast('danger', typeof err === 'object' ? Object.values(err).flat().join(' · ') : (err || 'Erreur'));
            }
        } catch (e) {
            console.error(e);
            safeToast('danger', 'Erreur réseau');
        }
    }

    function attachEvents() {
        $('#filter-type')?.addEventListener('change', e => { state.type = e.target.value; loadDemandes(1); });
        $('#filter-statut')?.addEventListener('change', e => { state.statut = e.target.value; loadDemandes(1); });
        $('#filter-agent')?.addEventListener('change', e => { state.agent_dsa_id = e.target.value; loadDemandes(1); });
        $('#btn-refresh')?.addEventListener('click', () => loadDemandes(state.page));

        document.addEventListener('click', e => {
            const b = e.target.closest('[data-action]');
            if (b && b.dataset.action === 'view') openDetail(b.dataset.id);
        });

        $$('[data-close-modal]').forEach(b => {
            b.addEventListener('click', () => {
                const id = b.dataset.closeModal;
                if (id) closeModal(id);
            });
        });

        ['#demande-modal'].forEach(sel => {
            const el = $(sel);
            if (el) el.addEventListener('click', e => {
                if (e.target.id === el.id) closeModal(el.id);
            });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();