@extends('layouts.app')

@section('title', 'Souscription Client')
@section('page_title', 'Souscription Client')

@section('content')
<div class="space-y-5">

    {{-- ÉTAPE 1 : Client --}}
    <div class="bg-white rounded-xl shadow-sm p-4">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-indigo-600 text-white text-xs mr-2">1</span>
            Sélectionner un client
        </h3>
        <div class="relative">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
            <input type="text" id="search-client" placeholder="Rechercher un client..."
                   class="w-full pl-10 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
        </div>
        <div id="search-client-results" class="mt-2 max-h-56 overflow-y-auto hidden bg-white border border-indigo-200 rounded-lg shadow-lg"></div>

        <div id="client-selectionne" class="hidden mt-3 p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-xs text-emerald-600 font-semibold uppercase">Client sélectionné</div>
                    <div id="client-nom" class="font-bold text-gray-900 mt-1"></div>
                    <div id="client-code" class="text-xs text-gray-500"></div>
                </div>
                <button type="button" id="btn-clear-client" class="text-red-500 hover:text-red-700 text-sm">
                    <i class="fas fa-times-circle"></i> Retirer
                </button>
            </div>
        </div>
    </div>

    {{-- ÉTAPE 2 : Produits --}}
    <div id="produit-bloc" class="hidden bg-white rounded-xl shadow-sm p-4">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-indigo-600 text-white text-xs mr-2">2</span>
            Ajouter des produits au panier
        </h3>

        <div class="flex gap-2 mb-4">
            <button data-tab="STOCK" class="souscription-tab px-4 py-2 text-sm font-semibold rounded-lg border-b-2 border-indigo-600 text-indigo-600">
                <i class="fas fa-box mr-1"></i> Stock
            </button>
            <button data-tab="EPARGNE" class="souscription-tab px-4 py-2 text-sm font-semibold rounded-lg border-b-2 border-transparent text-gray-500">
                <i class="fas fa-piggy-bank mr-1"></i> Épargne
            </button>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-semibold text-gray-700 mb-1">Filtrer par partenaire</label>
            <select id="partenaire_id" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">— Tous —</option>
            </select>
        </div>

        <div id="produits-liste" class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="col-span-2 text-sm text-gray-400 italic text-center py-4">Chargement...</div>
        </div>
    </div>

    {{-- ÉTAPE 3 : Panier --}}
    <div id="panier-bloc" class="hidden bg-white rounded-xl shadow-sm p-4">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-indigo-600 text-white text-xs mr-2">3</span>
            Panier
        </h3>

        <div id="panier-liste" class="space-y-2 mb-4"></div>

        <div class="border-t pt-4 space-y-3">
            <div class="flex justify-between text-sm">
                <span class="font-semibold text-gray-700">Total panier :</span>
                <span id="panier-total" class="font-bold text-indigo-600 text-lg">0</span>
            </div>

            <div id="mode-execution-bloc" class="p-3 bg-gray-50 rounded-lg">
                <div class="text-sm font-semibold text-gray-700 mb-2">Mode d'exécution</div>
                <div class="flex gap-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="mode_execution" value="AUTO" checked class="text-indigo-600">
                        <span class="text-sm">Auto (vente si stock OK)</span>
                    </label>
                </div>
                <div class="flex gap-4 mt-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="mode_execution" value="VENTE" class="text-indigo-600">
                        <span class="text-sm">Vente immédiate</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="mode_execution" value="COMMANDE" class="text-indigo-600">
                        <span class="text-sm">Commande</span>
                    </label>
                </div>
            </div>

            {{-- Livraison (commande) --}}
            <div id="livraison-wrapper" class="hidden space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Mode de livraison</label>
                        <select id="mode_livraison" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="RETRAIT_MAGASIN">Retrait magasin</option>
                            <option value="DOMICILE">Domicile</option>
                            <option value="POINT_RELAIS">Point relais</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Téléphone livraison</label>
                        <input type="text" id="telephone_livraison" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">📅 Date de livraison souhaitée</label>
                        <input type="date" id="date_livraison_souhaitee" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Adresse livraison</label>
                        <input type="text" id="adresse_livraison" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    </div>
                </div>

                {{-- Récurrence --}}
                <div class="border-t pt-4">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="est_recurrente" class="w-4 h-4 rounded accent-indigo-600">
                        <span class="text-sm font-semibold text-gray-700">🔄 Commande récurrente (livraison périodique)</span>
                    </label>

                    <div id="recurrence-wrapper" class="hidden mt-3 p-4 bg-indigo-50 border border-indigo-200 rounded-lg space-y-3">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Type de récurrence *</label>
                                <select id="recurrence_type" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                                    <option value="AUCUNE">— Aucune —</option>
                                    <option value="HEBDOMADAIRE">Hebdomadaire</option>
                                    <option value="MENSUEL">Mensuel</option>
                                    <option value="TRIMESTRIEL">Trimestriel</option>
                                    <option value="ANNUEL">Annuel</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Intervalle (1 = chaque mois)</label>
                                <input type="number" id="recurrence_intervalle" min="1" max="12" value="1"
                                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">
                                    Nombre d'occurrences
                                    <span class="text-xs text-gray-500">(recommandé : calcule auto)</span>
                                </label>
                                <input type="number" id="recurrence_nb_occurrences" min="1" max="100"
                                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">
                                    Date de fin
                                    <span class="text-xs text-gray-500">(optionnel si nb occurrences renseigné)</span>
                                </label>
                                <input type="date" id="recurrence_date_fin"
                                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            </div>
                        </div>

                        {{-- Aperçu de la date de fin calculée --}}
                        <div id="recurrence-apercu" class="hidden p-3 bg-white border border-indigo-300 rounded-lg">
                            <div class="text-xs text-indigo-600 font-semibold uppercase">Date de fin calculée</div>
                            <div id="recurrence-apercu-date" class="font-bold text-gray-900 text-lg mt-1"></div>
                            <div id="recurrence-apercu-detail" class="text-xs text-gray-500 mt-1"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Notes</label>
                <textarea id="notes" rows="2" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" id="btn-annuler" class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                    Vider le panier
                </button>
                <button type="button" id="btn-soumettre" class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
                    <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                    Valider
                </button>
            </div>
        </div>
    </div>

    {{-- ÉPARGNE --}}
    <div id="epargne-bloc" class="hidden bg-white rounded-xl shadow-sm p-4">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
            <i class="fas fa-piggy-bank text-indigo-500 mr-1"></i> Ouverture de compte épargne
        </h3>

        <div id="epargne-produit-info" class="mb-4"></div>

        <div class="space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Montant initial</label>
                <input type="number" id="montant_initial" min="0" step="0.01" value="0"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" id="btn-annuler-epargne" class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-lg">
                    Annuler
                </button>
                <button type="button" id="btn-soumettre-epargne" class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg">
                    Ouvrir le compte
                </button>
            </div>
        </div>
    </div>

    {{-- Résultat --}}
    <div id="resultat-bloc" class="hidden bg-white rounded-xl shadow-sm p-4"></div>
</div>

{{-- MODAL — Finaliser la vente --}}
<div id="finaliser-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto" style="display: none;">
    <div class="modal-content bg-white rounded-xl shadow-2xl max-w-lg w-full my-8 transition-transform scale-95">

        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center">
                    <i class="fas fa-cash-register"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Finaliser la vente</h3>
                    <p id="finaliser-facture-ref" class="text-xs text-gray-500 font-mono"></p>
                </div>
            </div>
            <button type="button" data-close-modal="finaliser-modal"
                    class="text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg w-9 h-9 flex items-center justify-center">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="finaliser-form" class="p-5 space-y-4">
            <input type="hidden" id="finaliser-facture-id">

            <div class="p-3 bg-gray-50 rounded-lg">
                <div class="text-xs text-gray-500 uppercase font-semibold">Caisse</div>
                <div id="finaliser-caisse-info" class="text-sm text-gray-900 font-medium mt-1">Caisse de la succursale</div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Mode de paiement *</label>
                <select id="finaliser-mode" required
                        class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    <option value="ESPECES">Espèces</option>
                    <option value="MOBILE_MONEY">Mobile Money</option>
                    <option value="VIREMENT">Virement</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Montant reçu</label>
                <input type="number" id="finaliser-montant" min="0" step="0.01"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <p class="text-xs text-gray-500 mt-1">Laisser vide = montant total</p>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Notes</label>
                <textarea id="finaliser-notes" rows="2"
                          class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg"></textarea>
            </div>
        </form>

        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal="finaliser-modal"
                    class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                Annuler
            </button>
            <button type="submit" form="finaliser-form" id="btn-finaliser"
                    class="px-4 py-2 text-sm font-semibold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Payer et finaliser
            </button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/souscriptions.js') }}?v={{ time() }}"></script>
@endpush