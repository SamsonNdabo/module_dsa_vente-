<div id="succursale-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="modal-content bg-white rounded-xl shadow-2xl max-w-3xl w-full my-8 transition-transform scale-95">
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <h3 id="modal-title" class="text-lg font-bold text-gray-900">Nouvelle succursale</h3>
            <button type="button" data-close-modal class="text-gray-400 hover:text-gray-700 text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="succursale-form" class="p-5 space-y-4">
            <input type="hidden" id="succursale-id">

            {{-- Identité --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Nom *</label>
                    <input type="text" id="nom" required maxlength="150"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Code</label>
                    <input type="text" id="code" maxlength="100" placeholder="Auto-généré si vide"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Type</label>
                    <input type="text" id="type" placeholder="ex: AGENCE, DEPOT"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Entité *</label>
                    <select id="entite_id" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">— Choisir —</option>
                    </select>
                </div>
            </div>

            {{-- Contact --}}
            <div class="border-t pt-4">
                <h4 class="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide">Contact</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Téléphone *</label>
                        <input type="text" id="telephone" required maxlength="50"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Email *</label>
                        <input type="email" id="email" required maxlength="150"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Adresse *</label>
                        <input type="text" id="adresse" required
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Ville *</label>
                        <input type="text" id="ville" required maxlength="100"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Province</label>
                        <input type="text" id="province" maxlength="100"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Pays</label>
                        <input type="text" id="pays" maxlength="100" value="RDC"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Code postal</label>
                        <input type="text" id="code_postal" maxlength="20"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                </div>
            </div>

            {{-- Gestion --}}
            <div class="border-t pt-4">
                <h4 class="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide">Gestion</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Responsable</label>
                        <select id="responsable_id" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="">— Aucun —</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Zone géographique</label>
                        <input type="text" id="zone_geo" maxlength="100"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Capacité maximale</label>
                        <input type="number" id="capacite_maximale" min="1"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div class="space-y-2 pt-6">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="est_principale" class="w-4 h-4 rounded accent-indigo-600">
                            <span class="text-sm text-gray-700">Succursale principale</span>
                        </label>
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="est_active" class="w-4 h-4 rounded accent-indigo-600" checked>
                            <span class="text-sm text-gray-700">Active</span>
                        </label>
                    </div>
                </div>
            </div>

            {{-- Statut --}}
            <div class="border-t pt-4">
                <h4 class="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide">Statut</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Statut</label>
                        <select id="statut" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                            <option value="ACTIVE">Active</option>
                            <option value="INACTIVE">Inactive</option>
                            <option value="MAINTENANCE">Maintenance</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Motif (si désactivation)</label>
                        <input type="text" id="motif" maxlength="255"
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
            <button type="submit" form="succursale-form" id="btn-save"
                    class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Enregistrer
            </button>
        </div>
    </div>
</div>