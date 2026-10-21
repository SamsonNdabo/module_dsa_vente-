(function () {
    'use strict';

    if (!document.getElementById('table-body')) return;

    let state = {
        page: 1,
        recherche: '',
        statut: '',
        role_id: '',
    };
    let rolesList = [];
    let succursalesList = [];

    const $ = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    // ─────────────────────────────────────────
    // CHARGEMENT INITIAL
    // ─────────────────────────────────────────
    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await Promise.all([loadRoles(), loadSuccursales()]);
        await loadUsers();
    }

    async function loadRoles() {
        const res = await API.get('/roles?per_page=100');
        if (res.ok && res.data?.data?.data) {
            rolesList = res.data.data.data;
            const sel = $('#filter-role');
            const formSel = $('#role_id');
            rolesList.forEach(r => {
                sel.innerHTML += `<option value="${r.id}">${UI.escapeHtml(r.nom_role)}</option>`;
                formSel.innerHTML += `<option value="${r.id}">${UI.escapeHtml(r.nom_role)}</option>`;
            });
        }
    }

    async function loadSuccursales() {
        const res = await API.get('/succursales?per_page=100');
        if (res.ok && res.data?.data?.data) {
            succursalesList = res.data.data.data;
            const formSel = $('#succursale_id');
            succursalesList.forEach(s => {
                formSel.innerHTML += `<option value="${s.id}">${UI.escapeHtml(s.nom)}</option>`;
            });
        }
    }

    // ─────────────────────────────────────────
    // LISTE
    // ─────────────────────────────────────────
    async function loadUsers(page = 1) {
        state.page = page;
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page,
            per_page: 15,
            ...(state.recherche && { recherche: state.recherche }),
            ...(state.statut && { statut: state.statut }),
            ...(state.role_id && { role_id: state.role_id }),
        });

        const res = await API.get('/utilisateurs?' + params);

        if (!res.ok) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-red-500">
                Erreur de chargement</td></tr>`;
            return;
        }

        const { data, stats } = res.data;
        renderStats(stats);
        renderTable(data.data);
        UI.renderPagination($('#pagination'), data, p => loadUsers(p));
    }

    function renderStats(stats) {
        if (!stats) return;
        $('#stat-total').textContent = stats.total ?? 0;
        $('#stat-actifs').textContent = stats.actifs ?? 0;
        $('#stat-inactifs').textContent = stats.inactifs ?? 0;
        $('#stat-verrouilles').textContent = stats.verrouilles ?? 0;
        $('#stat-enligne').textContent = stats.en_ligne ?? 0;
    }

    function renderTable(users) {
        const tbody = $('#table-body');
        if (!users.length) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>
                Aucun utilisateur trouvé</td></tr>`;
            return;
        }

        tbody.innerHTML = users.map(u => `
            <tr class="hover:bg-gray-50 transition">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center justify-center w-9 h-9 rounded-full
                                     bg-gradient-to-br from-indigo-500 to-indigo-700 text-white text-xs font-bold">
                            ${UI.initials(u.nom_complet)}
                        </span>
                        <div>
                            <div class="font-medium text-gray-900">${UI.escapeHtml(u.nom_complet)}</div>
                            <div class="text-xs text-gray-500">${UI.escapeHtml(u.matricule || '')}</div>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3 text-gray-600">${UI.escapeHtml(u.email)}</td>
                <td class="px-4 py-3">
                    ${u.role ? `<span class="px-2 py-1 text-xs font-semibold rounded-full bg-indigo-50 text-indigo-700">
                        ${UI.escapeHtml(u.role.nom_role)}</span>` : '<span class="text-gray-400">—</span>'}
                </td>
                <td class="px-4 py-3 text-gray-600">${u.succursale ? UI.escapeHtml(u.succursale.nom) : '—'}</td>
                <td class="px-4 py-3">
                    ${u.statut === 'ACTIF'
                        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700"><i class="fas fa-circle text-[6px] mr-1"></i>Actif</span>'
                        : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600"><i class="fas fa-circle text-[6px] mr-1"></i>Inactif</span>'}
                    ${u.compte_verrouille ? '<span class="ml-1 px-2 py-1 text-xs font-semibold rounded-full bg-red-50 text-red-700"><i class="fas fa-lock text-[10px] mr-1"></i>Verrouillé</span>' : ''}
                </td>
                <td class="px-4 py-3">
                    ${u.two_factor_email_enabled
                        ? '<i class="fas fa-shield-alt text-emerald-500"></i>'
                        : '<i class="fas fa-shield-alt text-gray-300"></i>'}
                </td>
                <td class="px-4 py-3 text-right">
                    <div class="inline-flex gap-1">
                        <button data-action="edit" data-id="${u.id}" title="Modifier"
                                class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button data-action="${u.statut === 'ACTIF' ? 'deactivate' : 'activate'}" data-id="${u.id}"
                                title="${u.statut === 'ACTIF' ? 'Désactiver' : 'Activer'}"
                                class="px-2 py-1 text-xs ${u.statut === 'ACTIF' ? 'text-amber-600 bg-amber-50 hover:bg-amber-100' : 'text-emerald-600 bg-emerald-50 hover:bg-emerald-100'} rounded">
                            <i class="fas ${u.statut === 'ACTIF' ? 'fa-ban' : 'fa-check'}"></i>
                        </button>
                        <button data-action="reset-password" data-id="${u.id}" title="Reset mot de passe"
                                class="px-2 py-1 text-xs text-blue-600 bg-blue-50 rounded hover:bg-blue-100">
                            <i class="fas fa-key"></i>
                        </button>
                        <button data-action="delete" data-id="${u.id}" data-name="${UI.escapeHtml(u.nom_complet)}" title="Supprimer"
                                class="px-2 py-1 text-xs text-red-600 bg-red-50 rounded hover:bg-red-100">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>`).join('');
    }

    // ─────────────────────────────────────────
    // ACTIONS
    // ─────────────────────────────────────────
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const action = btn.dataset.action;
        const id = btn.dataset.id;

        if (action === 'edit') return openEdit(id);
        if (action === 'delete') return confirmDelete(id, btn.dataset.name);
        if (action === 'activate') return doActivate(id);
        if (action === 'deactivate') return doDeactivate(id);
        if (action === 'reset-password') return doResetPassword(id);
    });

    function openCreate() {
        $('#modal-title').textContent = 'Nouvel utilisateur';
        $('#user-form').reset();
        $('#user-id').value = '';
        $('#password-fields').classList.remove('hidden');
        $('#mot_de_passe').required = true;
        $('#mot_de_passe_confirmation').required = true;
        UI.openModal('user-modal');
    }

    async function openEdit(id) {
        const res = await API.get('/utilisateurs/' + id);
        if (!res.ok) { UI.toast('danger', 'Utilisateur introuvable'); return; }

        const u = res.data.data;
        $('#modal-title').textContent = 'Modifier l\'utilisateur';
        $('#user-id').value = u.id;
        $('#nom_complet').value = u.nom_complet || '';
        $('#nom_utilisateur').value = u.nom_utilisateur || '';
        $('#email').value = u.email || '';
        $('#telephone').value = u.telephone || '';
        $('#role_id').value = u.role_id || '';
        $('#succursale_id').value = u.succursale_id || '';
        $('#password-fields').classList.add('hidden');
        $('#mot_de_passe').required = false;
        $('#mot_de_passe_confirmation').required = false;
        UI.openModal('user-modal');
    }

    // Soumission formulaire
    $('#user-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = $('#user-id').value;
        const isEdit = !!id;
        const btn = $('#btn-save');

        const payload = {
            nom_complet: $('#nom_complet').value.trim(),
            nom_utilisateur: $('#nom_utilisateur').value.trim(),
            email: $('#email').value.trim(),
            telephone: $('#telephone').value.trim(),
            role_id: $('#role_id').value,
            succursale_id: $('#succursale_id').value,
        };

        if (!isEdit) {
            const pwd = $('#mot_de_passe').value;
            const confirm = $('#mot_de_passe_confirmation').value;
            if (pwd !== confirm) { UI.toast('danger', 'Les mots de passe ne correspondent pas'); return; }
            if (pwd.length < 8) { UI.toast('danger', 'Mot de passe trop court (min 8)'); return; }
            payload.mot_de_passe = pwd;
            payload.mot_de_passe_confirmation = confirm;
        }

        UI.setLoading(btn, true);
        const res = isEdit
            ? await API.put('/utilisateurs/' + id, payload)
            : await API.post('/utilisateurs', payload);
        UI.setLoading(btn, false);

        if (res.ok) {
            UI.toast('success', isEdit ? 'Utilisateur mis à jour' : 'Utilisateur créé');
            UI.closeModal('user-modal');
            loadUsers(state.page);
        } else {
            const errors = res.data?.errors || res.data?.error || res.data?.message;
            if (typeof errors === 'object') {
                UI.toast('danger', Object.values(errors).flat().join(' · '));
            } else {
                UI.toast('danger', errors || 'Erreur');
            }
        }
    });

    function confirmDelete(id, name) {
        UI.confirm(`Supprimer l'utilisateur "${name}" ? Cette action est irréversible.`, async () => {
            const res = await API.delete('/utilisateurs/' + id);
            if (res.ok) {
                UI.toast('success', 'Utilisateur supprimé');
                loadUsers(state.page);
            } else {
                UI.toast('danger', res.data?.error || 'Erreur');
            }
        }, { title: 'Supprimer l\'utilisateur', confirmText: 'Supprimer' });
    }

    async function doActivate(id) {
        const res = await API.post(`/utilisateurs/${id}/activate`);
        if (res.ok) { UI.toast('success', 'Utilisateur activé'); loadUsers(state.page); }
        else UI.toast('danger', res.data?.error || 'Erreur');
    }

    async function doDeactivate(id) {
        UI.confirm('Désactiver cet utilisateur ?', async () => {
            const res = await API.post(`/utilisateurs/${id}/deactivate`);
            if (res.ok) { UI.toast('success', 'Utilisateur désactivé'); loadUsers(state.page); }
            else UI.toast('danger', res.data?.error || 'Erreur');
        }, { title: 'Désactiver', confirmText: 'Désactiver' });
    }

    function doResetPassword(id) {
        const pwd = prompt('Nouveau mot de passe (min 8 caractères) :');
        if (!pwd || pwd.length < 8) { UI.toast('warning', 'Mot de passe invalide'); return; }
        const confirm = prompt('Confirmer le mot de passe :');
        if (pwd !== confirm) { UI.toast('danger', 'Les mots de passe ne correspondent pas'); return; }

        API.post(`/utilisateurs/${id}/reset-password`, {
            nouveau_mot_de_passe: pwd,
            nouveau_mot_de_passe_confirmation: confirm,
        }).then(res => {
            if (res.ok) UI.toast('success', 'Mot de passe réinitialisé');
            else UI.toast('danger', res.data?.error || 'Erreur');
        });
    }

    // ─────────────────────────────────────────
    // FILTRES
    // ─────────────────────────────────────────
    $('#search-input').addEventListener('input', UI.debounce(e => {
        state.recherche = e.target.value.trim();
        loadUsers(1);
    }, 400));

    $('#filter-statut').addEventListener('change', e => {
        state.statut = e.target.value;
        loadUsers(1);
    });

    $('#filter-role').addEventListener('change', e => {
        state.role_id = e.target.value;
        loadUsers(1);
    });

    $('#btn-refresh').addEventListener('click', () => loadUsers(state.page));
    $('#btn-create').addEventListener('click', openCreate);

    // Fermeture modal
    $$('[data-close-modal]').forEach(b => b.addEventListener('click', () => UI.closeModal('user-modal')));
    $('#user-modal').addEventListener('click', e => {
        if (e.target.id === 'user-modal') UI.closeModal('user-modal');
    });

    init();
})();