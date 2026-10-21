<div id="objectif-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="modal-content bg-white rounded-xl shadow-2xl max-w-3xl w-full my-8 transition-transform scale-95">
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <h3 id="modal-title" class="text-lg font-bold text-gray-900">Nouvel objectif</h3>
            <button type="button" data-close-modal class="text-gray-400 hover:text-gray-700 text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="objectif-form" class="p-5 space-y-4">
            <input type="hidden" id="objectif-id">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Partenaire *</label>
                    <select id="partenaire_id" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">— Choisir —</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Campagne</label>
                    <select id="campagne_id" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">— Aucune —</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Agent DSA</label>
                    <select id="agent_dsa_id" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="">— Aucun (global) —</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Produit DSA</label>
                    <select id="produit_dsa_id" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
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
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Type d'objectif *</label>
                    <select id="type_objectif" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="MONTANT">Montant</option>
                        <option value="VOLUME">Volume</option>
                        <option value="CLIENTS">Clients</option>
                        <option value="COMPTES">Comptes</option>
                        <option value="VISITES">Visites</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Libellé *</label>
                    <input type="text" id="libelle" required maxlength="200"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Valeur cible *</label>
                    <input type="number" step="0.01" id="valeur_cible" required min="0.01"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Valeur réalisée</label>
                    <input type="number" step="0.01" id="valeur_realisee" min="0" value="0"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Unité</label>
                    <input type="text" id="unite" maxlength="20" placeholder="CDF, USD, clients..."
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Statut</label>
                    <select id="statut" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="ACTIF">Actif</option>
                        <option value="ATTEINT">Atteint</option>
                        <option value="DEPASSE">Dépassé</option>
                        <option value="ANNULE">Annulé</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Période début *</label>
                    <input type="date" id="periode_debut" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Période fin *</label>
                    <input type="date" id="periode_fin" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
            </div>
        </form>

        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                Annuler
            </button>
            <button type="submit" form="objectif-form" id="btn-save"
                    class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Enregistrer
            </button>
        </div>
    </div>
</div>