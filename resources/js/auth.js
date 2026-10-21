(function () {
    'use strict';

    // ------------------------------------------------------------
    // HELPERS UI
    // ------------------------------------------------------------
    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    /**
     * Affiche un message dans #alerts-container (si présent).
     * Compatible Tailwind v4 (utilise les classes utilitaires).
     */
    function showAlert(type, message, list) {
        const container = $('#alerts-container');
        if (!container) {
            alert(message);
            return;
        }

        // Style par type (Tailwind)
        const styles = {
            success: { box: 'bg-emerald-50 border-emerald-500 text-emerald-800', icon: 'fa-check-circle' },
            danger:  { box: 'bg-red-50 border-red-500 text-red-800',             icon: 'fa-exclamation-circle' },
            warning: { box: 'bg-amber-50 border-amber-500 text-amber-800',       icon: 'fa-exclamation-triangle' },
            info:    { box: 'bg-blue-50 border-blue-500 text-blue-800',          icon: 'fa-info-circle' },
        };

        const cls = type === 'error' ? 'danger' : type;
        const s = styles[cls] || styles.info;

        let listHtml = '';
        if (Array.isArray(list) && list.length) {
            listHtml = '<ul class="list-disc pl-5 mt-2 space-y-1">'
                + list.map(e => `<li>${escapeHtml(e)}</li>`).join('')
                + '</ul>';
        }

        // On garde les classes custom (.alert / .alert-xxx) SI app.css en fournit,
        // sinon on utilise des classes Tailwind pures.
        const html = `
            <div class="flex items-start gap-3 p-4 mb-4 rounded-lg border-l-4 ${s.box}" data-auto-dismiss>
                <i class="fas ${s.icon} mt-0.5 text-lg"></i>
                <div class="flex-1 text-sm">
                    <strong>${escapeHtml(message)}</strong>
                    ${listHtml}
                </div>
            </div>`;
        container.innerHTML = html;

        // Auto-dismiss après 5s
        setTimeout(() => {
            const el = container.querySelector('[data-auto-dismiss]');
            if (!el) return;
            el.style.transition = 'opacity .4s, transform .4s';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-8px)';
            setTimeout(() => el.remove(), 400);
        }, 5000);
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    /**
     * Affiche les erreurs de validation Laravel { errors: { champ: [msg] } }
     */
    function showValidationErrors(errors) {
        if (!errors) return;
        const all = [];
        Object.values(errors).forEach(arr => {
            if (Array.isArray(arr)) all.push(...arr);
            else all.push(arr);
        });
        if (all.length) showAlert('danger', 'Veuillez corriger :', all);
    }

    /**
     * Active/désactive le loader d'un bouton.
     * ⚠️ Tailwind = 'hidden' (pas 'd-none')
     */
    function setLoading(btn, loading) {
        if (!btn) return;
        btn.disabled = !!loading;
        const sp = btn.querySelector('.spinner');
        if (sp) sp.classList.toggle('hidden', !loading);
    }

    /**
     * Récupère le token CSRF (meta ou cookie)
     */
    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
        return m ? decodeURIComponent(m[1]) : '';
    }

    // ------------------------------------------------------------
    // TOGGLE VISIBILITÉ MOT DE PASSE
    // ------------------------------------------------------------
    $$('[data-toggle-password]').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-toggle-password');
            const input = document.getElementById(targetId);
            if (!input) return;
            const icon = btn.querySelector('i');
            const isPwd = input.type === 'password';
            input.type = isPwd ? 'text' : 'password';
            if (icon) {
                icon.classList.toggle('fa-eye', !isPwd);
                icon.classList.toggle('fa-eye-slash', isPwd);
            }
        });
    });

    // ------------------------------------------------------------
    // LOGIN
    // ------------------------------------------------------------
    const loginForm = document.getElementById('form-login');
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = loginForm.querySelector('button[type="submit"]');
            const username = (loginForm.nom_utilisateur.value || '').trim();
            const password = loginForm.mot_de_passe.value;
            const remember = loginForm.remember?.checked || false;

            if (!username || !password) {
                showAlert('danger', 'Veuillez remplir tous les champs.');
                return;
            }

            setLoading(btn, true);
            const res = await API.login({
                nom_utilisateur: username,
                mot_de_passe: password,
                remember: remember,
            });
            setLoading(btn, false);

            // 2FA requise
            if (res.ok && res.data.requires_2fa) {
                sessionStorage.setItem('2fa_email', username);
                window.location.href = '/verify-email-code';
                return;
            }

            // Succès
            if (res.ok && res.data.success) {
                window.location.href = '/dashboard';
                return;
            }

            // Erreurs validation
            if (res.status === 422 && res.data.errors) {
                showValidationErrors(res.data.errors);
                return;
            }

            // Erreur générique
            showAlert('danger', res.data.error || res.data.message || 'Identifiants incorrects.');
        });
    }

    // ------------------------------------------------------------
    // LOGOUT
    // ------------------------------------------------------------
    $$('[data-logout]').forEach(el => {
        el.addEventListener('click', async (e) => {
            e.preventDefault();
            await API.logout();
            window.location.href = '/login';
        });
    });

    // ------------------------------------------------------------
    // FORGOT PASSWORD
    // ------------------------------------------------------------
    const forgotForm = document.getElementById('form-forgot');
    if (forgotForm) {
        forgotForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = forgotForm.querySelector('button[type="submit"]');
            const email = (forgotForm.email.value || '').trim();

            if (!email) {
                showAlert('danger', 'Veuillez saisir votre email.');
                return;
            }

            setLoading(btn, true);
            const res = await API.forgotPassword({ email });
            setLoading(btn, false);

            if (res.ok && res.data.success) {
                showAlert('success', res.data.message || 'Email de réinitialisation envoyé.');
                forgotForm.reset();
            } else {
                if (res.data.errors) showValidationErrors(res.data.errors);
                else showAlert('danger', res.data.error || res.data.message || 'Erreur.');
            }
        });
    }

    // ------------------------------------------------------------
    // RESET PASSWORD
    // ------------------------------------------------------------
    const resetForm = document.getElementById('form-reset');
    if (resetForm) {
        resetForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = resetForm.querySelector('button[type="submit"]');
            const email = (resetForm.email.value || '').trim();
            const token = resetForm.token.value;
            const password = resetForm.password.value;
            const confirmation = resetForm.password_confirmation.value;

            if (password !== confirmation) {
                showAlert('danger', 'Les deux mots de passe ne correspondent pas.');
                return;
            }
            if (password.length < 8) {
                showAlert('danger', 'Le mot de passe doit contenir au moins 8 caractères.');
                return;
            }

            setLoading(btn, true);
            const res = await API.resetPassword({
                email, token,
                password,
                password_confirmation: confirmation,
            });
            setLoading(btn, false);

            if (res.ok && res.data.success) {
                showAlert('success', 'Mot de passe réinitialisé. Redirection...');
                setTimeout(() => window.location.href = '/login', 1500);
            } else {
                if (res.data.errors) showValidationErrors(res.data.errors);
                else showAlert('danger', res.data.error || res.data.message || 'Erreur.');
            }
        });
    }

    // ------------------------------------------------------------
    // VERIFY 2FA CODE
    // ------------------------------------------------------------
    const verifyForm = document.getElementById('form-verify');
    if (verifyForm) {
        verifyForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = verifyForm.querySelector('button[type="submit"]');
            const code = (verifyForm.code.value || '').trim();
            const email = sessionStorage.getItem('2fa_email');

            if (!email) {
                showAlert('danger', 'Session expirée. Reconnectez-vous.');
                setTimeout(() => window.location.href = '/login', 1500);
                return;
            }
            if (!/^\d{6}$/.test(code)) {
                showAlert('danger', 'Le code doit contenir 6 chiffres.');
                return;
            }

            setLoading(btn, true);
            const res = await API.verifyEmailCode({ email, code });
            setLoading(btn, false);

            if (res.ok && res.data.success) {
                sessionStorage.removeItem('2fa_email');
                window.location.href = '/dashboard';
            } else {
                if (res.data.errors) showValidationErrors(res.data.errors);
                else showAlert('danger', res.data.message || 'Code invalide.');
                verifyForm.code.value = '';
                verifyForm.code.focus();
            }
        });
    }

    // ------------------------------------------------------------
    // RENVOYER UN CODE 2FA
    // ------------------------------------------------------------
    const resendBtn = document.getElementById('btn-resend-code');
    if (resendBtn) {
        resendBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            const email = sessionStorage.getItem('2fa_email');
            if (!email) {
                showAlert('danger', 'Session expirée. Reconnectez-vous.');
                setTimeout(() => window.location.href = '/login', 1500);
                return;
            }

            setLoading(resendBtn, true);
            const res = await API.requestEmailCode({ email });
            setLoading(resendBtn, false);

            if (res.ok && res.data.success) {
                showAlert('success', res.data.message || 'Nouveau code envoyé.');
            } else {
                showAlert('danger', res.data.error || res.data.message || 'Erreur.');
            }
        });
    }

    // ------------------------------------------------------------
    // OTP : auto-submit à 6 chiffres
    // ------------------------------------------------------------
    const otp = document.getElementById('otp-code');
    if (otp) {
        otp.addEventListener('input', (e) => {
            e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
            if (e.target.value.length === 6) {
                const form = e.target.closest('form');
                if (form) form.requestSubmit();
            }
        });
    }

    // ------------------------------------------------------------
    // EXPO (utile pour debug dans la console)
    // ------------------------------------------------------------
    window.AuthUI = { showAlert, showValidationErrors, setLoading, csrfToken };
})();