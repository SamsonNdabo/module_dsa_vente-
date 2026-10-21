<div id="demande-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto" style="display: none;">
    <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-4xl w-full my-8 transition-transform scale-95">

        {{-- Header --}}
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center">
                    <i class="fas fa-tasks"></i>
                </div>
                <div>
                    <h3 id="modal-title" class="text-lg font-bold text-gray-900">Détail de la demande</h3>
                    <p id="modal-subtitle" class="text-xs text-gray-500 font-mono">—</p>
                </div>
            </div>
            <button type="button" data-close-modal="demande-modal"
                    class="text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg w-9 h-9 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>

        {{-- Contenu --}}
        <div class="p-5 space-y-5 max-h-[75vh] overflow-y-auto">

            {{-- Résumé --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-gray-50 rounded-xl p-3 border border-gray-100">
                    <div class="text-xs text-gray-500 uppercase font-semibold">Type</div>
                    <div id="d-type" class="text-sm font-semibold text-gray-900 mt-1">—</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3 border border-gray-100">
                    <div class="text-xs text-gray-500 uppercase font-semibold">Statut actuel</div>
                    <div id="d-statut" class="text-sm font-semibold mt-1">—</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3 border border-gray-100">
                    <div class="text-xs text-gray-500 uppercase font-semibold">Client DSA</div>
                    <div id="d-client" class="text-sm font-semibold text-gray-900 mt-1">—</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3 border border-gray-100">
                    <div class="text-xs text-gray-500 uppercase font-semibold">Partenaire</div>
                    <div id="d-partenaire" class="text-sm font-semibold text-gray-900 mt-1">—</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3 border border-gray-100">
                    <div class="text-xs text-gray-500 uppercase font-semibold">Agent</div>
                    <div id="d-agent" class="text-sm font-semibold text-gray-900 mt-1">—</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3 border border-gray-100">
                    <div class="text-xs text-gray-500 uppercase font-semibold">Créée le</div>
                    <div id="d-date" class="text-sm font-semibold text-gray-900 mt-1">—</div>
                </div>
            </div>

            {{-- Workflow visuel --}}
            <div>
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-route text-indigo-500 mr-1"></i> Workflow
                </h4>
                <div id="workflow-steps" class="flex items-center gap-2 flex-wrap p-3 bg-gray-50 rounded-xl border border-gray-100"></div>
            </div>

            {{-- Actions --}}
            <div>
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-bolt text-amber-500 mr-1"></i> Actions disponibles
                </h4>
                <div id="actions-dispo" class="flex flex-wrap gap-2"></div>
            </div>

            {{-- Historique --}}
            <div>
                <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wide mb-3">
                    <i class="fas fa-history text-blue-500 mr-1"></i> Historique
                </h4>
                <div id="d-historique" class="space-y-2 max-h-64 overflow-y-auto"></div>
            </div>
        </div>

        {{-- Footer --}}
        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal="demande-modal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                Fermer
            </button>
        </div>
    </div>
</div>