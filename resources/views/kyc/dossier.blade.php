@extends('layouts.app')

@section('title', 'Dossier KYC')
@section('page_title', 'Dossier KYC')

@section('content')
<div class="space-y-5" id="kyc-dossier-page" data-demande-id="{{ $demandeId ?? '' }}">

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- HEADER — Carte principale avec référence + statut + progression --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="bg-gradient-to-br from-indigo-600 to-indigo-800 rounded-2xl shadow-lg overflow-hidden">
        <div class="p-6">
            <div class="flex items-start justify-between flex-wrap gap-4">
                <div class="flex items-start gap-4">
                    <div class="w-14 h-14 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-id-card text-white text-2xl"></i>
                    </div>
                    <div>
                        <div class="text-xs text-indigo-200 uppercase font-semibold tracking-wider">Dossier KYC</div>
                        <div id="d-reference" class="text-2xl font-bold text-white mt-1 font-mono">—</div>
                        <div id="d-client" class="text-sm text-indigo-100 mt-1">—</div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span id="d-statut" class="px-4 py-2 text-sm font-bold rounded-full bg-white/20 text-white backdrop-blur">—</span>
                    <a href="{{ url('/kyc-dossiers') }}"
                       class="px-4 py-2 text-sm font-medium bg-white/10 text-white rounded-lg hover:bg-white/20 transition-colors">
                        <i class="fas fa-arrow-left mr-1"></i> Retour
                    </a>
                </div>
            </div>

            {{-- Barre de progression KYC --}}
            <div class="mt-6 grid grid-cols-3 gap-3">
                <div class="bg-white/10 rounded-lg p-3 backdrop-blur">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs text-indigo-200 font-semibold uppercase">Pièces</span>
                        <span id="progress-pieces" class="text-sm font-bold text-white">0</span>
                    </div>
                    <div class="h-1.5 bg-white/20 rounded-full overflow-hidden">
                        <div id="progress-pieces-bar" class="h-full bg-emerald-400 transition-all duration-500" style="width: 0%"></div>
                    </div>
                </div>
                <div class="bg-white/10 rounded-lg p-3 backdrop-blur">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs text-indigo-200 font-semibold uppercase">Signature</span>
                        <span id="progress-signature" class="text-sm font-bold text-white">Non</span>
                    </div>
                    <div class="h-1.5 bg-white/20 rounded-full overflow-hidden">
                        <div id="progress-signature-bar" class="h-full bg-emerald-400 transition-all duration-500" style="width: 0%"></div>
                    </div>
                </div>
                <div class="bg-white/10 rounded-lg p-3 backdrop-blur">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs text-indigo-200 font-semibold uppercase">Consentements</span>
                        <span id="progress-consentements" class="text-sm font-bold text-white">0</span>
                    </div>
                    <div class="h-1.5 bg-white/20 rounded-full overflow-hidden">
                        <div id="progress-consentements-bar" class="h-full bg-emerald-400 transition-all duration-500" style="width: 0%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- 2 colonnes : gauche (exigences + actions), droite (pièces + signature + consentements) --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- COLONNE GAUCHE (1/3) --}}
        <div class="lg:col-span-1 space-y-5">

            {{-- Actions workflow --}}
            <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center">
                        <i class="fas fa-tasks text-sm"></i>
                    </div>
                    <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">Actions</h3>
                </div>
                <div id="actions-workflow" class="flex flex-col gap-2">
                    <div class="text-sm text-gray-500 italic">Chargement...</div>
                </div>
            </div>

            {{-- Exigences partenaire --}}
            <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-8 h-8 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center">
                        <i class="fas fa-clipboard-check text-sm"></i>
                    </div>
                    <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">Exigences partenaire</h3>
                </div>
                <div id="exigences-list" class="space-y-2">
                    <div class="text-sm text-gray-500 italic">Aucun partenaire associé.</div>
                </div>
            </div>
        </div>

        {{-- COLONNE DROITE (2/3) --}}
        <div class="lg:col-span-2 space-y-5">

            {{-- Pièces justificatives --}}
            <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center">
                            <i class="fas fa-folder-open text-sm"></i>
                        </div>
                        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">
                            Pièces justificatives
                            <span id="pieces-count" class="ml-2 px-2 py-0.5 text-xs bg-blue-100 text-blue-700 rounded-full">0</span>
                        </h3>
                    </div>
                    <button id="btn-add-piece"
                            class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition-colors shadow-sm">
                        <i class="fas fa-plus mr-1"></i> Ajouter
                    </button>
                </div>
                <div id="pieces-list" class="space-y-2">
                    <div class="text-sm text-gray-500 italic text-center py-4">Aucune pièce déposée.</div>
                </div>
            </div>

            {{-- Signature --}}
            <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-purple-100 text-purple-600 flex items-center justify-center">
                            <i class="fas fa-signature text-sm"></i>
                        </div>
                        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">Signature du client</h3>
                    </div>
                    <button id="btn-upload-signature"
                            class="px-4 py-2 text-sm font-semibold text-purple-600 bg-purple-50 rounded-lg hover:bg-purple-100 transition-colors">
                        <i class="fas fa-upload mr-1"></i> Uploader
                    </button>
                </div>
                <div id="signature-status" class="text-sm text-gray-500 italic text-center py-4">
                    Aucune signature enregistrée.
                </div>
            </div>

            {{-- Consentements --}}
            <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center">
                            <i class="fas fa-file-signature text-sm"></i>
                        </div>
                        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">
                            Consentements
                            <span id="consentements-count" class="ml-2 px-2 py-0.5 text-xs bg-emerald-100 text-emerald-700 rounded-full">0</span>
                        </h3>
                    </div>
                    <button id="btn-consentement"
                            class="px-4 py-2 text-sm font-semibold text-emerald-600 bg-emerald-50 rounded-lg hover:bg-emerald-100 transition-colors">
                        <i class="fas fa-plus mr-1"></i> Recueillir
                    </button>
                </div>
                <div id="consentements-list" class="space-y-2 text-sm text-gray-500 italic text-center py-4">
                    Aucun consentement enregistré.
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

{{-- ═══════════════════════════════════════════════════════════════════ --}}
{{-- MODALS (après @endsection)                                        --}}
{{-- ═══════════════════════════════════════════════════════════════════ --}}

{{-- MODAL : Ajouter une pièce --}}
<div id="piece-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4 overflow-y-auto" style="display: none;">
    <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-lg w-full my-8 transition-transform scale-95">
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center">
                    <i class="fas fa-file-upload"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Ajouter une pièce</h3>
                    <p class="text-xs text-gray-500">JPG, PNG, PDF, DOC, DOCX — max 10 Mo</p>
                </div>
            </div>
            <button type="button" data-close-modal="piece-modal" class="text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg w-9 h-9 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="piece-form" class="p-5 space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Type de document *</label>
                <select id="type_document" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                    <option value="">— Choisir —</option>
                    <option value="CARTE_IDENTITE">Carte d'identité</option>
                    <option value="PASSEPORT">Passeport</option>
                    <option value="CARTE_ELECTEUR">Carte d'électeur</option>
                    <option value="PERMIS">Permis de conduire</option>
                    <option value="AUTRE_PIECE">Autre pièce</option>
                    <option value="PHOTO_CLIENT">Photo du client</option>
                    <option value="JUSTIFICATIF_ADRESSE">Justificatif d'adresse</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Numéro</label>
                    <input type="text" id="numero" maxlength="60" placeholder="ex: 123456789"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Date d'expiration</label>
                    <input type="date" id="date_expiration"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Fichier *</label>
                <input type="file" id="fichier" required accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"
                       class="w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            </div>
        </form>

        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal="piece-modal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                Annuler
            </button>
            <button type="submit" form="piece-form" id="btn-save-piece"
                    class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition-colors">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Uploader
            </button>
        </div>
    </div>
</div>

{{-- MODAL : Signature --}}
<div id="signature-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4" style="display: none;">
    <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full my-8">
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-purple-100 text-purple-600 flex items-center justify-center">
                    <i class="fas fa-signature"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Signature du client</h3>
                    <p class="text-xs text-gray-500">PNG ou JPG — max 2 Mo</p>
                </div>
            </div>
            <button type="button" data-close-modal="signature-modal" class="text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg w-9 h-9 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="signature-form" class="p-5">
            <input type="file" id="signature_file" required accept=".png,.jpg,.jpeg"
                   class="w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100">
        </form>
        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal="signature-modal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                Annuler
            </button>
            <button type="submit" form="signature-form" id="btn-save-signature"
                    class="px-4 py-2 text-sm font-semibold text-white bg-purple-600 rounded-lg hover:bg-purple-700 transition-colors">
                Uploader
            </button>
        </div>
    </div>
</div>

{{-- MODAL : Consentement --}}
<div id="consentement-modal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center p-4" style="display: none;">
    <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-lg w-full my-8">
        <div class="flex items-center justify-between p-5 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center">
                    <i class="fas fa-file-signature"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Recueillir le consentement</h3>
                    <p class="text-xs text-gray-500">Traçabilité RGPD / MCD</p>
                </div>
            </div>
            <button type="button" data-close-modal="consentement-modal" class="text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg w-9 h-9 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="consentement-form" class="p-5 space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Type de consentement *</label>
                <select id="type_consentement" required class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-emerald-500">
                    <option value="KYC_DATA">Traitement des données KYC</option>
                    <option value="PARTAGE_PARTENAIRE">Partage avec le partenaire</option>
                    <option value="COMMUNICATION">Communications commerciales</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Version du formulaire</label>
                <input type="text" id="version_formulaire" value="v1.0"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Texte du consentement *</label>
                <textarea id="texte_consentement" rows="4" required
                          class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-emerald-500">Je consens au traitement de mes données personnelles conformément à la politique de confidentialité.</textarea>
            </div>
            <label class="flex items-center gap-2 cursor-pointer p-3 bg-emerald-50 rounded-lg">
                <input type="checkbox" id="consenti" checked class="w-4 h-4 rounded accent-emerald-600">
                <span class="text-sm font-medium text-emerald-800">Le client a donné son consentement</span>
            </label>
        </form>
        <div class="flex justify-end gap-2 p-5 border-t border-gray-100">
            <button type="button" data-close-modal="consentement-modal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                Annuler
            </button>
            <button type="submit" form="consentement-form" id="btn-save-consentement"
                    class="px-4 py-2 text-sm font-semibold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 transition-colors">
                <span class="spinner hidden inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin mr-1"></span>
                Enregistrer
            </button>
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/kyc_dossier.js') }}?v={{ time() }}"></script>
@endpush