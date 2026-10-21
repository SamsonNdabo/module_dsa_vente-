(function () {
    'use strict';
    const page = document.getElementById('kyc-dossier-page');
    if (!page) return;

    const demandeId = parseInt(page.dataset.demandeId, 10);
    if (!demandeId) {
        console.error('[KYC-DOSSIER] demandeId manquant');
        return;
    }

    let demande = null;
    let consentements = [];

    const $  = sel => document.querySelector(sel);
    const $$ = sel => [...document.querySelectorAll(sel)];

    // ─────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────
    function safeToast(type, msg) {
        if (window.UI && typeof UI.toast === 'function') {
            UI.toast(type, msg);
        } else {
            console.log(`[${type}]`, msg);
            if (type === 'danger') alert(msg);
        }
    }

    function openModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('hidden');
        el.classList.add('flex');
        el.style.display = 'flex';
        const content = el.querySelector('.modal-content');
        if (content) {
            content.classList.remove('scale-95');
            content.classList.add('scale-100');
        }
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.add('hidden');
        el.classList.remove('flex');
        el.style.display = 'none';
        const content = el.querySelector('.modal-content');
        if (content) {
            content.classList.remove('scale-100');
            content.classList.add('scale-95');
        }
    }

    function setLoading(btn, state) {
        if (!btn) return;
        if (window.UI && typeof UI.setLoading === 'function') {
            UI.setLoading(btn, state);
        } else {
            btn.disabled = state;
            const spinner = btn.querySelector('.spinner');
            if (spinner) spinner.classList.toggle('hidden', !state);
        }
    }

    // ─────────────────────────────────────────────────────────
    // INIT
    // ─────────────────────────────────────────────────────────
    async function init() {
        if (!API.isAuthenticated()) {
            window.location.href = window.APP_BASE + '/login';
            return;
        }
        await loadDossier();
        await Promise.allSettled([loadExigences(), loadConsentements()]);
        attachEvents();
    }

    // ─────────────────────────────────────────────────────────
    // CHARGEMENT : DOSSIER
    // ─────────────────────────────────────────────────────────
    async function loadDossier() {
        const res = await API.get('/dsa/demandes/' + demandeId);
        if (!res.ok) {
            safeToast('danger', 'Dossier introuvable');
            return;
        }
        demande = res.data.data || res.data;
        renderHeader();
        renderPieces();
        renderSignature();
        renderActions();
        updateProgress();
    }

    function renderHeader() {
        $('#d-reference').textContent = demande.reference;
        $('#d-client').textContent = demande.client
            ? `${demande.client.nom_complet} · ${demande.client.code_client || ''}`
            : '—';

        const badge = $('#d-statut');
        const classes = {
            BROUILLON:  'bg-white/20 text-white',
            SOUMIS:     'bg-blue-400/30 text-white',
            A_CORRIGER: 'bg-amber-400/30 text-white',
            VALIDE:     'bg-emerald-400/30 text-white',
            REJETE:     'bg-red-400/30 text-white',
        }[demande.statut] || 'bg-white/20 text-white';
        badge.className = `px-4 py-2 text-sm font-bold rounded-full backdrop-blur ${classes}`;
        badge.textContent = demande.statut;
    }

    function updateProgress() {
        // Pièces
        const nbPieces = demande.kyc_dossier?.pieces?.length ?? 0;
        $('#progress-pieces').textContent = nbPieces;
        $('#progress-pieces-bar').style.width = Math.min(nbPieces * 33, 100) + '%';
        $('#pieces-count').textContent = nbPieces;

        // Signature
        const hasSignature = !!demande.kyc_dossier?.signature_ref;
        $('#progress-signature').textContent = hasSignature ? 'Oui' : 'Non';
        $('#progress-signature-bar').style.width = hasSignature ? '100%' : '0%';

        // Consentements
        $('#progress-consentements').textContent = consentements.length;
        $('#progress-consentements-bar').style.width = Math.min(consentements.length * 50, 100) + '%';
        $('#consentements-count').textContent = consentements.length;
    }

    // ─────────────────────────────────────────────────────────
    // PIÈCES
    // ─────────────────────────────────────────────────────────
    function renderPieces() {
        const el = $('#pieces-list');
        const pieces = demande.kyc_dossier?.pieces || [];

        if (!pieces.length) {
            el.innerHTML = '<div class="text-sm text-gray-500 italic text-center py-4">Aucune pièce déposée.</div>';
            return;
        }

        el.innerHTML = pieces.map(p => {
            const statutBadge = {
                EN_ATTENTE: 'bg-amber-50 text-amber-700 border-amber-200',
                VALIDE:     'bg-emerald-50 text-emerald-700 border-emerald-200',
                REJETE:     'bg-red-50 text-red-700 border-red-200',
            }[p.statut] || 'bg-gray-100 text-gray-600 border-gray-200';

            const isImage = p.mime_type && p.mime_type.startsWith('image/');
            const isPdf = p.mime_type === 'application/pdf';
            const icon = isImage ? 'fa-file-image' : isPdf ? 'fa-file-pdf' : 'fa-file-alt';
            const iconColor = isImage ? 'text-blue-500' : isPdf ? 'text-red-500' : 'text-gray-500';

            return `
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-xl border border-gray-100 hover:border-indigo-200 transition-colors">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <div class="w-10 h-10 rounded-lg bg-white flex items-center justify-center flex-shrink-0 shadow-sm">
                        <i class="fas ${icon} ${iconColor}"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-sm text-gray-900">${UI.escapeHtml(p.type_document)}</div>
                        <div class="text-xs text-gray-500 truncate">
                            ${p.numero ? 'N° ' + UI.escapeHtml(p.numero) : 'Sans numéro'}
                            ${p.date_expiration ? ' · Exp: ' + p.date_expiration : ''}
                            ${p.taille_octets ? ' · ' + (p.taille_octets / 1024).toFixed(0) + ' Ko' : ''}
                        </div>
                        ${p.motif_rejet ? `<div class="text-xs text-red-600 mt-1">❌ ${UI.escapeHtml(p.motif_rejet)}</div>` : ''}
                    </div>
                </div>
                <div class="flex items-center gap-1.5 ml-2">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full border ${statutBadge}">${p.statut}</span>
                    <a href="${window.APP_BASE}/api/dsa/kyc/pieces/${p.id}/telecharger"
                       class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-100 flex items-center justify-center transition-colors"
                       title="Télécharger"
                       onclick="event.preventDefault(); downloadPiece(${p.id});">
                        <i class="fas fa-download text-xs"></i>
                    </a>
                    ${p.statut === 'EN_ATTENTE' ? `
                        <button data-valider="${p.id}" data-statut="VALIDE"
                                class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-100 flex items-center justify-center transition-colors"
                                title="Valider">
                            <i class="fas fa-check text-xs"></i>
                        </button>
                        <button data-valider="${p.id}" data-statut="REJETE"
                                class="w-8 h-8 rounded-lg bg-red-50 text-red-600 hover:bg-red-100 flex items-center justify-center transition-colors"
                                title="Rejeter">
                            <i class="fas fa-times text-xs"></i>
                        </button>
                    ` : ''}
                </div>
            </div>`;
        }).join('');

        el.querySelectorAll('[data-valider]').forEach(btn => {
            btn.addEventListener('click', () => validerPiece(parseInt(btn.dataset.valider, 10), btn.dataset.statut));
        });
    }

    // ─────────────────────────────────────────────────────────
    // TÉLÉCHARGEMENT PIÈCE
    // ─────────────────────────────────────────────────────────
    window.downloadPiece = async function (pieceId) {
        try {
            const token = localStorage.getItem('auth_token');
            const res = await fetch(`${window.APP_BASE}/api/dsa/kyc/pieces/${pieceId}/telecharger`, {
                headers: { 'Authorization': 'Bearer ' + token },
            });

            if (!res.ok) {
                safeToast('danger', 'Téléchargement impossible (' + res.status + ')');
                return;
            }

            const blob = await res.blob();
            const disposition = res.headers.get('content-disposition') || '';
            const match = disposition.match(/filename="?([^"]+)"?/);
            const filename = match ? match[1] : 'piece_' + pieceId;

            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(url);
        } catch (e) {
            console.error('[KYC-DOSSIER] download:', e);
            safeToast('danger', 'Erreur réseau');
        }
    };

    // ─────────────────────────────────────────────────────────
    // SIGNATURE
    // ─────────────────────────────────────────────────────────
    function renderSignature() {
        const el = $('#signature-status');
        const dossier = demande.kyc_dossier;
        if (dossier?.signature_ref) {
            el.innerHTML = `
                <div class="flex items-center justify-center gap-2 p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
                    <i class="fas fa-check-circle text-emerald-600 text-xl"></i>
                    <span class="text-emerald-700 font-semibold">Signature enregistrée</span>
                </div>`;
        } else {
            el.innerHTML = `
                <div class="flex items-center justify-center gap-2 p-4 bg-amber-50 border border-amber-200 rounded-xl">
                    <i class="fas fa-exclamation-triangle text-amber-600 text-xl"></i>
                    <span class="text-amber-700">Aucune signature enregistrée</span>
                </div>`;
        }
    }

    // ─────────────────────────────────────────────────────────
    // ACTIONS WORKFLOW
    // ─────────────────────────────────────────────────────────
    function renderActions() {
        const el = $('#actions-workflow');
        const actions = [];

        if (['BROUILLON', 'A_CORRIGER'].includes(demande.statut)) {
            actions.push({ cible: 'SOUMIS', label: 'Soumettre', color: 'bg-blue-600 hover:bg-blue-700', icon: 'paper-plane' });
        }
        if (demande.statut === 'SOUMIS') {
            actions.push({ cible: 'VALIDE',     label: 'Valider',      color: 'bg-emerald-600 hover:bg-emerald-700', icon: 'check' });
            actions.push({ cible: 'A_CORRIGER', label: 'À corriger',   color: 'bg-amber-600 hover:bg-amber-700',     icon: 'edit' });
            actions.push({ cible: 'REJETE',     label: 'Rejeter',      color: 'bg-red-600 hover:bg-red-700',         icon: 'ban' });
        }

        if (!actions.length) {
            el.innerHTML = '<div class="text-sm text-gray-500 italic text-center py-2">Aucune action disponible.</div>';
            return;
        }

        el.innerHTML = actions.map(a => `
            <button data-transition="${a.cible}"
                    class="w-full px-4 py-2.5 text-sm font-semibold text-white ${a.color} rounded-lg transition-colors flex items-center justify-center gap-2">
                <i class="fas fa-${a.icon}"></i> ${a.label}
            </button>
        `).join('');

        el.querySelectorAll('[data-transition]').forEach(btn => {
            btn.addEventListener('click', () => transitionner(btn.dataset.transition));
        });
    }

    // ─────────────────────────────────────────────────────────
    // EXIGENCES
    // ─────────────────────────────────────────────────────────
    async function loadExigences() {
        if (!demande?.client_dsa_id) return;

        try {
            let partenaireId = demande.partenaire_id;
            if (!partenaireId) {
                const listRes = await API.get(`/dsa/demandes?type=OUVERTURE_COMPTE&client_dsa_id=${demande.client_dsa_id}`);
                if (listRes.ok && listRes.data?.data?.data?.length) {
                    partenaireId = listRes.data.data.data[0].partenaire_id;
                }
            }
            if (!partenaireId) {
                $('#exigences-list').innerHTML = '<div class="text-sm text-gray-500 italic text-center py-2">Aucun partenaire associé.</div>';
                return;
            }

            const res = await API.get(`/dsa/clients/${demande.client_dsa_id}/kyc/exigences?partenaire_id=${partenaireId}`);
            if (!res.ok) return;

            const ev = res.data.evaluation;
            const regles = res.data.regles || [];
            const el = $('#exigences-list');

            if (ev.sans_regle) {
                el.innerHTML = '<div class="text-sm text-amber-600 italic text-center py-2">Aucune règle KYC configurée.</div>';
                return;
            }

            el.innerHTML = regles.map(r => `
                <div class="flex items-center gap-2 p-2 bg-gray-50 rounded-lg text-sm border border-gray-100">
                    <i class="fas fa-file-alt text-indigo-500 text-xs"></i>
                    <span class="flex-1 font-medium text-gray-700">${UI.escapeHtml(r.type_document)}</span>
                    ${r.obligatoire ? '<span class="text-xs bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded font-semibold">Oblig.</span>' : ''}
                    ${r.validite_jours ? `<span class="text-xs text-gray-500">${r.validite_jours}j</span>` : ''}
                </div>
            `).join('');
        } catch (e) {
            console.error('[KYC-DOSSIER] exigences:', e);
        }
    }

    // ─────────────────────────────────────────────────────────
    // CONSENTEMENTS
    // ─────────────────────────────────────────────────────────
    async function loadConsentements() {
        if (!demande?.client_dsa_id) return;

        try {
            const res = await API.get(`/dsa/clients/${demande.client_dsa_id}/consentements`);
            if (!res.ok) return;

            consentements = res.data.data || [];
            const el = $('#consentements-list');

            if (!consentements.length) {
                el.innerHTML = '<div class="text-sm text-gray-500 italic text-center py-2">Aucun consentement enregistré.</div>';
                updateProgress();
                return;
            }

            el.innerHTML = consentements.map(c => `
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-100">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-file-signature ${c.consenti ? 'text-emerald-500' : 'text-red-500'}"></i>
                        <div>
                            <div class="text-sm font-medium text-gray-800">${UI.escapeHtml(c.type_consentement)}</div>
                            <div class="text-xs text-gray-500">
                                ${new Date(c.date_consentement).toLocaleString('fr-FR')} · v${UI.escapeHtml(c.version_formulaire)}
                            </div>
                        </div>
                    </div>
                    <span class="px-2 py-1 text-xs font-semibold rounded-full ${c.consenti ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'}">
                        ${c.consenti ? 'Consenti' : 'Refusé'}
                    </span>
                </div>
            `).join('');
            updateProgress();
        } catch (e) {
            console.error('[KYC-DOSSIER] consentements:', e);
        }
    }

    // ─────────────────────────────────────────────────────────
    // VALIDATION PIÈCE
    // ─────────────────────────────────────────────────────────
    async function validerPiece(pieceId, statut) {
        const motif = statut === 'REJETE' ? prompt('Motif du rejet (obligatoire) :') : null;
        if (statut === 'REJETE' && !motif) return;

        try {
            const res = await API.post(`/dsa/kyc/pieces/${pieceId}/validation`, { statut, motif });
            if (res.ok) {
                safeToast('success', `Pièce ${statut.toLowerCase()}`);
                await loadDossier();
            } else {
                const err = res.data?.errors || res.data?.message;
                safeToast('danger', typeof err === 'object' ? Object.values(err).flat().join(' · ') : (err || 'Erreur'));
            }
        } catch (e) {
            console.error(e);
            safeToast('danger', 'Erreur réseau');
        }
    }

    // ─────────────────────────────────────────────────────────
    // TRANSITIONS
    // ─────────────────────────────────────────────────────────
    async function transitionner(cible) {
        const motif = ['REJETE', 'A_CORRIGER'].includes(cible)
            ? prompt('Motif (obligatoire pour ce statut) :')
            : null;
        if (['REJETE', 'A_CORRIGER'].includes(cible) && !motif) return;

        try {
            const res = await API.post(`/dsa/demandes/${demandeId}/transition`, { statut: cible, motif });
            if (res.ok) {
                safeToast('success', `Statut → ${cible}`);
                await loadDossier();
                await Promise.allSettled([loadExigences(), loadConsentements()]);
            } else {
                const err = res.data?.errors || res.data?.message;
                safeToast('danger', typeof err === 'object' ? Object.values(err).flat().join(' · ') : (err || 'Erreur'));
            }
        } catch (e) {
            console.error(e);
            safeToast('danger', 'Erreur réseau');
        }
    }

    // ─────────────────────────────────────────────────────────
    // ÉVÉNEMENTS
    // ─────────────────────────────────────────────────────────
    function attachEvents() {
        // Ouvrir les modals
        $('#btn-add-piece')?.addEventListener('click', () => {
            $('#piece-form').reset();
            openModal('piece-modal');
        });

        $('#btn-upload-signature')?.addEventListener('click', () => {
            $('#signature-form').reset();
            openModal('signature-modal');
        });

        $('#btn-consentement')?.addEventListener('click', () => {
            $('#consentement-form').reset();
            $('#version_formulaire').value = 'v1.0';
            $('#consenti').checked = true;
            openModal('consentement-modal');
        });

        // ── Fermeture modals : bouton data-close-modal="ID" ──────
        $$('[data-close-modal]').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.closeModal;
                if (id) closeModal(id);
            });
        });

        // ── Fermeture modals : clic backdrop ──────────────────────
        ['#piece-modal', '#signature-modal', '#consentement-modal'].forEach(sel => {
            const el = $(sel);
            if (el) {
                el.addEventListener('click', e => {
                    if (e.target.id === el.id) closeModal(el.id);
                });
            }
        });

        // ── Upload PIÈCE ─────────────────────────────────────────
        $('#piece-form')?.addEventListener('submit', async e => {
            e.preventDefault();
            const btn = $('#btn-save-piece');
            const fd = new FormData();
            fd.append('type_document',   $('#type_document').value);
            fd.append('numero',          $('#numero').value.trim());
            fd.append('date_expiration', $('#date_expiration').value);
            fd.append('fichier',         $('#fichier').files[0]);

            setLoading(btn, true);
            const token = localStorage.getItem('auth_token');
            const res = await fetch(window.APP_BASE + `/api/dsa/demandes/${demandeId}/kyc/pieces`, {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
                body: fd,
            });
            setLoading(btn, false);

            if (res.ok) {
                safeToast('success', 'Pièce ajoutée');
                closeModal('piece-modal');
                await loadDossier();
            } else {
                const data = await res.json().catch(() => ({}));
                const err = data.errors || data.message;
                safeToast('danger', typeof err === 'object' ? Object.values(err).flat().join(' · ') : (err || 'Erreur'));
            }
        });

        // ── Upload SIGNATURE ──────────────────────────────────────
        $('#signature-form')?.addEventListener('submit', async e => {
            e.preventDefault();
            const btn = $('#btn-save-signature');
            const fd = new FormData();
            fd.append('signature', $('#signature_file').files[0]);

            setLoading(btn, true);
            const token = localStorage.getItem('auth_token');
            const res = await fetch(window.APP_BASE + `/api/dsa/demandes/${demandeId}/kyc/signature`, {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
                body: fd,
            });
            setLoading(btn, false);

            if (res.ok) {
                safeToast('success', 'Signature enregistrée');
                closeModal('signature-modal');
                await loadDossier();
            } else {
                const data = await res.json().catch(() => ({}));
                safeToast('danger', data.message || 'Erreur');
            }
        });

        // ── Consentement ──────────────────────────────────────────
        $('#consentement-form')?.addEventListener('submit', async e => {
            e.preventDefault();
            if (!demande?.client_dsa_id) {
                safeToast('danger', 'Client DSA introuvable');
                return;
            }
            const btn = $('#btn-save-consentement');

            const payload = {
                type_consentement:  $('#type_consentement').value,
                version_formulaire: $('#version_formulaire').value,
                texte_consentement: $('#texte_consentement').value,
                consenti:           $('#consenti').checked,
                demande_id:         demandeId,
            };

            setLoading(btn, true);
            const res = await API.post(`/dsa/clients/${demande.client_dsa_id}/consentements`, payload);
            setLoading(btn, false);

            if (res.ok) {
                safeToast('success', 'Consentement enregistré');
                closeModal('consentement-modal');
                await loadConsentements();
            } else {
                const err = res.data?.errors || res.data?.message;
                safeToast('danger', typeof err === 'object' ? Object.values(err).flat().join(' · ') : (err || 'Erreur'));
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();