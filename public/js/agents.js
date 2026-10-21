(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    let state = { page: 1, recherche: '', statut: '', zone_id: '' };
    let zones = [];
    let succursales = [];

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await Promise.all([loadZones(), loadSuccursales()]);
        await loadAgents();
    }

    async function loadZones() {
        const res = await API.get('/zones/options');
        if (res.ok && res.data?.data) {
            zones = res.data.data;
            const selFilter = $('#filter-zone');
            const selForm = $('#zone_id');
            zones.forEach(z => {
                selFilter.innerHTML += `<option value="${z.id}">${UI.escapeHtml(z.nom)}</option>`;
                selForm.innerHTML += `<option value="${z.id}">${UI.escapeHtml(z.nom)}</option>`;
            });
        }
    }

    async function loadSuccursales() {
        const res = await API.get('/succursales?per_page=100');
        if (res.ok && res.data?.data?.data) {
            succursales = res.data.data.data;
            const sel = $('#succursale_id');
            succursales.forEach(s => {
                sel.innerHTML += `<option value="${s.id}">${UI.escapeHtml(s.nom)}</option>`;
            });
        }
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

        const res = await API.get('/dsa/agents?' + params);
        if (!res.ok) {
            tbody.innerHTML = `<tr><td colspan="6" class="p-10 text-center text-red-500">Erreur de chargement</td></tr>`;
            return;
        }

        // Laravel Resource::collection retourne { data: [...] } et la pagination est dans meta
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
    }

    function renderTable(agents) {
        const tbody = $('#table-body');
        if (!agents.length) {
            tbody.innerHTML = `<tr><td colspan="6" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucun agent</td></tr>`;
            return;
        }

        const statutColor = {
            ACTIF: 'bg-emerald-50 text-emerald-700',
            SUSPENDU: 'bg-amber-50 text-amber-700',
            INACTIF: 'bg-gray-100 text-gray-600',
            RESILIE: 'bg-red-50 text-red-700',
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

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const { action, id, statut } = btn.dataset;

        if (action === 'edit') return openEdit(id);
        if (action === 'statut') return promptStatut(id, statut);
    });

    function openCreate() {
        $('#modal-title').textContent = 'Nouvel agent';
        $('#agent-form').reset();
        $('#agent-id').value = '';
        $('#username-field').classList.remove('hidden');
        $('#nom_utilisateur').required = true;
        UI.openModal('agent-modal');
    }

    async function openEdit(id) {
        const res = await API.get('/dsa/agents/' + id);
        if (!res.ok) { UI.toast('danger', 'Agent introuvable'); return; }

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
        $('#piece_identite_type').value = a.piece_identite?.type || 'CARTE_IDENTITE';
        $('#piece_identite_numero').value = ''; // masqué pour sécurité
        $('#date_embauche').value = a.date_embauche || '';
        $('#zone_id').value = a.zone?.id || '';
        // Note : succursale non exposée par la resource, adapte si besoin
        $('#username-field').classList.add('hidden');
        $('#nom_utilisateur').required = false;
        UI.openModal('agent-modal');
    }

    $('#agent-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = $('#agent-id').value;
        const isEdit = !!id;
        const btn = $('#btn-save');

        const payload = {
            prenom: $('#prenom').value.trim(),
            nom: $('#nom').value.trim(),
            sexe: $('#sexe').value,
            telephone: $('#telephone').value.trim(),
            email: $('#email').value.trim(),
            province: $('#province').value.trim(),
            ville: $('#ville').value.trim(),
            commune: $('#commune').value.trim() || null,
            piece_identite_type: $('#piece_identite_type').value,
            piece_identite_numero: $('#piece_identite_numero').value.trim(),
            date_embauche: $('#date_embauche').value,
            zone_id: $('#zone_id').value || null,
            succursale_id: $('#succursale_id').value || null,
        };

        if (!isEdit) {
            payload.nom_utilisateur = $('#nom_utilisateur').value.trim();
        }

        UI.setLoading(btn, true);
        const res = isEdit
            ? await API.put('/dsa/agents/' + id, payload)
            : await API.post('/dsa/agents', payload);
        UI.setLoading(btn, false);

        if (res.ok) {
            UI.toast('success', isEdit ? 'Agent mis à jour' : 'Agent créé');
            // Si création, afficher le mot de passe temporaire
            if (!isEdit && res.data?.mot_de_passe_temporaire) {
                UI.toast('info', 'Mot de passe temporaire : ' + res.data.mot_de_passe_temporaire, 15000);
            }
            UI.closeModal('agent-modal');
            loadAgents(state.page);
        } else {
            const errors = res.data?.errors || res.data?.error || res.data?.message;
            if (typeof errors === 'object') UI.toast('danger', Object.values(errors).flat().join(' · '));
            else UI.toast('danger', errors || 'Erreur');
        }
    });

    async function promptStatut(id, currentStatut) {
        const transitions = {
            ACTIF:    ['SUSPENDU', 'INACTIF', 'RESILIE'],
            SUSPENDU: ['ACTIF', 'INACTIF', 'RESILIE'],
            INACTIF:  ['ACTIF', 'RESILIE'],
            RESILIE:  [],
        };
        const options = transitions[currentStatut] || [];
        if (!options.length) {
            UI.toast('warning', 'Aucune transition possible depuis ' + currentStatut);
            return;
        }
        const choix = prompt('Nouveau statut (' + options.join(', ') + ') :', options[0]);
        if (!choix || !options.includes(choix.toUpperCase())) return;
        const motif = prompt('Motif (obligatoire sauf ACTIF) :') || null;

        const res = await API.post('/dsa/agents/' + id + '/statut', {
            statut: choix.toUpperCase(),
            motif,
        });
        if (res.ok) { UI.toast('success', 'Statut mis à jour'); loadAgents(state.page); }
        else UI.toast('danger', res.data?.error || res.data?.message || 'Erreur');
    }

    $('#search-input').addEventListener('input', UI.debounce(e => {
        state.recherche = e.target.value.trim();
        loadAgents(1);
    }, 400));

    $('#filter-statut').addEventListener('change', e => {
        state.statut = e.target.value;
        loadAgents(1);
    });

    $('#filter-zone').addEventListener('change', e => {
        state.zone_id = e.target.value;
        loadAgents(1);
    });

    $('#btn-refresh').addEventListener('click', () => loadAgents(state.page));
    $('#btn-create').addEventListener('click', openCreate);

    $$('[data-close-modal]').forEach(b => b.addEventListener('click', () => UI.closeModal('agent-modal')));
    $('#agent-modal').addEventListener('click', e => {
        if (e.target.id === 'agent-modal') UI.closeModal('agent-modal');
    });

    init();
})();