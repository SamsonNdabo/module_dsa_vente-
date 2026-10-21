/**
 * Client API avec authentification par token (Bearer).
 */
const API = (function () {
    'use strict';

    function resolveBase() {
        if (window.APP_BASE && /^https?:\/\//.test(window.APP_BASE)) {
            return window.APP_BASE.replace(/\/+$/, '') + '/api';
        }
        const me = [...document.scripts].find(s => /\/js\/api\.js(\?|$)/.test(s.src));
        if (me && me.src) {
            return me.src.replace(/\/js\/api\.js.*$/, '') + '/api';
        }
        return '/api';
    }

    const BASE = resolveBase();
    console.log('[API] base =', BASE);

    // ─────────────────────────────────────────
    // GESTION DU TOKEN
    // ─────────────────────────────────────────
    const TOKEN_KEY = 'auth_token';

    function getToken() {
        return localStorage.getItem(TOKEN_KEY);
    }

    function setToken(token) {
        if (token) localStorage.setItem(TOKEN_KEY, token);
        else localStorage.removeItem(TOKEN_KEY);
    }

    function clearToken() {
        localStorage.removeItem(TOKEN_KEY);
    }

    // ─────────────────────────────────────────
    // REQUÊTE HTTP
    // ─────────────────────────────────────────
    async function request(method, path, body) {
        const headers = {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };

        const token = getToken();
        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }

        const options = { method, headers };
        if (body !== undefined) options.body = JSON.stringify(body);

        let response;
        try {
            response = await fetch(BASE + path, options);
        } catch (err) {
            return { ok: false, status: 0, data: { message: 'Erreur réseau.' } };
        }

        let data = {};
        const ct = response.headers.get('content-type') || '';
        if (ct.includes('application/json')) {
            data = await response.json().catch(() => ({}));
        }

        // 401 → token expiré ou invalide
        if (response.status === 401) {
            clearToken();
        }

        return { ok: response.ok, status: response.status, data };
    }

    // ─────────────────────────────────────────
    // API PUBLIQUE
    // ─────────────────────────────────────────
    return {
        base()          { return BASE; },
        getToken,
        setToken,
        clearToken,
        isAuthenticated() { return !!getToken(); },

        // Auth
        login(payload)              { return request('POST', '/auth/login', payload); },
        logout()                    { return request('POST', '/auth/logout').finally(() => clearToken()); },
        me()                        { return request('GET',  '/auth/me'); },
        refreshToken(payload)       { return request('POST', '/auth/refresh', payload); },

        // 2FA
        requestEmailCode(payload)   { return request('POST', '/auth/request-email-code', payload); },
        verifyEmailCode(payload)    { return request('POST', '/auth/verify-email-code', payload); },

        // Password reset
        forgotPassword(payload)     { return request('POST', '/auth/forgot-password', payload); },
        resetPassword(payload)      { return request('POST', '/auth/reset-password', payload); },
        verifyResetToken(payload)   { return request('POST', '/auth/verify-reset-token', payload); },
    };
})();