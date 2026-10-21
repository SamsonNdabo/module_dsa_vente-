@extends('layouts.app')

@section('title', 'Partenaires')
@section('page_title', 'Partenaires')

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
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-gray-400 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Inactifs</div>
            <div id="stat-inactifs" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-purple-500 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Produits liés</div>
            <div id="stat-produits" class="text-2xl font-bold text-gray-900">—</div>
        </div>
    </div>

    {{-- Barre d'actions --}}
    <div class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" id="search-input" placeholder="Rechercher un partenaire..."
                       class="w-full pl-10 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
            </div>
            <select id="filter-type" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Tous les types</option>
                <option value="BANQUE">Banque</option>
                <option value="IMF">IMF</option>
                <option value="MOBILE_MONEY">Mobile Money</option>
                <option value="ASSURANCE">Assurance</option>
                <option value="COMMERCIAL">Commercial</option>
                <option value="AUTRE">Autre</option>
            </select>
            <select id="filter-actif" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Tous</option>
                <option value="1">Actifs</option>
                <option value="0">Inactifs</option>
            </select>
            <button id="btn-refresh" class="px-3 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button id="btn-create"
                    class="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-br from-indigo-600 to-indigo-800 rounded-lg shadow-md hover:-translate-y-px transition-all">
                <i class="fas fa-plus mr-1"></i> Nouveau partenaire
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
                        <th class="px-4 py-3">Nom</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Contact</th>
                        <th class="px-4 py-3">Produits</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="table-body" class="divide-y divide-gray-100">
                    <tr><td colspan="7" class="p-10 text-center text-gray-400">
                        <i class="fas fa-spinner fa-spin text-2xl"></i>
                    </td></tr>
                </tbody>
            </table>
        </div>
        <div id="pagination" class="p-4 border-t border-gray-100"></div>
    </div>
</div>

@include('partenaires.modal')
@endsection

@push('scripts')
<script src="{{ asset('js/partenaires.js') }}?v={{ time() }}"></script>
@endpush