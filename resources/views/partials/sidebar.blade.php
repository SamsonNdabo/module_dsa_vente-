<aside class="w-64 bg-gradient-to-b from-slate-900 to-gray-900 text-slate-300 flex flex-col
              fixed inset-y-0 left-0 z-30 shadow-xl">

    {{-- ═══════════════════════════════════════════ --}}
    {{-- HEADER (fixe en haut)                       --}}
    {{-- ═══════════════════════════════════════════ --}}
    <div class="flex items-center gap-2 px-6 py-5 border-b border-white/10 text-white font-bold text-lg flex-shrink-0">
        <i class="fas fa-cubes text-indigo-400 text-xl"></i>
        <span>{{ config('app.name', 'Starehe') }}</span>
    </div>

    {{-- ═══════════════════════════════════════════ --}}
    {{-- NAV (scrollable au milieu)                  --}}
    {{-- ═══════════════════════════════════════════ --}}
    <nav class="flex-1 p-3 overflow-y-auto" id="sidebar-nav">

        {{-- Dashboard (toujours visible) --}}
        <a href="{{ url('/dashboard') }}"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                  {{ request()->is('dashboard*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
            <i class="fas fa-tachometer-alt w-5 text-center"></i>
            <span>Tableau de bord</span>
        </a>

        {{-- ═══════════════════════════════════════════ --}}
        {{-- SECTION ADMINISTRATION                      --}}
        {{-- ═══════════════════════════════════════════ --}}
        <div class="sidebar-section hidden" data-section="administration">
            <div class="text-[0.7rem] uppercase tracking-wider text-slate-400 font-bold px-3 py-2 mt-3">
                Administration
            </div>
            <a href="{{ url('/utilisateurs') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('utilisateurs*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-users w-5 text-center"></i>
                <span>Utilisateurs</span>
            </a>
            <a href="{{ url('/roles') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('roles*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-user-tag w-5 text-center"></i>
                <span>Rôles</span>
            </a>
            <a href="{{ url('/permissions') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('permissions*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-key w-5 text-center"></i>
                <span>Permissions</span>
            </a>
        </div>

        {{-- ═══════════════════════════════════════════ --}}
        {{-- SECTION GESTION                             --}}
        {{-- ═══════════════════════════════════════════ --}}
        <div class="sidebar-section hidden" data-section="gestion">
            <div class="text-[0.7rem] uppercase tracking-wider text-slate-400 font-bold px-3 py-2 mt-3">
                Gestion
            </div>
            <a href="{{ url('/succursales') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('succursales*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-building w-5 text-center"></i>
                <span>Succursales</span>
            </a>
        </div>

        {{-- ═══════════════════════════════════════════ --}}
        {{-- SECTION DSA — FORCE DE VENTE                --}}
        {{-- Ordre logique du parcours métier :          --}}
        {{-- Agents → Clients → KYC → Demandes →         --}}
        {{-- Campagnes → Objectifs → Zones →             --}}
        {{-- Produits → Partenaires → Règles KYC         --}}
        {{-- ═══════════════════════════════════════════ --}}
        <div class="sidebar-section hidden" data-section="dsa">
            <div class="text-[0.7rem] uppercase tracking-wider text-slate-400 font-bold px-3 py-2 mt-3">
                DSA — Force de vente
            </div>

            {{-- Force de vente --}}
            <a href="{{ url('/agents') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('agents*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-user-tie w-5 text-center"></i>
                <span>Agents DSA</span>
            </a>
            <a href="{{ url('/zones') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('zones*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-map-marked-alt w-5 text-center"></i>
                <span>Zones</span>
            </a>

            {{-- Parcours client --}}
            <a href="{{ url('/clients-dsa') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('clients-dsa*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-user-friends w-5 text-center"></i>
                <span>Clients DSA</span>
            </a>
            <a href="{{ url('/kyc-dossiers') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('kyc-dossiers*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-id-card w-5 text-center"></i>
                <span>Dossiers KYC</span>
            </a>
            <a href="{{ url('/demandes') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('demandes*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-tasks w-5 text-center"></i>
                <span>Demandes</span>
            </a>
            
            <a href="{{ url('/stock-dsa') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('stock-dsa*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-boxes w-5 text-center"></i>
                <span>Stock DSA</span>
            </a>

            {{-- 🆕 Souscription client --}}
            <a href="{{ url('/souscriptions') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('souscriptions*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-shopping-basket w-5 text-center"></i>
                <span>Souscription</span>
            </a>

            {{-- Pilotage commercial --}}
            <!-- <a href="{{ url('/campagnes') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('campagnes*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-bullhorn w-5 text-center"></i>
                <span>Campagnes</span>
            </a> -->
            <!-- <a href="{{ url('/objectifs') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('objectifs*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-bullseye w-5 text-center"></i>
                <span>Objectifs mesurables</span>
            </a> -->

            {{-- Catalogue --}}
            <!-- <a href="{{ url('/produits-dsa') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('produits-dsa*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-box-open w-5 text-center"></i>
                <span>Produits DSA</span>
            </a> -->
            <a href="{{ url('/partenaires') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('partenaires*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-handshake w-5 text-center"></i>
                <span>Partenaires</span>
            </a>

            {{-- Configuration KYC --}}
            <a href="{{ url('/regles-kyc') }}"
               class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium mb-1 transition
                      {{ request()->is('regles-kyc*') ? 'bg-gradient-to-r from-indigo-600 to-indigo-800 text-white shadow-lg shadow-indigo-500/30' : 'hover:bg-white/5 hover:text-white' }}">
                <i class="fas fa-shield-alt w-5 text-center"></i>
                <span>Règles KYC</span>
            </a>
        </div>

    </nav>

    {{-- ═══════════════════════════════════════════ --}}
    {{-- FOOTER (fixe en bas)                        --}}
    {{-- ═══════════════════════════════════════════ --}}
    <div class="p-4 border-t border-white/10 text-xs text-slate-400 flex-shrink-0">
        v1.0 · {{ date('Y') }}
    </div>
</aside>