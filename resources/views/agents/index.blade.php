@extends('layouts.app')

@section('title', 'Agents DSA')
@section('page_title', 'Agents DSA')

@section('content')
<div class="space-y-5">

    {{-- Filtres --}}
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

    {{-- Table --}}
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

{{-- MODAL Agent --}}
<div id="agent-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto" style="display: none;">
    <div class="modal-content bg-white rounded-xl shadow-2xl max-w-3xl w-full my-8 transition-transform scale-95">

        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div>
                    <h3 id="modal-title" class="text-lg font-bold text-gray-900">Nouvel agent DSA</h3>
                    <p class="text-xs text-gray-500">Agent + compte POS automatique</p>
                </div>
            </div>
            <button type="button" data-close-modal="agent-modal"
                    class="text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg w-9 h-9 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="agent-form" class="p-5 space-y-4 max-h-[75vh] overflow-y-auto">
            <input type="hidden" id="agent-id">

            {{-- ═══ IDENTITÉ AGENT ═══ --}}
            <div>
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-user text-indigo-500 mr-1"></i> Identité
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Prénom *</label>
                        <input type="text" id="prenom" required maxlength="100"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nom *</label>
                        <input type="text" id="nom" required maxlength="100"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Sexe *</label>
                        <select id="sexe" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="M">Masculin</option>
                            <option value="F">Féminin</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Téléphone</label>
                        <input type="text" id="telephone" maxlength="30"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Date embauche *</label>
                        <input type="date" id="date_embauche" required
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Email POS *</label>
                        <input type="email" id="email" required maxlength="255"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                        <p class="text-xs text-gray-500 mt-1">Sert d'identifiant de connexion POS.</p>
                    </div>
                </div>
            </div>

            {{-- ═══ ZONE (obligatoire) ═══ --}}
            <div class="border-t pt-4">
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-map-marker-alt text-indigo-500 mr-1"></i> Rattachement
                </h4>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Zone *</label>
                    <select id="zone_id" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">— Sélectionner une zone —</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-info-circle"></i> Le superviseur est automatiquement déduit de la zone.
                    </p>
                </div>
            </div>

            {{-- ═══ ADRESSE ═══ --}}
            <div class="border-t pt-4">
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-home text-indigo-500 mr-1"></i> Adresse
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Province *</label>
                        <input type="text" id="province" required maxlength="100"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Ville *</label>
                        <input type="text" id="ville" required maxlength="100"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Commune</label>
                        <input type="text" id="commune" maxlength="100"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    </div>
                </div>
                <div class="mt-4">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Adresse</label>
                    <input type="text" id="adresse" maxlength="255"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
            </div>

            {{-- ═══ PIÈCE D'IDENTITÉ ═══ --}}
            <div class="border-t pt-4">
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-id-card text-indigo-500 mr-1"></i> Pièce d'identité
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Type pièce *</label>
                        <select id="piece_identite_type" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="CARTE_IDENTITE">Carte d'identité</option>
                            <option value="PASSEPORT">Passeport</option>
                            <option value="CARTE_ELECTEUR">Carte électeur</option>
                            <option value="PERMIS">Permis</option>
                            <option value="AUTRE">Autre</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">N° pièce *</label>
                        <input type="text" id="piece_identite_numero" required maxlength="60"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    </div>
                </div>
            </div>

            {{-- ═══ COMPTE POS (toujours créé) ═══ --}}
            <div class="border-t pt-4">
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-key text-indigo-500 mr-1"></i> Compte POS de l'agent
                </h4>
                <div class="p-3 bg-indigo-50 border border-indigo-200 rounded-lg mb-3">
                    <p class="text-xs text-indigo-800">
                        <i class="fas fa-info-circle mr-1"></i>
                        Un compte POS est automatiquement créé pour chaque agent.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nom d'utilisateur POS</label>
                        <input type="text" id="nom_utilisateur" maxlength="50"
                               pattern="[a-zA-Z0-9._-]+"
                               placeholder="Auto-généré si vide (ex: prenom.nom)"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                        <p class="text-xs text-gray-500 mt-1">Lettres, chiffres, points, tirets, underscores uniquement.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Mot de passe *</label>
                        <input type="password" id="mot_de_passe" required minlength="8"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                        <p class="text-xs text-gray-500 mt-1">Minimum 8 caractères.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Confirmer le mot de passe *</label>
                        <input type="password" id="mot_de_passe_confirmation" required minlength="8"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                </div>
            </div>
        </form>

        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal="agent-modal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                Annuler
            </button>
            <button type="submit" form="agent-form" id="btn-save"
                    class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition-colors">
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