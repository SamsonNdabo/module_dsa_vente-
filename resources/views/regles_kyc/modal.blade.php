<div id="regle-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="modal-content bg-white rounded-xl shadow-2xl max-w-2xl w-full my-8 transition-transform scale-95">
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <h3 id="modal-title" class="text-lg font-bold text-gray-900">Nouvelle règle KYC</h3>
            <button type="button" data-close-modal class="text-gray-400 hover:text-gray-700 text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="regle-form" class="p-5 space-y-4">
            <input type="hidden" id="regle-id">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Partenaire *</label>
                    <select id="partenaire_id" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">— Choisir —</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Produit DSA</label>
                    <select id="produit_dsa_id" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">Tous les produits (règle globale)</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Laisser vide pour une règle au niveau partenaire.</p>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Type de document *</label>
                    <select id="type_document" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">— Choisir —</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Validité (jours)</label>
                    <input type="number" id="validite_jours" min="1" max="3650" placeholder="ex: 365 (vide = illimité)"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>

                <div class="space-y-2 pt-6">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="obligatoire" class="w-4 h-4 rounded accent-indigo-600" checked>
                        <span class="text-sm text-gray-700">Document obligatoire</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="actif" class="w-4 h-4 rounded accent-indigo-600" checked>
                        <span class="text-sm text-gray-700">Règle active</span>
                    </label>
                </div>
            </div>
        </form>

        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                Annuler
            </button>
            <button type="submit" form="regle-form" id="btn-save"
                    class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Enregistrer
            </button>
        </div>
    </div>
</div>