(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    let state = { page: 1, recherche: '', statut: '', kyc_statut: '', type_client: '', zone_id: '' };
    let zones = [];
    let agents = [];

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    // ══════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════
    function safeToast(type, msg) {
        if (window.UI && typeof UI.toast === 'function') UI.toast(type, msg);
        else { console.log(`[${type}]`, msg); if (type === 'danger') alert(msg); }
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

    function setLoading(btn, loading) {
        if (!btn) return;
        const spinner = btn.querySelector('.spinner');
        if (spinner) spinner.classList.toggle('hidden', !loading);
        btn.disabled = loading;
        btn.classList.toggle('opacity-60', loading);
    }

    // Toggle blocs selon type_client
    function toggleTypeBlocs() {
        const type = $('#type_client')?.value;
        const blocPersonne = $('#bloc-personne');
        const blocEntreprise = $('#bloc-entreprise');

        if (!blocPersonne || !blocEntreprise) return;

        if (type === 'PERSONNE_PHYSIQUE') {
            blocPersonne.classList.remove('hidden');
            blocEntreprise.classList.add('hidden');
        } else if (type === 'ENTREPRISE') {
            blocPersonne.classList.add('hidden');
            blocEntreprise.classList.remove('hidden');
        } else {
            // AUTRE : les deux masqués (seuls les champs communs sont obligatoires)
            blocPersonne.classList.add('hidden');
            blocEntreprise.classList.add('hidden');
        }
    }

    // ══════════════════════════════════════════════════════════
    // INIT
    // ══════════════════════════════════════════════════════════
    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }

        try {
            await Promise.all([loadZones(), loadAgents()]);
            await loadClients();
        } catch (e) {
            console.error('[CLIENTS] init:', e);
        }

        attachEvents();
    }

    async function loadZones() {
        try {
            const res = await API.get('/zones/options');
            if (res.ok && res.data?.data) {
                zones = res.data.data;
                const selFilter = $('#filter-zone');
                const selForm = $('#zone_id');
                zones.forEach(z => {
                    const opt = `<option value="${z.id}">${UI.escapeHtml(z.nom)}</option>`;
                    if (selFilter) selFilter.insertAdjacentHTML('beforeend', opt);
                    if (selForm)   selForm.insertAdjacentHTML('beforeend', opt);
                });
            }
        } catch (e) { console.error(e); }
    }

    async function loadAgents() {
        try {
            const res = await API.get('/dsa/agents/options?only_actif=1');
            if (res.ok && res.data?.data) {
                agents = res.data.data;
                const sel = $('#agent_dsa_id');
                agents.forEach(a => {
                    if (sel) sel.insertAdjacentHTML('beforeend',
                        `<option value="${a.id}">${UI.escapeHtml(a.nom_complet)} (${UI.escapeHtml(a.code)})</option>`);
                });
            }
        } catch (e) { console.error(e); }
    }

    async function loadClients(page = 1) {
        state.page = page;
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page, per_page: 15,
            ...(state.recherche   && { q: state.recherche }),
            ...(state.statut      && { statut: state.statut }),
            ...(state.kyc_statut  && { kyc_statut: state.kyc_statut }),
            ...(state.type_client && { type_client: state.type_client }),
            ...(state.zone_id     && { zone_id: state.zone_id }),
        });

        try {
            const res = await API.get('/dsa/clients?' + params);
            if (!res.ok) {
                tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-red-500">Erreur ${res.status}</td></tr>`;
                return;
            }

            const payload = res.data;
            const items = payload.data || [];
            const meta = payload.meta || {};

            renderTable(items);
            if (meta.last_page > 1) {
                UI.renderPagination($('#pagination'), {
                    current_page: meta.current_page,
                    last_page: meta.last_page,
                }, p => loadClients(p));
            } else {
                $('#pagination').innerHTML = '';
            }
        } catch (e) {
            console.error('[CLIENTS] list:', e);
            tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-red-500">Exception : ${UI.escapeHtml(e.message)}</td></tr>`;
        }
    }

    function renderTable(clients) {
        const tbody = $('#table-body');
        if (!clients.length) {
            tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucun client</td></tr>`;
            return;
        }

        const statutColor = {
            ACTIF:    'bg-emerald-50 text-emerald-700',
            INACTIF:  'bg-gray-100 text-gray-600',
            BLOQUE:   'bg-red-50 text-red-700',
            EN_ATTENTE: 'bg-amber-50 text-amber-700',
        };
        const kycColor = {
            AUCUN:      'bg-gray-100 text-gray-600',
            EN_ATTENTE: 'bg-amber-50 text-amber-700',
            EN_COURS:   'bg-blue-50 text-blue-700',
            VALIDE:     'bg-emerald-50 text-emerald-700',
            REJETE:     'bg-red-50 text-red-700',
        };
        const typeBadge = {
            PERSONNE_PHYSIQUE: '<span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-blue-50 text-blue-700"><i class="fas fa-user mr-1"></i>Personne</span>',
            ENTREPRISE:        '<span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-purple-50 text-purple-700"><i class="fas fa-building mr-1"></i>Entreprise</span>',
            AUTRE:             '<span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-gray-100 text-gray-700"><i class="fas fa-question mr-1"></i>Autre</span>',
        };

        tbody.innerHTML = clients.map(c => `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(c.code_client)}</td>
                <td class="px-4 py-3">${typeBadge[c.type_client] || '—'}</td>
                <td class="px-4 py-3 font-medium text-gray-900">${UI.escapeHtml(c.nom_complet)}</td>
                <td class="px-4 py-3 text-gray-600">${UI.escapeHtml(c.telephone || '—')}</td>
                <td class="px-4 py-3 text-gray-600">${c.zone ? UI.escapeHtml(c.zone.nom) : '—'}</td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full ${kycColor[c.kyc_statut] || 'bg-gray-100 text-gray-600'}">
                        ${UI.escapeHtml(c.kyc_statut_label || c.kyc_statut)}
                    </span>
                </td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full ${statutColor[c.statut] || 'bg-gray-100 text-gray-600'}">
                        ${UI.escapeHtml(c.statut)}
                    </span>
                </td>
                <td class="px-4 py-3 text-right">
                    <button data-action="edit" data-id="${c.id}" title="Modifier"
                            class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button data-action="statut" data-id="${c.id}" data-statut="${c.statut}" title="Changer statut"
                            class="px-2 py-1 text-xs text-amber-600 bg-amber-50 rounded hover:bg-amber-100">
                        <i class="fas fa-exchange-alt"></i>
                    </button>
                    <button data-action="delete" data-id="${c.id}" data-nom="${UI.escapeHtml(c.nom_complet)}" title="Supprimer"
                            class="px-2 py-1 text-xs text-red-600 bg-red-50 rounded hover:bg-red-100">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`).join('');
    }

    // ══════════════════════════════════════════════════════════
    // CRÉATION
    // ══════════════════════════════════════════════════════════
    function openCreate() {
        const titleEl = $('#modal-title');
        if (titleEl) titleEl.textContent = 'Nouveau client';

        const form = $('#client-form');
        if (form) form.reset();

        $('#client-id').value = '';
        $('#type_client').value = 'PERSONNE_PHYSIQUE';
        toggleTypeBlocs();

        openModal('client-modal');
    }

    // ══════════════════════════════════════════════════════════
    // ÉDITION
    // ══════════════════════════════════════════════════════════
    async function openEdit(id) {
        const res = await API.get('/dsa/clients/' + id);
        if (!res.ok) { safeToast('danger', 'Client introuvable'); return; }

        const c = res.data.data;
        $('#modal-title').textContent = 'Modifier le client';
        $('#client-id').value = c.id;

        // Type
        $('#type_client').value = c.type_client || 'PERSONNE_PHYSIQUE';

        // Commun
        $('#nom_complet').value = c.nom_complet || '';
        $('#telephone').value = c.telephone || '';
        $('#email').value = c.email || '';
        $('#adresse').value = c.adresse || '';

        // Personne physique
        $('#sexe').value = c.sexe || '';
        $('#date_naissance').value = c.date_naissance || '';
        $('#profession').value = c.profession || '';
        $('#activite_economique').value = c.activite_economique || '';

        // Entreprise
        $('#raison_sociale').value = c.raison_sociale || '';
        $('#numero_rc').value = c.numero_rc || '';
        $('#numero_impot').value = c.numero_impot || '';
        $('#representant_legal').value = c.representant_legal || '';

        // Pièce
        $('#piece_identite_type').value = c.piece_identite_type || '';
        $('#piece_identite_numero').value = c.piece_identite_numero || '';

        // Rattachement
        $('#agent_dsa_id').value = c.agent_dsa_id || '';
        $('#zone_id').value = c.zone_id || '';

        toggleTypeBlocs();
        openModal('client-modal');
    }

    // ══════════════════════════════════════════════════════════
    // SUBMIT
    // ══════════════════════════════════════════════════════════
    async function onSubmit(e) {
        e.preventDefault();
        const id = $('#client-id').value;
        const isEdit = !!id;
        const btn = $('#btn-save');

        const type = $('#type_client').value;

        const payload = {
            type_client:       type,
            nom_complet:       $('#nom_complet').value.trim(),
            telephone:         $('#telephone').value.trim(),
            email:             $('#email').value.trim() || null,
            adresse:           $('#adresse').value.trim(),
            piece_identite_type:   $('#piece_identite_type').value || null,
            piece_identite_numero: $('#piece_identite_numero').value.trim() || null,
            agent_dsa_id:      $('#agent_dsa_id').value ? parseInt($('#agent_dsa_id').value, 10) : null,
            zone_id:           $('#zone_id').value ? parseInt($('#zone_id').value, 10) : null,
        };

        if (type === 'PERSONNE_PHYSIQUE') {
            payload.sexe = $('#sexe').value || null;
            payload.date_naissance = $('#date_naissance').value || null;
            payload.profession = $('#profession').value.trim() || null;
            payload.activite_economique = $('#activite_economique').value.trim() || null;
        } else if (type === 'ENTREPRISE') {
            payload.raison_sociale = $('#raison_sociale').value.trim() || null;
            payload.numero_rc = $('#numero_rc').value.trim() || null;
            payload.numero_impot = $('#numero_impot').value.trim() || null;
            payload.representant_legal = $('#representant_legal').value.trim() || null;
        }

        // Validation front minimaliste
        if (!payload.nom_complet || !payload.telephone || !payload.adresse) {
            safeToast('danger', 'Nom, téléphone et adresse sont obligatoires.');
            return;
        }
        if (type === 'PERSONNE_PHYSIQUE' && (!payload.sexe || !payload.date_naissance || !payload.profession || !payload.activite_economique)) {
            safeToast('danger', 'Sexe, date de naissance, profession et activité économique sont obligatoires.');
            return;
        }
        if (type === 'ENTREPRISE' && (!payload.raison_sociale || !payload.numero_rc || !payload.representant_legal)) {
            safeToast('danger', 'Raison sociale, N° RC et représentant légal sont obligatoires.');
            return;
        }

        setLoading(btn, true);

        try {
            const res = isEdit
                ? await API.put('/dsa/clients/' + id, payload)
                : await API.post('/dsa/clients', payload);

            setLoading(btn, false);

            if (res.ok) {
                safeToast('success', isEdit ? 'Client mis à jour' : 'Client créé');
                closeModal('client-modal');
                loadClients(state.page);
            } else {
                const errors = res.data?.errors || res.data?.error || res.data?.message;
                if (typeof errors === 'object') safeToast('danger', Object.values(errors).flat().join(' · '));
                else safeToast('danger', errors || 'Erreur');
            }
        } catch (err) {
            setLoading(btn, false);
            console.error('[CLIENTS] onSubmit:', err);
            safeToast('danger', 'Erreur réseau');
        }
    }

    // ══════════════════════════════════════════════════════════
    // CHANGER STATUT
    // ══════════════════════════════════════════════════════════
    async function promptStatut(id, currentStatut) {
        const transitions = {
            ACTIF:    ['INACTIF', 'BLOQUE'],
            INACTIF:  ['ACTIF', 'BLOQUE'],
            BLOQUE:   ['ACTIF', 'INACTIF'],
        };
        const options = transitions[currentStatut] || [];
        if (!options.length) {
            safeToast('warning', 'Aucune transition possible depuis ' + currentStatut);
            return;
        }
        const choix = prompt('Nouveau statut (' + options.join(', ') + ') :', options[0]);
        if (!choix || !options.includes(choix.toUpperCase())) return;

        const res = await API.post('/dsa/clients/' + id + '/statut', {
            statut: choix.toUpperCase(),
        });
        if (res.ok) { safeToast('success', 'Statut mis à jour'); loadClients(state.page); }
        else safeToast('danger', res.data?.error || res.data?.message || 'Erreur');
    }

    // ══════════════════════════════════════════════════════════
    // SUPPRIMER
    // ══════════════════════════════════════════════════════════
    async function deleteClient(id, nom) {
        if (!confirm(`Supprimer définitivement le client "${nom}" ?\n\nCette action est réversible (soft delete).`)) return;

        const res = await API.delete('/dsa/clients/' + id);
        if (res.ok) {
            safeToast('success', 'Client supprimé');
            loadClients(state.page);
        } else {
            safeToast('danger', res.data?.error || res.data?.message || 'Erreur');
        }
    }

    // ══════════════════════════════════════════════════════════
    // ÉVÉNEMENTS
    // ══════════════════════════════════════════════════════════
    function attachEvents() {
        // Filtres
        $('#search-input')?.addEventListener('input', UI.debounce(e => {
            state.recherche = e.target.value.trim();
            loadClients(1);
        }, 400));

        $('#filter-type')?.addEventListener('change', e => {
            state.type_client = e.target.value;
            loadClients(1);
        });

        $('#filter-statut')?.addEventListener('change', e => {
            state.statut = e.target.value;
            loadClients(1);
        });

        $('#filter-kyc')?.addEventListener('change', e => {
            state.kyc_statut = e.target.value;
            loadClients(1);
        });

        $('#filter-zone')?.addEventListener('change', e => {
            state.zone_id = e.target.value;
            loadClients(1);
        });

        $('#btn-refresh')?.addEventListener('click', () => loadClients(state.page));
        $('#btn-create')?.addEventListener('click', openCreate);

        // Toggle type → blocs conditionnels
        $('#type_client')?.addEventListener('change', toggleTypeBlocs);

        // Actions table
        document.addEventListener('click', e => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const { action, id, statut, nom } = btn.dataset;
            if (action === 'edit')   return openEdit(id);
            if (action === 'statut') return promptStatut(id, statut);
            if (action === 'delete') return deleteClient(id, nom);
        });

        // Submit
        $('#client-form')?.addEventListener('submit', onSubmit);

        // Fermeture modals
        $$('[data-close-modal]').forEach(b => {
            b.addEventListener('click', () => {
                const mid = b.dataset.closeModal;
                if (mid) closeModal(mid);
            });
        });

        const modal = $('#client-modal');
        if (modal) {
            modal.addEventListener('click', e => {
                if (e.target.id === modal.id) closeModal(modal.id);
            });
        }
    }

    // GO
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();