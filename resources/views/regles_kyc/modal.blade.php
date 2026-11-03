<div id="regle-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="modal-content bg-white rounded-xl shadow-2xl max-w-3xl w-full my-8 transition-transform scale-95">
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <h3 id="modal-title" class="text-lg font-bold text-gray-900">Nouvelle règle KYC</h3>
            <button type="button" data-close-modal class="text-gray-400 hover:text-gray-700 text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="regle-form" class="p-5 space-y-4 max-h-[75vh] overflow-y-auto">
            <input type="hidden" id="regle-id">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Partenaire *</label>
                    <select id="partenaire_id" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">— Choisir —</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Type de document *</label>
                    <select id="type_document" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">— Choisir —</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Validité (jours)</label>
                    <input type="number" id="validite_jours" min="1" max="3650" placeholder="ex: 365"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>

                <div class="space-y-2 pt-6">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="obligatoire" class="w-4 h-4 rounded accent-indigo-600" checked>
                        <span class="text-sm text-gray-700">Obligatoire</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="actif" class="w-4 h-4 rounded accent-indigo-600" checked>
                        <span class="text-sm text-gray-700">Active</span>
                    </label>
                </div>
            </div>

            {{-- ⚠️ NOUVEAU : liste N produits --}}
            <div class="border-t pt-4">
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-sm font-semibold text-gray-700">
                        Produits concernés
                        <span class="text-xs font-normal text-gray-500">(laisser vide = règle globale)</span>
                    </label>
                    <button type="button" id="btn-add-produit"
                            class="px-2 py-1 text-xs text-indigo-600 bg-indigo-50 rounded hover:bg-indigo-100">
                        <i class="fas fa-plus mr-1"></i>Ajouter un produit
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-2 mb-2">
                    <select id="add-type-produit" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="STOCK">Stock</option>
                        <option value="EPARGNE">Épargne</option>
                    </select>
                    <select id="add-produit-id" class="md:col-span-2 px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">— Sélectionner un produit —</option>
                    </select>
                </div>

                <div id="produits-list" class="space-y-1 mt-2">
                    <div class="text-sm text-gray-400 italic text-center py-2">Aucun produit sélectionné (règle globale).</div>
                </div>
            </div>
        </form>

        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
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