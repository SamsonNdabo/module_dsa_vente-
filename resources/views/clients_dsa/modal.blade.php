<div id="client-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="modal-content bg-white rounded-xl shadow-2xl max-w-3xl w-full my-8 transition-transform scale-95">
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <h3 id="modal-title" class="text-lg font-bold text-gray-900">Nouveau client DSA</h3>
            <button type="button" data-close-modal class="text-gray-400 hover:text-gray-700 text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>

        {{-- Onglets --}}
        <div class="flex border-b border-gray-100 px-5">
            <button type="button" data-tab="pos" class="tab-btn px-4 py-3 text-sm font-semibold border-b-2 border-indigo-600 text-indigo-600">
                <i class="fas fa-link mr-1"></i> Client POS existant
            </button>
            <button type="button" data-tab="nouveau" class="tab-btn px-4 py-3 text-sm font-semibold border-b-2 border-transparent text-gray-500">
                <i class="fas fa-plus-circle mr-1"></i> Nouveau client
            </button>
        </div>

        <form id="client-form" class="p-5 space-y-4">
            <input type="hidden" id="client-id">
            <input type="hidden" id="client_id_pos">

            {{-- ONGLET 1 : POS existant --}}
            <div data-tab-content="pos" class="space-y-4">
                <div class="p-4 bg-indigo-50 rounded-lg">
                    <p class="text-sm text-indigo-800 mb-3">
                        <i class="fas fa-info-circle mr-1"></i>
                        Sélectionnez un client déjà existant dans le POS. Les informations communes seront reprises automatiquement.
                    </p>
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" id="search-pos" placeholder="Nom, code ou téléphone d'un client POS..."
                               class="w-full pl-10 pr-4 py-2 text-sm border border-indigo-300 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div id="search-pos-results" class="mt-2 max-h-48 overflow-y-auto hidden bg-white border border-indigo-200 rounded-lg"></div>
                </div>

                <div id="client-pos-selectionne" class="hidden p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs text-emerald-600 font-semibold uppercase">Client POS sélectionné</div>
                            <div id="pos-nom" class="font-bold text-gray-900 mt-1"></div>
                            <div id="pos-code" class="text-xs text-gray-500"></div>
                        </div>
                        <button type="button" id="btn-clear-pos" class="text-red-500 hover:text-red-700 text-sm">
                            <i class="fas fa-times-circle"></i> Retirer
                        </button>
                    </div>
                </div>
            </div>

            {{-- ONGLET 2 : nouveau client --}}
            <div data-tab-content="nouveau" class="space-y-4 hidden">
                <div class="p-4 bg-amber-50 rounded-lg">
                    <p class="text-sm text-amber-800">
                        <i class="fas fa-info-circle mr-1"></i>
                        Remplissez les informations du nouveau client.
                    </p>
                </div>

                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="creer_client_pos" class="w-4 h-4 rounded accent-indigo-600">
                    <span class="text-sm text-gray-700">Créer également ce client dans le POS</span>
                </label>
            </div>

            {{-- Champs communs --}}
            <div class="border-t pt-4 mt-4">
                <h4 class="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide">Informations client</h4>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nom complet *</label>
                        <input type="text" id="nom_complet" required maxlength="255"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Sexe *</label>
                        <select id="sexe" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="">— Choisir —</option>
                            <option value="M">Masculin</option>
                            <option value="F">Féminin</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Date de naissance *</label>
                        <input type="date" id="date_naissance" required
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Téléphone *</label>
                        <input type="text" id="telephone" required maxlength="30"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Email</label>
                        <input type="email" id="email" maxlength="255"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Adresse *</label>
                        <input type="text" id="adresse" required maxlength="255"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Profession *</label>
                        <input type="text" id="profession" required maxlength="150"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Activité économique *</label>
                        <input type="text" id="activite_economique" required maxlength="150"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Agent DSA</label>
                        <select id="agent_dsa_id" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="">— Aucun —</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Zone</label>
                        <select id="zone_id" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="">— Aucune —</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Type de pièce d'identité</label>
                        <select id="piece_identite_type" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="">— Aucun —</option>
                            <option value="CARTE_IDENTITE">Carte d'identité</option>
                            <option value="PASSEPORT">Passeport</option>
                            <option value="PERMIS">Permis</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Numéro pièce</label>
                        <input type="text" id="piece_identite_numero" maxlength="100"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                </div>
            </div>
        </form>

        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                Annuler
            </button>
            <button type="submit" form="client-form" id="btn-save"
                    class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Enregistrer
            </button>
        </div>
    </div>
</div>