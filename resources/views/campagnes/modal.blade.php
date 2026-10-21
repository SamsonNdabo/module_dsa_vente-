<div id="campagne-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto" style="display: none;">
    <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-3xl w-full my-8 transition-transform scale-95">

        {{-- ═══════════════════════════════════════════════ --}}
        {{-- HEADER                                          --}}
        {{-- ═══════════════════════════════════════════════ --}}
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center">
                    <i class="fas fa-bullhorn"></i>
                </div>
                <div>
                    <h3 id="modal-title" class="text-lg font-bold text-gray-900">Nouvelle campagne</h3>
                    <p class="text-xs text-gray-500">Campagne commerciale + affectation agents</p>
                </div>
            </div>
            <button type="button" data-close-modal="campagne-modal"
                    class="text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg w-9 h-9 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>

        {{-- ═══════════════════════════════════════════════ --}}
        {{-- FORMULAIRE (contient TOUT)                     --}}
        {{-- ═══════════════════════════════════════════════ --}}
        <form id="campagne-form" class="p-5 space-y-4 max-h-[75vh] overflow-y-auto">
            <input type="hidden" id="campagne-id">

            {{-- ─── INFOS CAMPAGNE ────────────────────── --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Partenaire *</label>
                    <select id="partenaire_id" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                        <option value="">— Choisir —</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Code</label>
                    <input type="text" id="code" maxlength="30" placeholder="Auto-généré si vide"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Nom *</label>
                    <input type="text" id="nom" required maxlength="200"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Description</label>
                    <textarea id="description" rows="2" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Date début *</label>
                    <input type="date" id="date_debut" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Date fin</label>
                    <input type="date" id="date_fin" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
            </div>

            {{-- ─── BUDGET + DEVISE ────────────────────── --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Budget campagne</label>
                    <input type="number" step="0.01" id="budget" min="0" placeholder="0.00"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Devise</label>
                    <select id="devise_id" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                        <option value="">— Choisir —</option>
                    </select>
                </div>
            </div>

            {{-- ─── STATUT ──────────────────────────────── --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Statut</label>
                <select id="statut" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    <option value="PLANIFIEE">Planifiée</option>
                    <option value="ACTIVE">Active</option>
                    <option value="SUSPENDUE">Suspendue</option>
                    <option value="TERMINEE">Terminée</option>
                    <option value="ANNULEE">Annulée</option>
                </select>
            </div>

            {{-- ─── OBJECTIFS VISÉS ─────────────────────── --}}
            <div class="border-t pt-4">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center">
                            <i class="fas fa-bullseye text-sm"></i>
                        </div>
                        <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide">
                            Objectifs visés
                            <span id="objectifs-types-count" class="ml-2 px-2 py-0.5 text-xs bg-emerald-100 text-emerald-700 rounded-full">0</span>
                        </h4>
                    </div>
                    <button type="button" id="btn-add-objectif-type"
                            class="px-3 py-1.5 text-xs font-semibold text-emerald-600 bg-emerald-50 rounded-lg hover:bg-emerald-100 transition-colors">
                        <i class="fas fa-plus mr-1"></i> Ajouter un type
                    </button>
                </div>

                {{-- Checkboxes des types standard --}}
                <div id="types-checkboxes" class="grid grid-cols-2 sm:grid-cols-3 gap-2 mb-3">
                    <label class="flex items-center gap-2 p-2 bg-white rounded-lg border border-gray-200 cursor-pointer hover:bg-emerald-50 transition-colors">
                        <input type="checkbox" data-type="MONTANT" class="w-4 h-4 rounded accent-emerald-600 type-checkbox">
                        <span class="text-sm">Montant</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 bg-white rounded-lg border border-gray-200 cursor-pointer hover:bg-emerald-50 transition-colors">
                        <input type="checkbox" data-type="VOLUME" class="w-4 h-4 rounded accent-emerald-600 type-checkbox">
                        <span class="text-sm">Volume</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 bg-white rounded-lg border border-gray-200 cursor-pointer hover:bg-emerald-50 transition-colors">
                        <input type="checkbox" data-type="CLIENTS" class="w-4 h-4 rounded accent-emerald-600 type-checkbox">
                        <span class="text-sm">Clients</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 bg-white rounded-lg border border-gray-200 cursor-pointer hover:bg-emerald-50 transition-colors">
                        <input type="checkbox" data-type="COMPTES" class="w-4 h-4 rounded accent-emerald-600 type-checkbox">
                        <span class="text-sm">Comptes</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 bg-white rounded-lg border border-gray-200 cursor-pointer hover:bg-emerald-50 transition-colors">
                        <input type="checkbox" data-type="VISITES" class="w-4 h-4 rounded accent-emerald-600 type-checkbox">
                        <span class="text-sm">Visites</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 bg-white rounded-lg border border-gray-200 cursor-pointer hover:bg-emerald-50 transition-colors">
                        <input type="checkbox" data-type="PRODUIT" class="w-4 h-4 rounded accent-emerald-600 type-checkbox">
                        <span class="text-sm">Produit</span>
                    </label>
                </div>

                {{-- Mini-formulaire d'ajout libre (caché par défaut) --}}
                <div id="custom-objectif-form" class="hidden p-3 mb-3 bg-blue-50 rounded-lg border border-blue-200 space-y-2">
                    <div class="grid grid-cols-3 gap-2">
                        <input type="text" id="custom-libelle" placeholder="Nom de l'objectif" maxlength="150"
                               class="col-span-2 px-2 py-1 text-sm border border-blue-300 rounded">
                        <input type="text" id="custom-unite" placeholder="Unité (CDF, appels…)" maxlength="20"
                               class="px-2 py-1 text-sm border border-blue-300 rounded">
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="number" step="0.01" min="0.01" id="custom-valeur" placeholder="Valeur cible"
                               class="flex-1 px-2 py-1 text-sm border border-blue-300 rounded">
                        <button type="button" id="btn-custom-cancel"
                                class="px-3 py-1 text-xs font-semibold text-gray-600 bg-white rounded border border-gray-300 hover:bg-gray-100">
                            Annuler
                        </button>
                        <button type="button" id="btn-custom-save"
                                class="px-3 py-1 text-xs font-semibold text-white bg-blue-600 rounded hover:bg-blue-700">
                            Ajouter
                        </button>
                    </div>
                </div>

                {{-- Section : choix des produits en campagne (affiché si PRODUIT coché) --}}
                <div id="produits-section" class="hidden mb-3">
                    <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase">Produits en campagne</label>
                    <div id="produits-liste" class="grid grid-cols-2 gap-2 max-h-40 overflow-y-auto p-2 border border-emerald-200 rounded-lg bg-emerald-50">
                        <div class="text-xs text-gray-500 italic col-span-2">Sélectionnez un partenaire d'abord</div>
                    </div>
                </div>

                {{-- Liste des objectifs avec valeurs --}}
                <div id="objectifs-types-list" class="space-y-2">
                    <div class="text-xs text-gray-500 italic p-2">Cochez un type pour définir une valeur cible</div>
                </div>
            </div>

            {{-- ─── AFFECTATION AGENTS ──────────────────── --}}
            <div class="border-t pt-4">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide">
                        <i class="fas fa-users text-indigo-500 mr-1"></i> Affecter des agents
                        <span id="agents-count" class="ml-2 px-2 py-0.5 text-xs bg-indigo-100 text-indigo-700 rounded-full">0</span>
                    </h4>
                </div>
                <div id="agents-selection" class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-56 overflow-y-auto p-2 border border-gray-200 rounded-lg bg-gray-50">
                    <div class="text-xs text-gray-500 italic p-2">Chargement des agents...</div>
                </div>
            </div>
        </form>

        {{-- ═══════════════════════════════════════════════ --}}
        {{-- FOOTER                                          --}}
        {{-- ═══════════════════════════════════════════════ --}}
        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal="campagne-modal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                Annuler
            </button>
            <button type="submit" form="campagne-form" id="btn-save"
                    class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition-colors">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Enregistrer
            </button>
        </div>
    </div>
</div>