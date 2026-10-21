{{-- Modal Création / Édition --}}
<div id="user-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="modal-content bg-white rounded-xl shadow-2xl max-w-2xl w-full my-8 transition-transform scale-95">
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <h3 id="modal-title" class="text-lg font-bold text-gray-900">Nouvel utilisateur</h3>
            <button type="button" data-close-modal class="text-gray-400 hover:text-gray-700 text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="user-form" class="p-5 space-y-4">
            <input type="hidden" id="user-id" value="">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Nom complet *</label>
                    <input type="text" id="nom_complet" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Nom d'utilisateur *</label>
                    <input type="text" id="nom_utilisateur" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Email *</label>
                    <input type="email" id="email" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Téléphone *</label>
                    <input type="text" id="telephone" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Rôle *</label>
                    <select id="role_id" required
                            class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                        <option value="">— Choisir un rôle —</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Succursale *</label>
                    <select id="succursale_id" required
                            class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                        <option value="">— Choisir une succursale —</option>
                    </select>
                </div>

                <div id="password-fields" class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Mot de passe *</label>
                        <input type="password" id="mot_de_passe"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Confirmer *</label>
                        <input type="password" id="mot_de_passe_confirmation"
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
            <button type="submit" form="user-form" id="btn-save"
                    class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition
                           disabled:opacity-60">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                <span>Enregistrer</span>
            </button>
        </div>
    </div>
</div>