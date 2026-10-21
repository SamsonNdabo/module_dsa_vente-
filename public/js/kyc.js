(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    function safeToast(type, msg) {
        if (window.UI && typeof UI.toast === 'function') {
            UI.toast(type, msg);
        } else {
            console.log(`[${type}]`, msg);
            if (type === 'danger') alert(msg);
        }
    }

    function safeOpenModal(id) {
        const el = document.getElementById(id);
        if (!el) { console.error('[KYC] modal introuvable:', id); return; }
        el.classList.remove('hidden');
        el.classList.add('flex');
        el.style.display = 'flex';   // ← FORCE l'affichage
        const content = el.querySelector('.modal-content');
        if (content) {
            content.classList.remove('scale-95');
            content.classList.add('scale-100');
        }
    }

    function safeCloseModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.add('hidden');
        el.classList.remove('flex');
        el.style.display = 'none';   // ← FORCE le masquage
        const content = el.querySelector('.modal-content');
        if (content) {
            content.classList.remove('scale-100');
            content.classList.add('scale-95');
        }
    }

    function safeSetLoading(btn, state) {
        if (!btn) return;
        if (window.UI && typeof UI.setLoading === 'function') {
            UI.setLoading(btn, state);
        } else {
            btn.disabled = state;
            const spinner = btn.querySelector('.spinner');
            if (spinner) spinner.classList.toggle('hidden', !state);
        }
    }

    let state = { page: 1, q: '', statut: '' };

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await loadDossiers();
        attachEvents();
    }

    async function loadDossiers(page = 1) {
        state.page = page;
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400"><i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page, per_page: 15, type: 'KYC',
            ...(state.q && { q: state.q }),
            ...(state.statut && { statut: state.statut }),
        });

        try {
            const res = await API.get('/dsa/demandes?' + params);
            console.log('[KYC] list →', res.status);

            if (!res.ok) {
                const msg = res.data?.message || res.data?.error || `HTTP ${res.status}`;
                tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-red-500">Erreur : ${UI.escapeHtml(msg)}</td></tr>`;
                return;
            }

            const pag   = res.data.data || res.data;
            const items = pag.data || [];

            renderTable(items);
            computeStats(items);

            if (UI.renderPagination && pag.current_page !== undefined) {
                UI.renderPagination($('#pagination'), pag, p => loadDossiers(p));
            }
        } catch (e) {
            console.error('[KYC] exception:', e);
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-red-500">Exception : ${UI.escapeHtml(e.message)}</td></tr>`;
        }
    }

    function computeStats(items) {
        const el = id => document.getElementById(id);
        if (el('stat-total'))    el('stat-total').textContent    = items.length;
        if (el('stat-en-cours')) el('stat-en-cours').textContent = items.filter(d => ['BROUILLON','SOUMIS','A_CORRIGER'].includes(d.statut)).length;
        if (el('stat-valides'))  el('stat-valides').textContent  = items.filter(d => d.statut === 'VALIDE').length;
        if (el('stat-rejetes'))  el('stat-rejetes').textContent  = items.filter(d => d.statut === 'REJETE').length;
    }

        function renderTable(items) {
        const tbody = $('#table-body');
        if (!items.length) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucun dossier KYC</td></tr>`;
            return;
        }

        tbody.innerHTML = items.map(d => {
            const pieces = d.kyc_dossier?.pieces || [];
            const nbTotal      = pieces.length;
            const nbValidees   = pieces.filter(p => p.statut === 'VALIDE').length;
            const nbEnAttente  = pieces.filter(p => p.statut === 'EN_ATTENTE').length;
            const nbRejetees   = pieces.filter(p => p.statut === 'REJETE').length;
            const hasSignature = !!d.kyc_dossier?.signature_ref;

            const statutBadge = {
                BROUILLON:  'bg-gray-100 text-gray-700',
                SOUMIS:     'bg-blue-50 text-blue-700',
                A_CORRIGER: 'bg-amber-50 text-amber-700',
                VALIDE:     'bg-emerald-50 text-emerald-700',
                REJETE:     'bg-red-50 text-red-700',
            }[d.statut] || 'bg-gray-100 text-gray-600';

            // Bloc "pièces" avec détail
            let piecesCell;
            if (nbTotal === 0) {
                piecesCell = `<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-500">0 pièce</span>`;
            } else {
                piecesCell = `
                    <div class="flex flex-col gap-1">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-indigo-50 text-indigo-700 inline-block w-fit">
                            ${nbTotal} pièce${nbTotal > 1 ? 's' : ''}
                        </span>
                        <div class="flex flex-wrap gap-1">
                            ${nbValidees > 0 ? `<span class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-emerald-50 text-emerald-700" title="Validées">✓ ${nbValidees}</span>` : ''}
                            ${nbEnAttente > 0 ? `<span class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-amber-50 text-amber-700" title="En attente">⏳ ${nbEnAttente}</span>` : ''}
                            ${nbRejetees > 0 ? `<span class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-red-50 text-red-700" title="Rejetées">✗ ${nbRejetees}</span>` : ''}
                        </div>
                    </div>`;
            }

            return `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(d.reference || '')}</td>
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-900">${d.client ? UI.escapeHtml(d.client.nom_complet) : '—'}</div>
                    <div class="text-xs text-gray-500">${d.client ? UI.escapeHtml(d.client.code_client || '') : ''}</div>
                </td>
                <td class="px-4 py-3">${piecesCell}</td>
                <td class="px-4 py-3">
                    ${hasSignature
                        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700"><i class="fas fa-check-circle mr-1"></i>Oui</span>'
                        : '<span class="text-xs text-gray-400">Non signé</span>'}
                </td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full ${statutBadge}">${UI.escapeHtml(d.statut || '')}</span>
                </td>
                <td class="px-4 py-3 text-xs text-gray-500">${new Date(d.created_at).toLocaleDateString('fr-FR')}</td>
                <td class="px-4 py-3 text-right">
                    <a href="${window.APP_BASE}/kyc-dossiers/${d.id}"
                       class="inline-flex items-center gap-1 px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100">
                        <i class="fas fa-eye"></i> Voir
                    </a>
                </td>
            </tr>`;
        }).join('');
    }

    async function loadClientsDsa() {
        try {
            const res = await API.get('/dsa/clients/options');
            console.log('[KYC] clients dsa →', res.status);

            if (!res.ok) {
                console.warn('[KYC] clients dsa KO:', res.status, res.data);
                return;
            }

            const list = res.data?.data ?? res.data ?? [];
            const sel  = $('#client_dsa_id');
            if (!sel) return;

            sel.innerHTML = '<option value="">— Choisir un client DSA —</option>';

            if (!list.length) {
                sel.innerHTML = '<option value="">Aucun client DSA — créez-en un d\'abord</option>';
                return;
            }

            list.forEach(c => {
                sel.innerHTML += `<option value="${c.id}">${UI.escapeHtml(c.nom_complet)} (${UI.escapeHtml(c.code_client || '')})</option>`;
            });
        } catch (e) {
            console.error('[KYC] loadClientsDsa:', e);
        }
    }

    async function openCreate() {
        console.log('[KYC] openCreate()');

        const title = $('#modal-title');
        if (title) title.textContent = 'Nouveau dossier KYC';

        const form = $('#kyc-create-form');
        if (form) form.reset();

        const uuid = $('#local_uuid');
        if (uuid) uuid.value = (crypto.randomUUID ? crypto.randomUUID() : '');

        await loadClientsDsa();
        safeOpenModal('kyc-modal');
    }

    async function onCreateSubmit(e) {
        e.preventDefault();
        const clientId = $('#client_dsa_id')?.value;
        if (!clientId) {
            safeToast('danger', 'Sélectionnez un client DSA');
            return;
        }

        const btn = $('#btn-save');
        const payload = { local_uuid: $('#local_uuid')?.value.trim() || null };

        safeSetLoading(btn, true);
        try {
            const res = await API.post(`/dsa/clients/${clientId}/kyc/ouvrir`, payload);
            console.log('[KYC] create →', res.status, res.data);

            if (res.ok) {
                const demande = res.data?.demande || res.data;
                safeToast('success', 'Dossier KYC créé');
                safeCloseModal('kyc-modal');
                if (demande?.id) {
                    window.location.href = window.APP_BASE + '/kyc-dossiers/' + demande.id;
                } else {
                    loadDossiers(state.page);
                }
            } else {
                const errs = res.data?.errors || res.data?.message || res.data?.error;
                if (typeof errs === 'object' && errs !== null) {
                    safeToast('danger', Object.values(errs).flat().join(' · '));
                } else {
                    safeToast('danger', errs || 'Erreur');
                }
            }
        } catch (err) {
            console.error('[KYC] create exception:', err);
            safeToast('danger', 'Erreur réseau');
        } finally {
            safeSetLoading(btn, false);
        }
    }

    function attachEvents() {
        console.log('[KYC] attachEvents()');

        $('#search-input')?.addEventListener('input', UI.debounce(e => {
            state.q = e.target.value.trim();
            loadDossiers(1);
        }, 350));
        $('#filter-statut')?.addEventListener('change', e => { state.statut = e.target.value; loadDossiers(1); });
        $('#btn-refresh')?.addEventListener('click', () => loadDossiers(state.page));

        const btnCreate = $('#btn-create');
        if (btnCreate) {
            btnCreate.addEventListener('click', openCreate);
        } else {
            console.warn('[KYC] #btn-create introuvable');
        }

        const form = $('#kyc-create-form');
        if (form) form.addEventListener('submit', onCreateSubmit);

        $$('[data-close-modal]').forEach(b => b.addEventListener('click', () => safeCloseModal('kyc-modal')));
        $('#kyc-modal')?.addEventListener('click', e => {
            if (e.target.id === 'kyc-modal') safeCloseModal('kyc-modal');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();