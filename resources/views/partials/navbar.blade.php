<nav class="sticky top-0 z-20 h-16 bg-white border-b border-gray-100
            flex items-center px-6 gap-4 shadow-sm">
    <div class="flex-1">
        <h1 class="text-lg font-bold text-gray-900">@yield('page_title', 'Tableau de bord')</h1>
    </div>

    <div class="relative group">
        <button class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-100 transition">
            <span id="navbar-avatar"
                  class="inline-flex items-center justify-center w-8 h-8 rounded-full
                         bg-gradient-to-br from-indigo-600 to-indigo-800 text-white
                         text-sm font-bold">
                ?
            </span>
            <span id="navbar-name" class="text-sm font-medium text-gray-700 hidden md:inline">
                Chargement...
            </span>
            <i class="fas fa-chevron-down text-xs text-gray-400"></i>
        </button>

        <ul class="absolute right-0 top-full mt-2 w-52 bg-white rounded-lg shadow-lg border border-gray-100
                   p-1 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all">
            <li>
                <a href="{{ route('dashboard') }}"
                   class="flex items-center gap-2 px-3 py-2.5 rounded-md text-sm text-gray-700 hover:bg-gray-100 transition">
                    <i class="fas fa-user text-gray-400"></i> Mon profil
                </a>
            </li>
            <li><div class="h-px bg-gray-100 my-1"></div></li>
            <li>
                <button type="button" data-logout
                        class="w-full flex items-center gap-2 px-3 py-2.5 rounded-md text-sm text-red-600 hover:bg-red-50 transition">
                    <i class="fas fa-sign-out-alt"></i> Déconnexion
                </button>
            </li>
        </ul>
    </div>
</nav>