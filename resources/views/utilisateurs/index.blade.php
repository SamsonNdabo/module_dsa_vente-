@extends('layouts.app')

@section('title', 'Utilisateurs')
@section('page_title', 'Gestion des utilisateurs')

@section('content')
<div class="space-y-5">

    {{-- Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-indigo-600 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Total</div>
            <div id="stat-total" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-emerald-500 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Actifs</div>
            <div id="stat-actifs" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-gray-400 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Inactifs</div>
            <div id="stat-inactifs" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-red-500 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Verrouillés</div>
            <div id="stat-verrouilles" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-amber-500 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">En ligne</div>
            <div id="stat-enligne" class="text-2xl font-bold text-gray-900">—</div>
        </div>
    </div>

    {{-- Barre d'actions --}}
    <div class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" id="search-input" placeholder="Rechercher un utilisateur..."
                       class="w-full pl-10 pr-4 py-2 text-sm border border-gray-200 rounded-lg
                              focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/15 focus:outline-none">
            </div>

            <select id="filter-statut" class="px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                <option value="">Tous les statuts</option>
                <option value="ACTIF">Actifs</option>
                <option value="INACTIF">Inactifs</option>
            </select>

            <select id="filter-role" class="px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                <option value="">Tous les rôles</option>
            </select>

            <button id="btn-refresh"
                    class="px-3 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition">
                <i class="fas fa-sync-alt"></i>
            </button>

            <button id="btn-create"
                    class="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-br from-indigo-600 to-indigo-800 rounded-lg
                           shadow-md shadow-indigo-500/25 hover:from-indigo-700 hover:to-indigo-900 hover:-translate-y-px transition-all">
                <i class="fas fa-plus mr-1"></i> Nouvel utilisateur
            </button>
        </div>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-600 font-bold">
                        <th class="px-4 py-3">Utilisateur</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Rôle</th>
                        <th class="px-4 py-3">Succursale</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3">2FA</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="table-body" class="divide-y divide-gray-100">
                    <tr><td colspan="7" class="p-10 text-center text-gray-400">
                        <i class="fas fa-spinner fa-spin text-2xl mb-2"></i><br>Chargement...
                    </td></tr>
                </tbody>
            </table>
        </div>
        <div id="pagination" class="p-4 border-t border-gray-100"></div>
    </div>
</div>

@include('utilisateurs.modal')
@endsection

@push('scripts')
<script src="{{ asset('js/utilisateurs.js') }}?v={{ time() }}"></script>
@endpush