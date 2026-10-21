/**
 * Helpers partagés pour toutes les pages.
 */
window.UI = (function () {
    'use strict';

    // ─────────────────────────────────────────
    // TOAST
    // ─────────────────────────────────────────
    function toast(type, message, duration = 4000) {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'fixed top-4 right-4 z-[9999] flex flex-col gap-2 pointer-events-none';
            document.body.appendChild(container);
        }

        const styles = {
            success: 'bg-emerald-50 border-emerald-500 text-emerald-800',
            danger:  'bg-red-50 border-red-500 text-red-800',
            warning: 'bg-amber-50 border-amber-500 text-amber-800',
            info:    'bg-blue-50 border-blue-500 text-blue-800',
        };
        const icons = {
            success: 'fa-check-circle',
            danger:  'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info:    'fa-info-circle',
        };

        const el = document.createElement('div');
        el.className = `flex items-start gap-3 p-4 rounded-lg border-l-4 shadow-lg pointer-events-auto
                        ${styles[type] || styles.info} min-w-[280px] max-w-md`;
        el.innerHTML = `
            <i class="fas ${icons[type] || icons.info} mt-0.5"></i>
            <div class="flex-1 text-sm">${escapeHtml(message)}</div>
            <button class="opacity-60 hover:opacity-100" onclick="this.parentElement.remove()">
                <i class="fas fa-times text-xs"></i>
            </button>`;
        container.appendChild(el);

        setTimeout(() => {
            el.style.transition = 'opacity .3s, transform .3s';
            el.style.opacity = '0';
            el.style.transform = 'translateX(20px)';
            setTimeout(() => el.remove(), 300);
        }, duration);
    }

    // ─────────────────────────────────────────
    // CONFIRM DIALOG
    // ─────────────────────────────────────────
    function confirm(message, onConfirm, opts = {}) {
        const title = opts.title || 'Confirmation';
        const confirmText = opts.confirmText || 'Confirmer';
        const danger = opts.danger !== false;

        const backdrop = document.createElement('div');
        backdrop.className = 'fixed inset-0 z-[10000] bg-black/50 flex items-center justify-center p-4';
        backdrop.innerHTML = `
            <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-6">
                <div class="flex items-start gap-4 mb-4">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0
                                ${danger ? 'bg-red-100 text-red-600' : 'bg-blue-100 text-blue-600'}">
                        <i class="fas ${danger ? 'fa-exclamation-triangle' : 'fa-question-circle'} text-xl"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-bold text-gray-900 mb-1">${escapeHtml(title)}</h3>
                        <p class="text-sm text-gray-600">${escapeHtml(message)}</p>
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <button data-cancel class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition">
                        Annuler
                    </button>
                    <button data-confirm class="px-4 py-2 text-sm font-medium text-white rounded-lg transition
                                ${danger ? 'bg-red-600 hover:bg-red-700' : 'bg-indigo-600 hover:bg-indigo-700'}">
                        ${escapeHtml(confirmText)}
                    </button>
                </div>
            </div>`;

        document.body.appendChild(backdrop);
        backdrop.querySelector('[data-cancel]').addEventListener('click', () => backdrop.remove());
        backdrop.querySelector('[data-confirm]').addEventListener('click', () => {
            backdrop.remove();
            onConfirm();
        });
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) backdrop.remove();
        });
    }

    // ─────────────────────────────────────────
    // MODAL
    // ─────────────────────────────────────────
    function openModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('hidden');
        requestAnimationFrame(() => el.querySelector('.modal-content')?.classList.remove('scale-95'));
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.querySelector('.modal-content')?.classList.add('scale-95');
        setTimeout(() => el.classList.add('hidden'), 150);
    }

    // ─────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────
    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function debounce(fn, delay = 300) {
        let t;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    function formatDate(date, opts = {}) {
        if (!date) return '—';
        const d = new Date(date);
        if (isNaN(d)) return '—';
        const { time = false } = opts;
        return d.toLocaleDateString('fr-FR', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            ...(time ? { hour: '2-digit', minute: '2-digit' } : {})
        });
    }

    function initials(name) {
        if (!name) return '?';
        return name.split(' ').map(w => w[0]).filter(Boolean).slice(0, 2).join('').toUpperCase();
    }

    function setLoading(btn, loading) {
        if (!btn) return;
        btn.disabled = !!loading;
        const sp = btn.querySelector('.spinner');
        if (sp) sp.classList.toggle('hidden', !loading);
    }

    // ─────────────────────────────────────────
    // PAGINATION
    // ─────────────────────────────────────────
    function renderPagination(container, paginator, onPage) {
        if (!container || !paginator || !paginator.last_page) {
            if (container) container.innerHTML = '';
            return;
        }
        const { current_page, last_page } = paginator;
        if (last_page <= 1) { container.innerHTML = ''; return; }

        let html = '<div class="flex items-center justify-center gap-1 mt-4 flex-wrap">';

        // Prev
        html += `<button data-page="${current_page - 1}" ${current_page === 1 ? 'disabled' : ''}
            class="px-3 py-1.5 text-sm rounded-md border border-gray-200 bg-white
                   ${current_page === 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-50'}">
            <i class="fas fa-chevron-left text-xs"></i></button>`;

        // Pages (avec ellipses)
        const pages = [];
        for (let i = 1; i <= last_page; i++) {
            if (i === 1 || i === last_page || Math.abs(i - current_page) <= 1) {
                pages.push(i);
            } else if (pages[pages.length - 1] !== '...') {
                pages.push('...');
            }
        }

        pages.forEach(p => {
            if (p === '...') {
                html += `<span class="px-2 text-gray-400">...</span>`;
            } else {
                html += `<button data-page="${p}"
                    class="px-3 py-1.5 text-sm rounded-md border
                    ${p === current_page ? 'bg-indigo-600 text-white border-indigo-600' : 'border-gray-200 bg-white hover:bg-gray-50'}">
                    ${p}</button>`;
            }
        });

        // Next
        html += `<button data-page="${current_page + 1}" ${current_page === last_page ? 'disabled' : ''}
            class="px-3 py-1.5 text-sm rounded-md border border-gray-200 bg-white
                   ${current_page === last_page ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-50'}">
            <i class="fas fa-chevron-right text-xs"></i></button>`;

        html += '</div>';
        container.innerHTML = html;

        container.querySelectorAll('[data-page]').forEach(btn => {
            btn.addEventListener('click', () => {
                const p = parseInt(btn.dataset.page);
                if (p >= 1 && p <= last_page && p !== current_page) {
                    onPage(p);
                }
            });
        });
    }

    // ─────────────────────────────────────────
    // EXPORTS
    // ─────────────────────────────────────────
    return {
        toast, confirm, openModal, closeModal,
        escapeHtml, debounce, formatDate, initials, setLoading,
        renderPagination
    };
})();