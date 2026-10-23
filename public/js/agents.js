(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    let state = { page: 1, recherche: '', statut: '', zone_id: '' };
    let zones = [];

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

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

    // ══════════════════════════════════════════════════════════
    // INIT
    // ══════════════════════════════════════════════════════════
    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }

        try {
            await loadZones();
            await loadAgents();
        } catch (e) {
            console.error('[AGENTS] init erreur:', e);
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

    async function loadAgents(page = 1) {
        state.page = page;
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="6" class="p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page, per_page: 15,
            ...(state.recherche && { q: state.recherche }),
            ...(state.statut && { statut: state.statut }),
            ...(state.zone_id && { zone_id: state.zone_id }),
        });

        try {
            const res = await API.get('/dsa/agents?' + params);
            if (!res.ok) {
                tbody.innerHTML = `<tr><td colspan="6" class="p-10 text-center text-red-500">Erreur ${res.status}</td></tr>`;
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
                }, p => loadAgents(p));
            } else {
                $('#pagination').innerHTML = '';
            }
        } catch (e) {
            console.error('[AGENTS] list exception:', e);
            tbody.innerHTML = `<tr><td colspan="6" class="p-10 text-center text-red-500">Exception : ${UI.escapeHtml(e.message)}</td></tr>`;
        }
    }

    function renderTable(agents) {
        const tbody = $('#table-body');
        if (!agents.length) {
            tbody.innerHTML = `<tr><td colspan="6" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucun agent</td></tr>`;
            return;
        }

        const statutColor = {
            ACTIF:    'bg-emerald-50 text-emerald-700',
            SUSPENDU: 'bg-amber-50 text-amber-700',
            INACTIF:  'bg-gray-100 text-gray-600',
            RESILIE:  'bg-red-50 text-red-700',
        };

        tbody.innerHTML = agents.map(a => `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(a.code)}</td>
                <td class="px-4 py-3 font-medium text-gray-900">${UI.escapeHtml(a.nom_complet)}</td>
                <td class="px-4 py-3 text-gray-600">${UI.escapeHtml(a.telephone || '—')}</td>
                <td class="px-4 py-3 text-gray-600">${a.zone ? UI.escapeHtml(a.zone.nom) : '—'}</td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full ${statutColor[a.statut] || 'bg-gray-100 text-gray-600'}">
                        ${UI.escapeHtml(a.statut)}
                    </span>
                </td>
                <td class="px-4 py-3 text-right">
                    <button data-action="edit" data-id="${a.id}" title="Modifier"
                            class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button data-action="statut" data-id="${a.id}" data-statut="${a.statut}" title="Changer statut"
                            class="px-2 py-1 text-xs text-amber-600 bg-amber-50 rounded hover:bg-amber-100">
                        <i class="fas fa-exchange-alt"></i>
                    </button>
                </td>
            </tr>`).join('');
    }

    // ══════════════════════════════════════════════════════════
    // CRÉATION
    // ══════════════════════════════════════════════════════════
    function openCreate() {
        const titleEl = $('#modal-title');
        if (titleEl) titleEl.textContent = 'Nouvel agent DSA';

        const form = $('#agent-form');
        if (form) form.reset();

        $('#agent-id').value = '';
        $('#date_embauche').value = new Date().toISOString().slice(0, 10);

        openModal('agent-modal');
    }

    // ══════════════════════════════════════════════════════════
    // ÉDITION
    // ══════════════════════════════════════════════════════════
    async function openEdit(id) {
        const res = await API.get('/dsa/agents/' + id);
        if (!res.ok) { safeToast('danger', 'Agent introuvable'); return; }

        const a = res.data.data;
        $('#modal-title').textContent = 'Modifier l\'agent';
        $('#agent-id').value = a.id;
        $('#prenom').value = a.prenom || '';
        $('#nom').value = a.nom || '';
        $('#sexe').value = a.sexe || 'M';
        $('#telephone').value = a.telephone || '';
        $('#email').value = a.email || '';
        $('#province').value = a.province || '';
        $('#ville').value = a.ville || '';
        $('#commune').value = a.commune || '';
        $('#adresse').value = a.adresse || '';
        $('#piece_identite_type').value = a.piece_identite?.type || 'CARTE_IDENTITE';
        $('#piece_identite_numero').value = '';
        $('#date_embauche').value = a.date_embauche || '';
        $('#zone_id').value = a.zone?.id || '';

        openModal('agent-modal');
    }

    // ══════════════════════════════════════════════════════════
    // SUBMIT
    // ══════════════════════════════════════════════════════════
    async function onSubmit(e) {
        e.preventDefault();
        const id = $('#agent-id').value;
        const isEdit = !!id;
        const btn = $('#btn-save');

        const zoneId = $('#zone_id').value;
        if (!zoneId) {
            safeToast('danger', 'La zone est obligatoire.');
            return;
        }

        const payload = {
            prenom:                 $('#prenom').value.trim(),
            nom:                    $('#nom').value.trim(),
            sexe:                   $('#sexe').value,
            telephone:              $('#telephone').value.trim() || null,
            email:                  $('#email').value.trim() || null,
            province:               $('#province').value.trim(),
            ville:                  $('#ville').value.trim(),
            commune:                $('#commune').value.trim() || null,
            piece_identite_type:    $('#piece_identite_type').value,
            piece_identite_numero:  $('#piece_identite_numero').value.trim() || null,
            date_embauche:          $('#date_embauche').value,
            zone_id:                parseInt(zoneId, 10),
            adresse:                $('#adresse').value.trim() || null,
        };

        // ⚠️ Création : nom_utilisateur + mot de passe obligatoires
        if (!isEdit) {
            const nomUser = $('#nom_utilisateur')?.value.trim() || null;
            if (nomUser) payload.nom_utilisateur = nomUser;

            const pwd = $('#mot_de_passe')?.value || '';
            const pwd2 = $('#mot_de_passe_confirmation')?.value || '';

            if (!pwd) {
                safeToast('danger', 'Le mot de passe est obligatoire.');
                return;
            }
            if (pwd !== pwd2) {
                safeToast('danger', 'Les mots de passe ne correspondent pas.');
                return;
            }
            payload.mot_de_passe = pwd;
            payload.mot_de_passe_confirmation = pwd2;
        }

        if (typeof UI.setLoading === 'function') UI.setLoading(btn, true);

        try {
            const res = isEdit
                ? await API.put('/dsa/agents/' + id, payload)
                : await API.post('/dsa/agents', payload);

            if (typeof UI.setLoading === 'function') UI.setLoading(btn, false);

            if (res.ok) {
                safeToast('success', isEdit ? 'Agent mis à jour' : 'Agent créé');
                closeModal('agent-modal');
                loadAgents(state.page);
            } else {
                const errors = res.data?.errors || res.data?.error || res.data?.message;
                if (typeof errors === 'object') safeToast('danger', Object.values(errors).flat().join(' · '));
                else safeToast('danger', errors || 'Erreur');
            }
        } catch (err) {
            if (typeof UI.setLoading === 'function') UI.setLoading(btn, false);
            console.error('[AGENTS] onSubmit:', err);
            safeToast('danger', 'Erreur réseau');
        }
    }

    // ══════════════════════════════════════════════════════════
    // CHANGER STATUT
    // ══════════════════════════════════════════════════════════
    async function promptStatut(id, currentStatut) {
        const transitions = {
            ACTIF:    ['SUSPENDU', 'INACTIF', 'RESILIE'],
            SUSPENDU: ['ACTIF', 'INACTIF', 'RESILIE'],
            INACTIF:  ['ACTIF', 'RESILIE'],
            RESILIE:  [],
        };
        const options = transitions[currentStatut] || [];
        if (!options.length) {
            safeToast('warning', 'Aucune transition possible depuis ' + currentStatut);
            return;
        }
        const choix = prompt('Nouveau statut (' + options.join(', ') + ') :', options[0]);
        if (!choix || !options.includes(choix.toUpperCase())) return;
        const motif = prompt('Motif (obligatoire sauf ACTIF) :') || null;

        const res = await API.post('/dsa/agents/' + id + '/statut', {
            statut: choix.toUpperCase(),
            motif,
        });
        if (res.ok) { safeToast('success', 'Statut mis à jour'); loadAgents(state.page); }
        else safeToast('danger', res.data?.error || res.data?.message || 'Erreur');
    }

    // ══════════════════════════════════════════════════════════
    // ÉVÉNEMENTS
    // ══════════════════════════════════════════════════════════
    function attachEvents() {
        $('#search-input')?.addEventListener('input', UI.debounce(e => {
            state.recherche = e.target.value.trim();
            loadAgents(1);
        }, 400));

        $('#filter-statut')?.addEventListener('change', e => {
            state.statut = e.target.value;
            loadAgents(1);
        });

        $('#filter-zone')?.addEventListener('change', e => {
            state.zone_id = e.target.value;
            loadAgents(1);
        });

        $('#btn-refresh')?.addEventListener('click', () => loadAgents(state.page));

        $('#btn-create')?.addEventListener('click', openCreate);

        document.addEventListener('click', async e => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const { action, id, statut } = btn.dataset;
            if (action === 'edit')   return openEdit(id);
            if (action === 'statut') return promptStatut(id, statut);
        });

        const form = $('#agent-form');
        if (form) form.addEventListener('submit', onSubmit);

        $$('[data-close-modal]').forEach(b => {
            b.addEventListener('click', () => {
                const mid = b.dataset.closeModal;
                if (mid) closeModal(mid);
            });
        });

        const modal = $('#agent-modal');
        if (modal) {
            modal.addEventListener('click', e => {
                if (e.target.id === modal.id) closeModal(modal.id);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();