@extends('layouts.app')
@section('title', 'Activations SIM')
@section('page_title', 'Activations SIM')

@section('content')
<div class="space-y-5">

    {{-- Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-emerald-500 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Activées</div>
            <div id="stat-activees" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-indigo-600 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Total ce mois</div>
            <div id="stat-mois" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-amber-500 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">À activer (factures récentes)</div>
            <div id="stat-attente" class="text-2xl font-bold text-gray-900">—</div>
        </div>
    </div>

    {{-- Filtres --}}
    <div class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" id="search-input" placeholder="Rechercher (identifiant, référence)..."
                       class="w-full pl-10 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
            </div>
            <button id="btn-refresh" class="px-3 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button id="btn-new" class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
                <i class="fas fa-plus mr-1"></i> Activer une SIM
            </button>
        </div>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-600 font-bold">
                        <th class="px-4 py-3">Référence</th>
                        <th class="px-4 py-3">Identifiant</th>
                        <th class="px-4 py-3">Client</th>
                        <th class="px-4 py-3">Agent</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3 text-center">Preuve</th>
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

{{-- MODAL — Activation SIM (une seule action) --}}
<div id="sim-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full my-8">

        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center">
                    <i class="fas fa-sim-card"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Activer une SIM</h3>
                    <p class="text-xs text-gray-500">Preuve et activation en une seule fois</p>
                </div>
            </div>
            <button type="button" data-close-modal="sim-modal"
                    class="text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg w-9 h-9 flex items-center justify-center">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="sim-form" class="p-5 space-y-4" enctype="multipart/form-data">

            {{-- Client (optionnel : si facture_id fourni, auto-déduit) --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Client *</label>
                <select id="sim-client" required
                        class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg select2">
                    <option value="">— Choisir un client —</option>
                </select>
            </div>

            {{-- Produit SIM --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Produit SIM *</label>
                <select id="sim-produit" required
                        class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    <option value="">— Choisir un produit —</option>
                </select>
            </div>

            {{-- Identifiant --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Numéro SIM / ICCID *</label>
                <input type="text" id="sim-identifiant" required maxlength="50"
                       placeholder="Ex: 89237012345678901234"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                <p class="text-xs text-gray-500 mt-1">Le numéro imprimé sur la carte SIM ou la puce.</p>
            </div>

            {{-- Preuve --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">
                    📷 Photo du fichier opérateur *
                </label>
                <input type="file" id="sim-preuve" required accept="image/*,application/pdf"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <p class="text-xs text-gray-500 mt-1">JPG, PNG ou PDF. Max 10 Mo.</p>
            </div>

            {{-- Notes --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Notes (optionnel)</label>
                <textarea id="sim-notes" rows="2" maxlength="1000"
                          class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg"></textarea>
            </div>
        </form>

        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal="sim-modal"
                    class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                Annuler
            </button>
            <button type="submit" form="sim-form" id="btn-activer"
                    class="px-4 py-2 text-sm font-semibold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Activer la SIM
            </button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/activations_sim.js') }}?v={{ time() }}"></script>
@endpush