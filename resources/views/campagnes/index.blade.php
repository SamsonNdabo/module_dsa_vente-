@extends('layouts.app')

@section('title', 'Campagnes')
@section('page_title', 'Campagnes')

@section('content')
<div class="space-y-5">

    {{-- Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl shadow-sm border-l-4 border-indigo-600 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-xs text-gray-500 uppercase font-semibold">Total</div>
                    <div id="stat-total" class="text-2xl font-bold text-gray-900 mt-1">—</div>
                </div>
                <div class="w-12 h-12 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center">
                    <i class="fas fa-bullhorn text-xl"></i>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border-l-4 border-emerald-500 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-xs text-gray-500 uppercase font-semibold">Actives</div>
                    <div id="stat-actives" class="text-2xl font-bold text-gray-900 mt-1">—</div>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center">
                    <i class="fas fa-play-circle text-xl"></i>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border-l-4 border-amber-500 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-xs text-gray-500 uppercase font-semibold">Planifiées</div>
                    <div id="stat-planifiees" class="text-2xl font-bold text-gray-900 mt-1">—</div>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center">
                    <i class="fas fa-clock text-xl"></i>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border-l-4 border-gray-500 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-xs text-gray-500 uppercase font-semibold">Terminées</div>
                    <div id="stat-terminees" class="text-2xl font-bold text-gray-900 mt-1">—</div>
                </div>
                <div class="w-12 h-12 rounded-xl bg-gray-100 text-gray-600 flex items-center justify-center">
                    <i class="fas fa-flag-checkered text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    {{-- Filtres --}}
    <div class="bg-white rounded-2xl shadow-sm p-4 border border-gray-100">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" id="search-input" placeholder="Code, nom..."
                       class="w-full pl-10 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
            </div>
            <select id="filter-partenaire" class="px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                <option value="">Tous partenaires</option>
            </select>
            <select id="filter-statut" class="px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                <option value="">Tous statuts</option>
                <option value="PLANIFIEE">Planifiée</option>
                <option value="ACTIVE">Active</option>
                <option value="SUSPENDUE">Suspendue</option>
                <option value="TERMINEE">Terminée</option>
                <option value="ANNULEE">Annulée</option>
            </select>
            <button id="btn-refresh" class="px-3 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors" title="Rafraîchir">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button id="btn-create"
                    class="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-br from-indigo-600 to-indigo-800 rounded-lg shadow-md hover:-translate-y-px transition-all">
                <i class="fas fa-plus mr-1"></i> Nouvelle campagne
            </button>
        </div>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl shadow-sm overflow-hidden border border-gray-100">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-600 font-bold">
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Nom</th>
                        <th class="px-4 py-3">Partenaire</th>
                        <th class="px-4 py-3">Période</th>
                        <th class="px-4 py-3">Agents</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="table-body" class="divide-y divide-gray-100">
                    <tr>
                        <td colspan="7" class="p-10 text-center text-gray-400">
                            <i class="fas fa-spinner fa-spin text-2xl"></i>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div id="pagination" class="p-4 border-t border-gray-100"></div>
    </div>
</div>
@endsection

@include('campagnes.modal')

@push('scripts')
<script src="{{ asset('js/campagnes.js') }}?v={{ time() }}"></script>
@endpush