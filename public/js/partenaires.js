(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    let state = { page: 1, recherche: '', type: '', actif: '' };
    let allPartners = []; // cache pour les stats

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await loadPartenaires();
    }

    async function loadPartenaires(page = 1) {
        state.page = page;
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page, per_page: 15,
            ...(state.recherche && { q: state.recherche }),
            ...(state.type && { type: state.type }),
            ...(state.actif !== '' && { actif: state.actif }),
        });

        const res = await API.get('/dsa/partenaires?' + params);
        if (!res.ok) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-red-500">Erreur de chargement</td></tr>`;
            return;
        }

        const paginator = res.data.data;
        renderTable(paginator.data);
        computeStats(paginator.data);
        UI.renderPagination($('#pagination'), paginator, p => loadPartenaires(p));
    }

    function computeStats(items) {
        const total = items.length;
        const actifs = items.filter(p => p.actif).length;
        const inactifs = total - actifs;
        const produits = items.reduce((sum, p) => sum + (p.produits_count ?? 0), 0);

        $('#stat-total').textContent    = total;
        $('#stat-actifs').textContent   = actifs;
        $('#stat-inactifs').textContent = inactifs;
        $('#stat-produits').textContent = produits;
    }

    const TYPE_LABELS = {
        BANQUE: 'Banque',
        IMF: 'IMF',
        MOBILE_MONEY: 'Mobile Money',
        ASSURANCE: 'Assurance',
        COMMERCIAL: 'Commercial',
        AUTRE: 'Autre',
    };

    function renderTable(partenaires) {
        const tbody = $('#table-body');
        if (!partenaires.length) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucun partenaire</td></tr>`;
            return;
        }

        tbody.innerHTML = partenaires.map(p => `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(p.code)}</td>
                <td class="px-4 py-3 font-medium text-gray-900">${UI.escapeHtml(p.nom)}</td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-indigo-50 text-indigo-700">
                        ${TYPE_LABELS[p.type] || p.type}
                    </span>
                </td>
                <td class="px-4 py-3 text-gray-600 text-xs">
                    ${p.email ? `<div>${UI.escapeHtml(p.email)}</div>` : ''}
                    ${p.telephone ? `<div>${UI.escapeHtml(p.telephone)}</div>` : ''}
                    ${!p.email && !p.telephone ? '—' : ''}
                </td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-purple-50 text-purple-700">
                        ${p.produits_count ?? 0} produit(s)
                    </span>
                </td>
                <td class="px-4 py-3">
                    ${p.actif
                        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700">Actif</span>'
                        : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">Inactif</span>'}
                </td>
                <td class="px-4 py-3 text-right">
                    <button data-action="toggle" data-id="${p.id}" data-actif="${p.actif}" title="${p.actif ? 'Désactiver' : 'Activer'}"
                            class="px-2 py-1 text-xs ${p.actif ? 'text-amber-600 bg-amber-50 hover:bg-amber-100' : 'text-emerald-600 bg-emerald-50 hover:bg-emerald-100'} rounded">
                        <i class="fas ${p.actif ? 'fa-ban' : 'fa-check'}"></i>
                    </button>
                    <button data-action="edit" data-id="${p.id}" title="Modifier"
                            class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button data-action="delete" data-id="${p.id}" data-name="${UI.escapeHtml(p.nom)}" title="Supprimer"
                            class="px-2 py-1 text-xs text-red-600 bg-red-50 rounded hover:bg-red-100">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`).join('');
    }

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const { action, id, name, actif } = btn.dataset;

        if (action === 'edit')   return openEdit(id);
        if (action === 'delete') return confirmDelete(id, name);
        if (action === 'toggle') return toggleActif(id, actif === '1' || actif === 'true');
    });

    function openCreate() {
        $('#modal-title').textContent = 'Nouveau partenaire';
        $('#partenaire-form').reset();
        $('#partenaire-id').value = '';
        $('#actif').checked = true;
        UI.openModal('partenaire-modal');
    }

    async function openEdit(id) {
        const res = await API.get('/dsa/partenaires/' + id);
        if (!res.ok) { UI.toast('danger', 'Partenaire introuvable'); return; }

        const p = res.data.data;
        $('#modal-title').textContent = 'Modifier le partenaire';
        $('#partenaire-id').value = p.id;
        $('#code').value        = p.code || '';
        $('#nom').value         = p.nom || '';
        $('#type').value        = p.type || '';
        $('#telephone').value   = p.telephone || '';
        $('#email').value       = p.email || '';
        $('#ville').value       = p.ville || '';
        $('#adresse').value     = p.adresse || '';
        $('#contact_nom').value = p.contact_nom || '';
        $('#actif').checked     = !!p.actif;

        UI.openModal('partenaire-modal');
    }

    $('#partenaire-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = $('#partenaire-id').value;
        const isEdit = !!id;
        const btn = $('#btn-save');

        const payload = {
            code:        $('#code').value.trim(),
            nom:         $('#nom').value.trim(),
            type:        $('#type').value,
            telephone:   $('#telephone').value.trim()   || null,
            email:       $('#email').value.trim()       || null,
            ville:       $('#ville').value.trim()       || null,
            adresse:     $('#adresse').value.trim()     || null,
            contact_nom: $('#contact_nom').value.trim() || null,
            actif:       $('#actif').checked,
        };

        UI.setLoading(btn, true);
        const res = isEdit
            ? await API.put('/dsa/partenaires/' + id, payload)
            : await API.post('/dsa/partenaires', payload);
        UI.setLoading(btn, false);

        if (res.ok) {
            UI.toast('success', isEdit ? 'Partenaire mis à jour' : 'Partenaire créé');
            UI.closeModal('partenaire-modal');
            loadPartenaires(state.page);
        } else {
            const errors = res.data?.errors || res.data?.error || res.data?.message;
            if (typeof errors === 'object') UI.toast('danger', Object.values(errors).flat().join(' · '));
            else UI.toast('danger', errors || 'Erreur');
        }
    });

    function confirmDelete(id, name) {
        UI.confirm(`Supprimer le partenaire "${name}" ?`, async () => {
            const res = await API.delete('/dsa/partenaires/' + id);
            if (res.ok) { UI.toast('success', 'Partenaire supprimé'); loadPartenaires(state.page); }
            else UI.toast('danger', res.data?.error || 'Erreur');
        }, { title: 'Supprimer', confirmText: 'Supprimer' });
    }

    async function toggleActif(id, currentActif) {
        if (currentActif) {
            UI.confirm('Désactiver ce partenaire ?', async () => {
                const res = await API.post(`/dsa/partenaires/${id}/toggle-actif`);
                if (res.ok) { UI.toast('success', res.data.message); loadPartenaires(state.page); }
                else UI.toast('danger', res.data?.error || 'Erreur');
            }, { title: 'Désactiver', confirmText: 'Désactiver' });
        } else {
            const res = await API.post(`/dsa/partenaires/${id}/toggle-actif`);
            if (res.ok) { UI.toast('success', res.data.message); loadPartenaires(state.page); }
            else UI.toast('danger', res.data?.error || 'Erreur');
        }
    }

    $('#search-input').addEventListener('input', UI.debounce(e => {
        state.recherche = e.target.value.trim();
        loadPartenaires(1);
    }, 400));

    $('#filter-type').addEventListener('change', e => { state.type = e.target.value; loadPartenaires(1); });
    $('#filter-actif').addEventListener('change', e => { state.actif = e.target.value; loadPartenaires(1); });

    $('#btn-refresh').addEventListener('click', () => loadPartenaires(state.page));
    $('#btn-create').addEventListener('click', openCreate);

    $$('[data-close-modal]').forEach(b => b.addEventListener('click', () => UI.closeModal('partenaire-modal')));
    $('#partenaire-modal').addEventListener('click', e => {
        if (e.target.id === 'partenaire-modal') UI.closeModal('partenaire-modal');
    });

    init();
})();