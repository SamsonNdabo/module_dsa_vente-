@extends('layouts.app')

@section('title', 'Agents DSA')
@section('page_title', 'Agents DSA')

@section('content')
<div class="space-y-5">

    <div class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" id="search-input" placeholder="Rechercher (code, nom, prénom)..."
                       class="w-full pl-10 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
            </div>
            <select id="filter-statut" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Tous statuts</option>
                <option value="ACTIF">Actif</option>
                <option value="SUSPENDU">Suspendu</option>
                <option value="INACTIF">Inactif</option>
                <option value="RESILIE">Résilié</option>
            </select>
            <select id="filter-zone" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Toutes zones</option>
            </select>
            <button id="btn-refresh" class="px-3 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button id="btn-create"
                    class="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-br from-indigo-600 to-indigo-800 rounded-lg shadow-md hover:-translate-y-px transition-all">
                <i class="fas fa-plus mr-1"></i> Nouvel agent
            </button>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-600 font-bold">
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Nom complet</th>
                        <th class="px-4 py-3">Téléphone</th>
                        <th class="px-4 py-3">Zone</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="table-body" class="divide-y divide-gray-100">
                    <tr><td colspan="6" class="p-10 text-center text-gray-400">
                        <i class="fas fa-spinner fa-spin text-2xl"></i>
                    </td></tr>
                </tbody>
            </table>
        </div>
        <div id="pagination" class="p-4 border-t border-gray-100"></div>
    </div>
</div>

{{-- Modal création/édition --}}
<div id="agent-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="modal-content bg-white rounded-xl shadow-2xl max-w-3xl w-full my-8 transition-transform scale-95">
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <h3 id="modal-title" class="text-lg font-bold text-gray-900">Nouvel agent</h3>
            <button type="button" data-close-modal class="text-gray-400 hover:text-gray-700 text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="agent-form" class="p-5 space-y-4">
            <input type="hidden" id="agent-id">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Prénom *</label>
                    <input type="text" id="prenom" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Nom *</label>
                    <input type="text" id="nom" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Sexe *</label>
                    <select id="sexe" required
                            class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="M">Masculin</option>
                        <option value="F">Féminin</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Téléphone *</label>
                    <input type="text" id="telephone" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Email *</label>
                    <input type="email" id="email" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
                <div id="username-field">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Nom d'utilisateur *</label>
                    <input type="text" id="nom_utilisateur"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Province *</label>
                    <input type="text" id="province" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Ville *</label>
                    <input type="text" id="ville" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Commune</label>
                    <input type="text" id="commune"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Type pièce *</label>
                    <select id="piece_identite_type" required
                            class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="CARTE_IDENTITE">Carte d'identité</option>
                        <option value="PASSEPORT">Passeport</option>
                        <option value="CARTE_ELECTEUR">Carte électeur</option>
                        <option value="PERMIS">Permis</option>
                        <option value="AUTRE">Autre</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">N° pièce *</label>
                    <input type="text" id="piece_identite_numero" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Date embauche *</label>
                    <input type="date" id="date_embauche" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Zone</label>
                    <select id="zone_id"
                            class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">— Aucune —</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Succursale</label>
                    <select id="succursale_id"
                            class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">— Aucune —</option>
                    </select>
                </div>
            </div>
        </form>
        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                Annuler
            </button>
            <button type="submit" form="agent-form" id="btn-save"
                    class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Enregistrer
            </button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/agents.js') }}?v={{ time() }}"></script>
@endpush