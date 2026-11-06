(function () {
    'use strict';
    if (!document.getElementById('table-body')) return;

    let state = { page: 1, recherche: '' };

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    function toast(type, msg) {
        if (window.UI && typeof UI.toast === 'function') UI.toast(type, msg);
        else console.log(type, msg);
    }

    function setLoading(btn, loading) {
        if (!btn) return;
        const spinner = btn.querySelector('.spinner');
        if (spinner) spinner.classList.toggle('hidden', !loading);
        btn.disabled = loading;
    }

    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await loadActivations();
        await loadClients();
        await loadProduitsSim();
        attachEvents();
    }

    async function loadActivations(page = 1) {
        state.page = page;
        const tbody = $('#table-body');
        tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i></td></tr>`;

        const params = new URLSearchParams({
            page, per_page: 15,
            ...(state.recherche && { q: state.recherche }),
        });

        try {
            const res = await API.get('/dsa/activations-sim?' + params);
            if (!res.ok) {
                tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-red-500">Erreur ${res.status}</td></tr>`;
                return;
            }

            const items = res.data?.data || [];
            const meta  = res.data?.meta || {};

            renderTable(items);
            computeStats(items);

            if (meta.last_page > 1) {
                UI.renderPagination($('#pagination'), {
                    current_page: meta.current_page,
                    last_page: meta.last_page,
                }, p => loadActivations(p));
            } else {
                $('#pagination').innerHTML = '';
            }
        } catch (e) {
            console.error(e);
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-red-500">${UI.escapeHtml(e.message)}</td></tr>`;
        }
    }

    function computeStats(items) {
        $('#stat-activees').textContent = items.filter(a => a.statut === 'ACTIVEE').length;
        $('#stat-mois').textContent = items.filter(a => {
            if (!a.activated_at) return false;
            const d = new Date(a.activated_at);
            const now = new Date();
            return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
        }).length;
        $('#stat-attente').textContent = items.filter(a => a.statut !== 'ACTIVEE').length;
    }

    function renderTable(items) {
        const tbody = $('#table-body');

        if (!items.length) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-10 text-center text-gray-400">
                <i class="fas fa-inbox text-3xl mb-2 block"></i>Aucune activation SIM</td></tr>`;
            return;
        }

        tbody.innerHTML = items.map(a => `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono text-xs">${UI.escapeHtml(a.reference)}</td>
                <td class="px-4 py-3 font-medium">${UI.escapeHtml(a.identifiant)}</td>
                <td class="px-4 py-3 text-gray-700">${UI.escapeHtml(a.client?.nom_complet || '—')}</td>
                <td class="px-4 py-3 text-gray-600">${UI.escapeHtml(a.agent_dsa?.prenom ? a.agent_dsa.prenom + ' ' + a.agent_dsa.nom : '—')}</td>
                <td class="px-4 py-3 text-gray-600 text-xs">
                    ${a.activated_at ? UI.escapeHtml(new Date(a.activated_at).toLocaleString('fr-FR')) : '—'}
                </td>
                <td class="px-4 py-3 text-center">
                    ${a.preuve_fichier_ref
                        ? '<i class="fas fa-check-circle text-emerald-500"></i>'
                        : '<i class="fas fa-times-circle text-red-500"></i>'}
                </td>
                <td class="px-4 py-3 text-right">
                    ${a.preuve_fichier_ref ? `
                        <button data-action="download" data-id="${a.id}"
                                class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100">
                            <i class="fas fa-download"></i>
                        </button>
                    ` : ''}
                </td>
            </tr>
        `).join('');
    }

    async function loadClients() {
        try {
            const res = await API.get('/clients?per_page=500');
            if (!res.ok) return;
            const items = res.data?.data || res.data || [];
            const select = $('#sim-client');
            items.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = `${c.nom_complet} (${c.telephone || '—'})`;
                select.appendChild(opt);
            });
        } catch (e) { console.error(e); }
    }

    async function loadProduitsSim() {
        try {
            const res = await API.get('/produits?per_page=500&gere_imei=1');
            if (!res.ok) return;
            const items = res.data?.data || res.data || [];
            const select = $('#sim-produit');
            items.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.nom;
                select.appendChild(opt);
            });
        } catch (e) { console.error(e); }
    }

    function openModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('hidden');
        el.classList.add('flex');
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.add('hidden');
        el.classList.remove('flex');
    }

    async function submitActivation(e) {
        e.preventDefault();
        const btn = $('#btn-activer');
        setLoading(btn, true);

        try {
            const fd = new FormData();
            fd.append('client_id',   $('#sim-client').value);
            fd.append('produit_id',  $('#sim-produit').value);
            fd.append('identifiant', $('#sim-identifiant').value.trim());
            fd.append('notes',       $('#sim-notes').value.trim());

            const fichier = $('#sim-preuve').files[0];
            if (!fichier) {
                toast('danger', 'La photo de preuve est obligatoire.');
                setLoading(btn, false);
                return;
            }
            fd.append('preuve', fichier);

            const token = localStorage.getItem('auth_token');
            const res = await fetch(`${window.APP_BASE}/api/dsa/activations-sim`, {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
                body: fd,
            });

            setLoading(btn, false);

            if (res.ok) {
                toast('success', 'SIM activée avec succès !');
                closeModal('sim-modal');
                $('#sim-form').reset();
                loadActivations(1);
            } else {
                const err = await res.json().catch(() => ({}));
                const msg = err.errors
                    ? Object.values(err.errors).flat().join(' · ')
                    : (err.message || 'Erreur');
                toast('danger', msg);
            }
        } catch (e) {
            setLoading(btn, false);
            console.error(e);
            toast('danger', 'Erreur réseau');
        }
    }

    async function downloadPreuve(id) {
        try {
            const token = localStorage.getItem('auth_token');
            const res = await fetch(`${window.APP_BASE}/api/dsa/activations-sim/${id}/preuve`, {
                headers: { 'Authorization': 'Bearer ' + token },
            });

            if (!res.ok) { toast('danger', 'Téléchargement impossible'); return; }

            const blob = await res.blob();
            const disposition = res.headers.get('content-disposition') || '';
            const match = disposition.match(/filename="?([^"]+)"?/);
            const filename = match ? match[1] : 'preuve_sim_' + id;

            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(url);
        } catch (e) {
            console.error(e);
            toast('danger', 'Erreur réseau');
        }
    }

    function attachEvents() {
        $('#search-input')?.addEventListener('input', UI.debounce(e => {
            state.recherche = e.target.value.trim();
            loadActivations(1);
        }, 400));

        $('#btn-refresh')?.addEventListener('click', () => loadActivations(state.page));

        $('#btn-new')?.addEventListener('click', () => {
            $('#sim-form').reset();
            openModal('sim-modal');
        });

        $('#sim-form')?.addEventListener('submit', submitActivation);

        document.addEventListener('click', e => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            if (btn.dataset.action === 'download') downloadPreuve(btn.dataset.id);
        });

        $$('[data-close-modal]').forEach(b => {
            b.addEventListener('click', () => closeModal(b.dataset.closeModal));
        });

        $('#sim-modal')?.addEventListener('click', e => {
            if (e.target.id === 'sim-modal') closeModal('sim-modal');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();