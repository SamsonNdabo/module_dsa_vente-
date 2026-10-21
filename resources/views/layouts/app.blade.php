<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — {{ config('app.name') }}</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="min-h-screen bg-gray-50 font-sans antialiased">
    <div class="flex min-h-screen">
        @include('partials.sidebar')

        <div class="flex-1 ml-64 flex flex-col min-w-0">
            @include('partials.navbar')

            <main class="flex-1 p-6">
                @yield('content')
            </main>
        </div>
    </div>

    <div id="toast-container" class="fixed top-4 right-4 z-[9999] flex flex-col gap-2 pointer-events-none"></div>

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- VARIABLES GLOBALES                                  --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    <script>
        window.APP_BASE = "{{ url('/') }}";
        window.CSRF_TOKEN = "{{ csrf_token() }}";
    </script>

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- SCRIPTS DE BASE                                     --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    <script src="{{ asset('js/api.js') }}?v={{ time() }}"></script>
    <script src="{{ asset('js/utils.js') }}?v={{ time() }}"></script>
    <script src="{{ asset('js/auth.js') }}?v={{ time() }}"></script>

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- SIDEBAR DYNAMIQUE : charger le rôle depuis le token --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    <script>
    (function () {
        function initSidebar() {
            const nav = document.getElementById('sidebar-nav');
            if (!nav) {
                console.warn('[SIDEBAR] #sidebar-nav introuvable');
                return;
            }

            if (typeof API === 'undefined') {
                console.error('[SIDEBAR] API non chargée');
                return;
            }

            if (!API.isAuthenticated()) {
                console.warn('[SIDEBAR] Pas de token, redirection login');
                window.location.href = window.APP_BASE + '/login';
                return;
            }

            API.me().then(function (res) {
                if (!res.ok) {
                    console.warn('[SIDEBAR] me() KO, redirection login');
                    API.clearToken();
                    window.location.href = window.APP_BASE + '/login';
                    return;
                }

                const user = res.data.user || res.data;
                const role = (user?.role?.code || '').trim().toUpperCase();

                console.log('[SIDEBAR] role =', role);

                // SUPER_ADMIN voit tout
                if (role === 'SUPER_ADMIN') {
                    document.querySelectorAll('[data-section]').forEach(function (el) {
                        el.classList.remove('hidden');
                    });
                    console.log('[SIDEBAR] toutes sections affichées');
                    return;
                }

                // Autres rôles
                // Dans le script sidebar, remplace ACCESS par :
                const ACCESS = {
                    'administration': ['AUDITEUR', 'CHEF_OPERATIONS'],
                    'gestion':        ['CHEF_OPERATIONS', 'COMPTABLE_HOLDING'],
                    'dsa':            ['SUPER_ADMIN', 'ADMIN_DSA', 'SUPERVISEUR_DSA', 'CHEF_OPERATIONS', 'AUDITEUR'],
                };

                Object.entries(ACCESS).forEach(function (entry) {
                    const section = entry[0];
                    const roles = entry[1];
                    const el = document.querySelector('[data-section="' + section + '"]');
                    if (el && roles.indexOf(role) !== -1) {
                        el.classList.remove('hidden');
                    }
                });
            }).catch(function (err) {
                console.error('[SIDEBAR] erreur me():', err);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initSidebar);
        } else {
            initSidebar();
        }
    })();
    </script>

    @stack('scripts')
</body>
</html>