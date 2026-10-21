<div id="produit-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="modal-content bg-white rounded-xl shadow-2xl max-w-3xl w-full my-8 transition-transform scale-95">
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <h3 id="modal-title" class="text-lg font-bold text-gray-900">Nouveau produit DSA</h3>
            <button type="button" data-close-modal class="text-gray-400 hover:text-gray-700 text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>

        {{-- Onglets --}}
        <div class="flex border-b border-gray-100 px-5">
            <button type="button" data-tab="pos" class="tab-btn px-4 py-3 text-sm font-semibold border-b-2 border-indigo-600 text-indigo-600">
                <i class="fas fa-link mr-1"></i> Produit POS existant
            </button>
            <button type="button" data-tab="nouveau" class="tab-btn px-4 py-3 text-sm font-semibold border-b-2 border-transparent text-gray-500">
                <i class="fas fa-plus-circle mr-1"></i> Nouveau produit
            </button>
        </div>

        <form id="produit-form" class="p-5 space-y-4">
            <input type="hidden" id="produit-id">
            <input type="hidden" id="produit_id_pos">

            {{-- ═══════════════════════════════════════════════════════ --}}
            {{-- ONGLET 1 : SÉLECTION PRODUIT POS                      --}}
            {{-- ═══════════════════════════════════════════════════════ --}}
            <div data-tab-content="pos" class="space-y-4">
                <div class="p-4 bg-indigo-50 rounded-lg">
                    <p class="text-sm text-indigo-800 mb-3">
                        <i class="fas fa-info-circle mr-1"></i>
                        Sélectionnez un produit déjà existant dans le POS. Les informations communes
                        seront reprises automatiquement.
                    </p>
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" id="search-pos" placeholder="Tapez le nom ou le code-barre d'un produit POS..."
                               class="w-full pl-10 pr-4 py-2 text-sm border border-indigo-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div id="search-pos-results" class="mt-2 max-h-48 overflow-y-auto hidden bg-white border border-indigo-200 rounded-lg"></div>
                </div>

                <div id="produit-pos-selectionne" class="hidden p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs text-emerald-600 font-semibold uppercase">Produit POS sélectionné</div>
                            <div id="pos-nom" class="font-bold text-gray-900 mt-1"></div>
                            <div id="pos-code" class="text-xs text-gray-500"></div>
                        </div>
                        <button type="button" id="btn-clear-pos" class="text-red-500 hover:text-red-700 text-sm">
                            <i class="fas fa-times-circle"></i> Retirer
                        </button>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════════════════════ --}}
            {{-- ONGLET 2 : NOUVEAU PRODUIT                             --}}
            {{-- ═══════════════════════════════════════════════════════ --}}
            <div data-tab-content="nouveau" class="space-y-4 hidden">
                <div class="p-4 bg-amber-50 rounded-lg">
                    <p class="text-sm text-amber-800">
                        <i class="fas fa-info-circle mr-1"></i>
                        Remplissez les informations. Si le produit nécessite un stock,
                        il sera également créé dans le POS.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nom *</label>
                        <input type="text" id="nom" required
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Code-barre POS</label>
                        <input type="text" id="produit_pos_code_barre" placeholder="Auto-généré si vide"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Type POS</label>
                        <select id="produit_pos_type" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="STANDARD">Standard</option>
                            <option value="ELECTRONIQUE">Électronique</option>
                            <option value="ACCESSOIRE">Accessoire</option>
                            <option value="ALIMENTAIRE">Alimentaire</option>
                            <option value="SERVICE">Service</option>
                            <option value="CONSOMMABLE">Consommable</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Devise POS *</label>
                        <select id="produit_pos_devise_id" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="">— Choisir —</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="produit_pos_gere_imei" class="w-4 h-4 rounded accent-indigo-600">
                            <span class="text-sm text-gray-700">Gérer les IMEI (téléphones, modems)</span>
                        </label>
                    </div>
                    <div class="md:col-span-2">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="produit_pos_est_perissable" class="w-4 h-4 rounded accent-indigo-600">
                            <span class="text-sm text-gray-700">Produit périssable (date d'expiration)</span>
                        </label>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════════════════════ --}}
            {{-- CHAMPS COMMUNS DSA                                    --}}
            {{-- ═══════════════════════════════════════════════════════ --}}
            <div class="border-t pt-4 mt-4">
                <h4 class="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide">Informations DSA</h4>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Code DSA *</label>
                        <input type="text" id="code" required
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Partenaire *</label>
                        <select id="partenaire_id" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="">— Choisir —</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Type DSA *</label>
                        <input type="text" id="type" required placeholder="ex: SIM, TELEPHONE, COMPTE"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Catégorie</label>
                        <input type="text" id="categorie" placeholder="ex: TELECOM, FINANCIER"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Prix de vente</label>
                        <input type="number" step="0.01" id="prix_vente"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Devise DSA *</label>
                        <select id="devise_id" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="">— Choisir —</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Unité</label>
                        <input type="text" id="unite" placeholder="unité, compte, police..."
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Type de commission *</label>
                        <select id="commission_type" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="FIXE">Fixe</option>
                            <option value="POURCENTAGE">Pourcentage</option>
                        </select>
                    </div>
                    <div id="commission-fixe-wrapper">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Commission (fixe)</label>
                        <input type="number" step="0.01" id="commission"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div id="commission-taux-wrapper" class="hidden">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Taux (%)</label>
                        <input type="number" step="0.01" id="taux_commission" min="0" max="100"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Date début</label>
                        <input type="date" id="date_debut"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Date fin</label>
                        <input type="date" id="date_fin"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    </div>
                    <div class="md:col-span-2 space-y-2">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="stock_requis" class="w-4 h-4 rounded accent-indigo-600">
                            <span class="text-sm text-gray-700">Ce produit nécessite une gestion de stock</span>
                        </label>
                        <label class="inline-flex items-center gap-2 cursor-pointer ml-6">
                            <input type="checkbox" id="est_activation" class="w-4 h-4 rounded accent-indigo-600">
                            <span class="text-sm text-gray-700">Produit d'activation (SIM, compte, ...)</span>
                        </label>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Statut</label>
                        <select id="statut" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="ACTIF">Actif</option>
                            <option value="INACTIF">Inactif</option>
                        </select>
                    </div>
                </div>
            </div>
        </form>

        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                Annuler
            </button>
            <button type="submit" form="produit-form" id="btn-save"
                    class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Enregistrer
            </button>
        </div>
    </div>
</div>