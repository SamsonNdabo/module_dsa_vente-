@extends('layouts.app')

@section('title', 'Clients DSA')
@section('page_title', 'Clients DSA')

@section('content')
<div class="space-y-5">

    {{-- Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-indigo-600 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Total</div>
            <div id="stat-total" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-emerald-500 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Actifs</div>
            <div id="stat-actifs" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-amber-500 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">KYC validé</div>
            <div id="stat-kyc-valide" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-purple-500 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Liés POS</div>
            <div id="stat-pos" class="text-2xl font-bold text-gray-900">—</div>
        </div>
    </div>

    {{-- Filtres --}}
    <div class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" id="search-input" placeholder="Nom, code, téléphone, email..."
                       class="w-full pl-10 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
            </div>
            <select id="filter-statut" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Tous statuts</option>
                <option value="ACTIF">Actif</option>
                <option value="INACTIF">Inactif</option>
                <option value="BLOQUE">Bloqué</option>
            </select>
            <select id="filter-kyc" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">KYC — Tous</option>
                <option value="AUCUN">Aucun</option>
                <option value="EN_ATTENTE">En attente</option>
                <option value="EN_COURS">En cours</option>
                <option value="VALIDE">Validé</option>
                <option value="REJETE">Rejeté</option>
            </select>
            <select id="filter-agent" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Tous agents</option>
            </select>
            <button id="btn-refresh" class="px-3 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button id="btn-create"
                    class="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-br from-indigo-600 to-indigo-800 rounded-lg shadow-md hover:-translate-y-px transition-all">
                <i class="fas fa-plus mr-1"></i> Nouveau client DSA
            </button>
        </div>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-600 font-bold">
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Nom complet</th>
                        <th class="px-4 py-3">Téléphone</th>
                        <th class="px-4 py-3">Agent</th>
                        <th class="px-4 py-3">KYC</th>
                        <th class="px-4 py-3">POS</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="table-body" class="divide-y divide-gray-100">
                    <tr><td colspan="8" class="p-10 text-center text-gray-400">
                        <i class="fas fa-spinner fa-spin text-2xl"></i>
                    </td></tr>
                </tbody>
            </table>
        </div>
        <div id="pagination" class="p-4 border-t border-gray-100"></div>
    </div>
</div>

@include('clients_dsa.modal')
@endsection

@push('scripts')
<script src="{{ asset('js/clients_dsa.js') }}?v={{ time() }}"></script>
@endpush