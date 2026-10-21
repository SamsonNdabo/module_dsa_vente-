<div id="kyc-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto"
     style="display: none;">
    <div class="modal-content bg-white rounded-xl shadow-2xl max-w-lg w-full my-8 transition-transform scale-95">
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <h3 id="modal-title" class="text-lg font-bold text-gray-900">Nouveau dossier KYC</h3>
            <button type="button" data-close-modal class="text-gray-400 hover:text-gray-700 text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="kyc-create-form" class="p-5 space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Client DSA *</label>
                <select id="client_dsa_id" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                    <option value="">— Choisir un client DSA —</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">
                    Le client doit avoir été créé dans « Clients DSA ».
                </p>
            </div>

            <div class="p-3 bg-blue-50 rounded-lg text-xs text-blue-800">
                <i class="fas fa-info-circle mr-1"></i>
                Le dossier KYC sera créé en statut <strong>BROUILLON</strong>.
                Vous pourrez ensuite ajouter les pièces, uploader la signature
                et recueillir les consentements.
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">
                    UUID local <span class="text-xs text-gray-400">(optionnel)</span>
                </label>
                <input type="text" id="local_uuid" maxlength="36"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg font-mono">
            </div>
        </form>

        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                Annuler
            </button>
            <button type="submit" form="kyc-create-form" id="btn-save"
                    class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Créer le dossier
            </button>
        </div>
    </div>
</div>