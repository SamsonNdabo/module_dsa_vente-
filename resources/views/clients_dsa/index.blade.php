@extends('layouts.app')

@section('title', 'Clients')
@section('page_title', 'Clients')

@section('content')
<div class="space-y-5">

    {{-- ═══ FILTRES ═══ --}}
    <div class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" id="search-input" placeholder="Rechercher (code, nom, téléphone, email)..."
                       class="w-full pl-10 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
            </div>
            <select id="filter-type" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Tous types</option>
                <option value="PERSONNE_PHYSIQUE">Personne physique</option>
                <option value="ENTREPRISE">Entreprise</option>
                <option value="AUTRE">Autre</option>
            </select>
            <select id="filter-statut" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Tous statuts</option>
                <option value="ACTIF">Actif</option>
                <option value="INACTIF">Inactif</option>
                <option value="BLOQUE">Bloqué</option>
            </select>
            <select id="filter-kyc" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Tous KYC</option>
                <option value="AUCUN">Aucun</option>
                <option value="EN_ATTENTE">En attente</option>
                <option value="EN_COURS">En cours</option>
                <option value="VALIDE">Validé</option>
                <option value="REJETE">Rejeté</option>
            </select>
            <select id="filter-zone" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Toutes zones</option>
            </select>
            <button id="btn-refresh" class="px-3 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button id="btn-create"
                    class="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-br from-indigo-600 to-indigo-800 rounded-lg shadow-md hover:-translate-y-px transition-all">
                <i class="fas fa-plus mr-1"></i> Nouveau client
            </button>
        </div>
    </div>

    {{-- ═══ TABLE ═══ --}}
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-600 font-bold">
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Nom complet</th>
                        <th class="px-4 py-3">Téléphone</th>
                        <th class="px-4 py-3">Zone</th>
                        <th class="px-4 py-3">KYC</th>
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

{{-- ═══ MODAL Client ═══ --}}
<div id="client-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto" style="display: none;">
    <div class="modal-content bg-white rounded-xl shadow-2xl max-w-3xl w-full my-8 transition-transform scale-95">

        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center">
                    <i class="fas fa-user-friends"></i>
                </div>
                <div>
                    <h3 id="modal-title" class="text-lg font-bold text-gray-900">Nouveau client</h3>
                    <p class="text-xs text-gray-500">Personne physique, entreprise ou autre</p>
                </div>
            </div>
            <button type="button" data-close-modal="client-modal"
                    class="text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg w-9 h-9 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="client-form" class="p-5 space-y-4 max-h-[75vh] overflow-y-auto">
            <input type="hidden" id="client-id">

            {{-- ═══ TYPE DE CLIENT ═══ --}}
            <div>
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-tag text-indigo-500 mr-1"></i> Type de client
                </h4>
                <select id="type_client" name="type_client" required
                        class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    <option value="PERSONNE_PHYSIQUE">Personne physique</option>
                    <option value="ENTREPRISE">Entreprise</option>
                    <option value="AUTRE">Autre</option>
                </select>
            </div>

            {{-- ═══ IDENTITÉ COMMUNE ═══ --}}
            <div class="border-t pt-4">
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-id-card text-indigo-500 mr-1"></i> Identité
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nom complet / Raison sociale *</label>
                        <input type="text" id="nom_complet" name="nom_complet" required maxlength="255"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Téléphone *</label>
                        <input type="text" id="telephone" name="telephone" required maxlength="30"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Email</label>
                        <input type="email" id="email" name="email" maxlength="255"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Adresse *</label>
                        <input type="text" id="adresse" name="adresse" required maxlength="255"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                </div>
            </div>

            {{-- ═══ BLOC PERSONNE PHYSIQUE ═══ --}}
            <div id="bloc-personne" class="border-t pt-4">
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-user text-indigo-500 mr-1"></i> Informations personnelles
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Sexe *</label>
                        <select id="sexe" name="sexe"
                                class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="">— Sélectionner —</option>
                            <option value="M">Masculin</option>
                            <option value="F">Féminin</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Date de naissance *</label>
                        <input type="date" id="date_naissance" name="date_naissance"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Profession *</label>
                        <input type="text" id="profession" name="profession" maxlength="150"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Activité économique *</label>
                        <input type="text" id="activite_economique" name="activite_economique" maxlength="150"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                </div>
            </div>

            {{-- ═══ BLOC ENTREPRISE ═══ --}}
            <div id="bloc-entreprise" class="border-t pt-4 hidden">
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-building text-indigo-500 mr-1"></i> Informations entreprise
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Raison sociale *</label>
                        <input type="text" id="raison_sociale" name="raison_sociale" maxlength="200"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">N° RC (Registre de commerce) *</label>
                        <input type="text" id="numero_rc" name="numero_rc" maxlength="50"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">N° Impôt</label>
                        <input type="text" id="numero_impot" name="numero_impot" maxlength="50"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Représentant légal *</label>
                        <input type="text" id="representant_legal" name="representant_legal" maxlength="150"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                </div>
            </div>

            {{-- ═══ PIÈCE D'IDENTITÉ (commun) ═══ --}}
            <div class="border-t pt-4">
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-id-badge text-indigo-500 mr-1"></i> Pièce d'identité
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Type de pièce</label>
                        <select id="piece_identite_type" name="piece_identite_type"
                                class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="">— Aucune —</option>
                            <option value="CARTE_IDENTITE">Carte d'identité</option>
                            <option value="PASSEPORT">Passeport</option>
                            <option value="CARTE_ELECTEUR">Carte électeur</option>
                            <option value="PERMIS">Permis</option>
                            <option value="AUTRE">Autre</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">N° pièce</label>
                        <input type="text" id="piece_identite_numero" name="piece_identite_numero" maxlength="100"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                </div>
            </div>

            {{-- ═══ RATTACHEMENT ═══ --}}
            <div class="border-t pt-4">
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-map-marker-alt text-indigo-500 mr-1"></i> Rattachement
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Agent DSA</label>
                        <select id="agent_dsa_id" name="agent_dsa_id"
                                class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="">— Aucun —</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Zone</label>
                        <select id="zone_id" name="zone_id"
                                class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="">— Aucune —</option>
                        </select>
                    </div>
                </div>
            </div>
        </form>

        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal="client-modal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                Annuler
            </button>
            <button type="submit" form="client-form" id="btn-save"
                    class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition-colors">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Enregistrer
            </button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/clients_dsa.js') }}?v={{ time() }}"></script>
@endpush