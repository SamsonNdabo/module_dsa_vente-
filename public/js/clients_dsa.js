(function () {
    'use strict';

    const TABLE = document.getElementById('table-body');
    if (!TABLE) {
        console.warn('[CLIENTS] #table-body introuvable — script ignoré');
        return;
    }

    console.log('[CLIENTS] script chargé');

    // ─────────────────────────────────────────────────────────
    // STATE
    // ─────────────────────────────────────────────────────────
    let state = {
        page: 1,
        recherche: '',
        statut: '',
        kyc_statut: '',
        agent_dsa_id: '',
    };

    let currentTab = 'pos';
    let agents = [];
    let zones = [];
    let selectedClientPos = null;

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

        // Chaque chargement est isolé : si l'un échoue, les autres continuent
        await Promise.allSettled([loadAgents(), loadZones()]);

        try {
            await loadClients();
        } catch (e) {
            console.error('[CLIENTS] loadClients fatal:', e);
            TABLE.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-red-500">
                Erreur fatale : ${UI.escapeHtml(e.message || 'Inconnue')}
            </td></tr>`;
        }

        // TOUJOURS attacher les événements
        attachEvents();
    }

    // ─────────────────────────────────────────────────────────
    // CHARGEMENT : AGENTS
    // ─────────────────────────────────────────────────────────
    async function loadAgents() {
        try {
            const res = await API.get('/dsa/agents/options');
            console.log('[CLIENTS] agents →', res.status);

            if (!res.ok) {
                console.warn('[CLIENTS] agents KO:', res.status, res.data);
                return;
            }

            const list = res.data?.data ?? res.data ?? [];
            agents = Array.isArray(list) ? list : [];

            const filterSel = $('#filter-agent');
            const formSel   = $('#agent_dsa_id');

            agents.forEach(a => {
                const label = a.nom_complet || a.code || `#${a.id}`;
                const opt = `<option value="${a.id}">${UI.escapeHtml(label)}</option>`;
                if (filterSel) filterSel.insertAdjacentHTML('beforeend', opt);
                if (formSel)   formSel.insertAdjacentHTML('beforeend', opt);
            });

            console.log('[CLIENTS] agents chargés:', agents.length);
        } catch (e) {
            console.error('[CLIENTS] agents exception:', e);
        }
    }

    // ─────────────────────────────────────────────────────────
    // CHARGEMENT : ZONES
    // ─────────────────────────────────────────────────────────
    async function loadZones() {
        try {
            const res = await API.get('/dsa/zones/options');
            console.log('[CLIENTS] zones →', res.status);

            if (!res.ok) {
                console.warn('[CLIENTS] zones KO:', res.status, res.data);
                return;
            }

            const list = res.data?.data ?? res.data ?? [];
            zones = Array.isArray(list) ? list : [];

            const formSel = $('#zone_id');
            zones.forEach(z => {
                if (!formSel) return;
                const code = z.code ?? '';
                const nom  = z.nom ?? '';
                formSel.insertAdjacentHTML(
                    'beforeend',
                    `<option value="${z.id}">${UI.escapeHtml(code)} — ${UI.escapeHtml(nom)}</option>`
                );
            });

            console.log('[CLIENTS] zones chargées:', zones.length);
        } catch (e) {
            console.error('[CLIENTS] zones exception:', e);
        }
    }

    // ─────────────────────────────────────────────────────────
    // CHARGEMENT : CLIENTS
    // ─────────────────────────────────────────────────────────
    async function loadClients(page = 1) {
        state.page = page;
        TABLE.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page,
            per_page: 15,
            ...(state.recherche   && { q: state.recherche }),
            ...(state.statut      && { statut: state.statut }),
            ...(state.kyc_statut  && { kyc_statut: state.kyc_statut }),
            ...(state.agent_dsa_id && { agent_dsa_id: state.agent_dsa_id }),
        });

        try {
            const res = await API.get('/dsa/clients?' + params);
            console.log('[CLIENTS] liste →', res.status);

            if (!res.ok) {
                const msg = res.data?.message || res.data?.error || `HTTP ${res.status}`;
                TABLE.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-red-500">
                    Erreur : ${UI.escapeHtml(msg)}
                </td></tr>`;
                return;
            }

            // Le controller renvoie soit Resource collection (data.data), soit objet paginé
            const paginator = res.data?.data ?? res.data ?? {};
            const items = paginator.data ?? paginator ?? [];

            renderTable(items);
            computeStats(items);

            if (UI.renderPagination && paginator.current_page !== undefined) {
                UI.renderPagination($('#pagination'), paginator, p => loadClients(p));
            }
        } catch (e) {
            console.error('[CLIENTS] loadClients exception:', e);
            TABLE.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-red-500">
                Exception : ${UI.escapeHtml(e.message || 'Inconnue')}
            </td></tr>`;
        }
    }

    // ─────────────────────────────────────────────────────────
    // STATS
    // ─────────────────────────────────────────────────────────
    function computeStats(items) {
        const el = id => document.getElementById(id);
        if (el('stat-total'))      el('stat-total').textContent      = items.length;
        if (el('stat-actifs'))     el('stat-actifs').textContent     = items.filter(c => c.statut === 'ACTIF').length;
        if (el('stat-kyc-valide')) el('stat-kyc-valide').textContent = items.filter(c => c.kyc_statut === 'VALIDE').length;
        if (el('stat-pos'))        el('stat-pos').textContent        = items.filter(c => c.client_id).length;
    }

    // ─────────────────────────────────────────────────────────
    // TABLE
    // ─────────────────────────────────────────────────────────
    function renderTable(items) {
        if (!items.length) {
            TABLE.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucun client DSA</td></tr>`;
            return;
        }

        TABLE.innerHTML = items.map(c => {
            const kycBadge = {
                AUCUN:      '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">Aucun</span>',
                EN_ATTENTE: '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-amber-50 text-amber-700">En attente</span>',
                EN_COURS:   '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700">En cours</span>',
                VALIDE:     '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700">Validé</span>',
                REJETE:     '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-50 text-red-700">Rejeté</span>',
            }[c.kyc_statut] || '—';

            const statutBadge =
                c.statut === 'ACTIF'
                    ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700">Actif</span>'
                    : c.statut === 'BLOQUE'
                        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-50 text-red-700">Bloqué</span>'
                        : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">Inactif</span>';

            return `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(c.code_client || '')}</td>
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-900">${UI.escapeHtml(c.nom_complet || '')}</div>
                    <div class="text-xs text-gray-500">${UI.escapeHtml(c.email || '')}</div>
                </td>
                <td class="px-4 py-3 text-xs">${UI.escapeHtml(c.telephone || '')}</td>
                <td class="px-4 py-3 text-xs text-gray-600">${c.agent_dsa ? UI.escapeHtml(c.agent_dsa.nom_complet) : '—'}</td>
                <td class="px-4 py-3">${kycBadge}</td>
                <td class="px-4 py-3 text-center">
                    ${c.client_id
                        ? '<i class="fas fa-link text-emerald-500" title="Lié au POS"></i>'
                        : '<span class="text-xs text-gray-400">—</span>'}
                </td>
                <td class="px-4 py-3">${statutBadge}</td>
                <td class="px-4 py-3 text-right">
                    <button data-action="edit" data-id="${c.id}"
                            class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button data-action="delete" data-id="${c.id}" data-name="${UI.escapeHtml(c.nom_complet || '')}"
                            class="px-2 py-1 text-xs text-red-600 bg-red-50 rounded hover:bg-red-100">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`;
        }).join('');
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
    // RECHERCHE CLIENT POS
    // ─────────────────────────────────────────────────────────
    async function searchClientsPos(query) {
        const container = $('#search-pos-results');
        if (!container) return;
        if (!query || query.length < 2) { container.classList.add('hidden'); return; }

        try {
            const res = await API.get('/dsa/clients/clients-pos-disponibles?q=' + encodeURIComponent(query));
            if (!res.ok) return;

            const results = res.data?.data ?? res.data ?? [];

            if (!results.length) {
                container.innerHTML = '<div class="p-3 text-sm text-gray-500">Aucun client POS disponible</div>';
                container.classList.remove('hidden');
                return;
            }

            container.innerHTML = results.map(c => `
                <div class="p-3 border-b border-gray-100 hover:bg-indigo-50 cursor-pointer" data-pos-id="${c.id}">
                    <div class="font-medium text-gray-900">${UI.escapeHtml(c.nom_complet || '')}</div>
                    <div class="text-xs text-gray-500">${UI.escapeHtml(c.code_client || '')} · ${UI.escapeHtml(c.telephone || '')}</div>
                </div>
            `).join('');
            container.classList.remove('hidden');

            container.querySelectorAll('[data-pos-id]').forEach(el => {
                el.addEventListener('click', () => {
                    const id = parseInt(el.dataset.posId, 10);
                    const client = results.find(r => r.id === id);
                    if (client) selectClientPos(client);
                });
            });
        } catch (e) {
            console.error('[CLIENTS] searchPos exception:', e);
        }
    }

    function selectClientPos(client) {
        selectedClientPos = client;
        if ($('#client_id_pos')) $('#client_id_pos').value = client.id;
        if ($('#pos-nom')) $('#pos-nom').textContent = client.nom_complet;
        if ($('#pos-code')) $('#pos-code').textContent = `${client.code_client || ''} · ${client.telephone || ''}`;
        $('#client-pos-selectionne')?.classList.remove('hidden');
        $('#search-pos-results')?.classList.add('hidden');
        if ($('#search-pos')) $('#search-pos').value = client.nom_complet;

        if (!$('#nom_complet').value) $('#nom_complet').value = client.nom_complet || '';
        if (!$('#telephone').value)   $('#telephone').value   = client.telephone || '';
        if (!$('#email').value && client.email) $('#email').value = client.email;
        if (!$('#adresse').value && client.adresse) $('#adresse').value = client.adresse;
    }

    function clearClientPos() {
        selectedClientPos = null;
        if ($('#client_id_pos')) $('#client_id_pos').value = '';
        $('#client-pos-selectionne')?.classList.add('hidden');
        if ($('#search-pos')) $('#search-pos').value = '';
        $('#search-pos-results')?.classList.add('hidden');
    }

    // ─────────────────────────────────────────────────────────
    // EVENTS
    // ─────────────────────────────────────────────────────────
    function attachEvents() {
        console.log('[CLIENTS] attachEvents()');

        $$('[data-tab]').forEach(b => b.addEventListener('click', () => setTab(b.dataset.tab)));

        $('#search-pos')?.addEventListener('input', UI.debounce(e => searchClientsPos(e.target.value.trim()), 300));
        $('#btn-clear-pos')?.addEventListener('click', clearClientPos);

        document.addEventListener('click', e => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const { action, id, name } = btn.dataset;
            if (action === 'edit')   return openEdit(id);
            if (action === 'delete') return confirmDelete(id, name);
        });

        $('#client-form')?.addEventListener('submit', onSubmit);

        $('#search-input')?.addEventListener('input', UI.debounce(e => {
            state.recherche = e.target.value.trim();
            loadClients(1);
        }, 400));

        ['#filter-statut', '#filter-kyc', '#filter-agent'].forEach(sel => {
            $(sel)?.addEventListener('change', e => {
                if (sel === '#filter-statut')      state.statut = e.target.value;
                if (sel === '#filter-kyc')         state.kyc_statut = e.target.value;
                if (sel === '#filter-agent')       state.agent_dsa_id = e.target.value;
                loadClients(1);
            });
        });

        $('#btn-refresh')?.addEventListener('click', () => loadClients(state.page));

        const btnCreate = $('#btn-create');
        if (btnCreate) {
            btnCreate.addEventListener('click', () => {
                console.log('[CLIENTS] openCreate()');
                openCreate();
            });
        } else {
            console.warn('[CLIENTS] #btn-create introuvable');
        }

        $$('[data-close-modal]').forEach(b =>
            b.addEventListener('click', () => UI.closeModal('client-modal'))
        );

        const modal = $('#client-modal');
        if (modal) {
            modal.addEventListener('click', e => {
                if (e.target.id === 'client-modal') UI.closeModal('client-modal');
            });
        } else {
            console.warn('[CLIENTS] #client-modal introuvable — vérifie le @include modal.blade.php');
        }
    }

    // ─────────────────────────────────────────────────────────
    // MODAL : OUVERTURE
    // ─────────────────────────────────────────────────────────
    function safeOpenModal() {
        const el = document.getElementById('client-modal');
        if (!el) { console.error('[CLIENTS] #client-modal introuvable'); return; }
        el.classList.remove('hidden');
        el.classList.add('flex');
        const content = el.querySelector('.modal-content');
        if (content) {
            content.classList.remove('scale-95');
            content.classList.add('scale-100');
        }
    }

    function safeCloseModal() {
        const el = document.getElementById('client-modal');
        if (!el) return;
        el.classList.add('hidden');
        el.classList.remove('flex');
        const content = el.querySelector('.modal-content');
        if (content) {
            content.classList.remove('scale-100');
            content.classList.add('scale-95');
        }
    }

    // ─────────────────────────────────────────────────────────
    // CRUD
    // ─────────────────────────────────────────────────────────
    function openCreate() {
        console.log('[CLIENTS] openCreate() → reset');

        $('#modal-title').textContent = 'Nouveau client DSA';
        $('#client-form').reset();
        $('#client-id').value = '';
        $('#client_id_pos').value = '';
        $('#client-pos-selectionne')?.classList.add('hidden');
        $('#search-pos-results')?.classList.add('hidden');
        if ($('#creer_client_pos')) $('#creer_client_pos').checked = false;
        setTab('pos');

        safeOpenModal();
    }

    async function openEdit(id) {
        try {
            const res = await API.get('/dsa/clients/' + id);
            if (!res.ok) { UI.toast('danger', 'Client introuvable'); return; }

            const c = res.data?.data ?? res.data;

            $('#modal-title').textContent = 'Modifier le client DSA';
            $('#client-id').value = c.id;
            $('#nom_complet').value = c.nom_complet || '';
            $('#sexe').value = c.sexe || '';
            $('#date_naissance').value = c.date_naissance || '';
            $('#telephone').value = c.telephone || '';
            $('#email').value = c.email || '';
            $('#adresse').value = c.adresse || '';
            $('#profession').value = c.profession || '';
            $('#activite_economique').value = c.activite_economique || '';
            $('#agent_dsa_id').value = c.agent_dsa_id || '';
            $('#zone_id').value = c.zone_id || '';
            $('#piece_identite_type').value = c.piece_identite_type || '';
            $('#piece_identite_numero').value = c.piece_identite_numero || '';

            if (c.client_id) {
                selectClientPos({
                    id: c.client_id,
                    nom_complet: c.client_pos?.nom_complet || '',
                    code_client: c.client_pos?.code || '',
                    telephone: c.client_pos?.telephone || '',
                });
                setTab('pos');
            } else {
                setTab('nouveau');
            }

            safeOpenModal();
        } catch (e) {
            console.error('[CLIENTS] openEdit exception:', e);
            UI.toast('danger', 'Erreur lors du chargement');
        }
    }

    async function onSubmit(e) {
        e.preventDefault();
        const id = $('#client-id').value;
        const isEdit = !!id;
        const btn = $('#btn-save');

        const payload = {
            nom_complet: $('#nom_complet').value.trim(),
            sexe: $('#sexe').value,
            date_naissance: $('#date_naissance').value,
            telephone: $('#telephone').value.trim(),
            email: $('#email').value.trim() || null,
            adresse: $('#adresse').value.trim(),
            profession: $('#profession').value.trim(),
            activite_economique: $('#activite_economique').value.trim(),
            agent_dsa_id: $('#agent_dsa_id').value || null,
            zone_id: $('#zone_id').value || null,
            piece_identite_type: $('#piece_identite_type').value || null,
            piece_identite_numero: $('#piece_identite_numero').value.trim() || null,
        };

        if (!isEdit) {
            if (currentTab === 'pos' && $('#client_id_pos').value) {
                payload.client_id = parseInt($('#client_id_pos').value, 10);
            }
            if (currentTab === 'nouveau') {
                payload.creer_client_pos = $('#creer_client_pos').checked;
            }
        }

        UI.setLoading(btn, true);
        try {
            const res = isEdit
                ? await API.put('/dsa/clients/' + id, payload)
                : await API.post('/dsa/clients', payload);

            if (res.ok) {
                UI.toast('success', isEdit ? 'Client mis à jour' : 'Client DSA créé');
                safeCloseModal();
                loadClients(state.page);
            } else {
                const errs = res.data?.errors || res.data?.error || res.data?.message;
                if (typeof errs === 'object') UI.toast('danger', Object.values(errs).flat().join(' · '));
                else UI.toast('danger', errs || 'Erreur');
            }
        } catch (err) {
            console.error('[CLIENTS] submit exception:', err);
            UI.toast('danger', 'Erreur réseau');
        } finally {
            UI.setLoading(btn, false);
        }
    }

    function confirmDelete(id, name) {
        UI.confirm(`Supprimer le client "${name}" ?`, async () => {
            const res = await API.delete('/dsa/clients/' + id);
            if (res.ok) { UI.toast('success', 'Client supprimé'); loadClients(state.page); }
            else UI.toast('danger', res.data?.error || 'Erreur');
        }, { title: 'Supprimer', confirmText: 'Supprimer' });
    }

    // GO
    init();
})();