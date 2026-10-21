@extends('layouts.app')

@section('title', 'Attribution objectifs')
@section('page_title', 'Attribution objectifs')

@section('content')
<div class="space-y-5" id="campagne-objectifs-agents-page" data-campagne-id="{{ $campagneId }}">

    {{-- ═══════════════════════════════════════════════════════════ --}}
    {{-- HEADER (gradient + stats)                                   --}}
    {{-- ═══════════════════════════════════════════════════════════ --}}
    <div class="bg-gradient-to-br from-indigo-600 via-indigo-700 to-purple-800 rounded-2xl shadow-xl overflow-hidden relative">
        {{-- Motif décoratif --}}
        <div class="absolute inset-0 opacity-10">
            <div class="absolute -top-10 -right-10 w-40 h-40 rounded-full bg-white"></div>
            <div class="absolute -bottom-10 -left-10 w-32 h-32 rounded-full bg-white"></div>
        </div>

        <div class="relative p-6">
            <div class="flex items-start justify-between flex-wrap gap-4">
                <div class="flex items-start gap-4">
                    <div class="w-16 h-16 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center flex-shrink-0 ring-2 ring-white/30">
                        <i class="fas fa-bullseye text-white text-3xl"></i>
                    </div>
                    <div>
                        <div class="text-xs text-indigo-200 uppercase font-semibold tracking-wider">
                            <i class="fas fa-tasks mr-1"></i> Attribution des objectifs
                        </div>
                        <div id="c-nom" class="text-2xl md:text-3xl font-bold text-white mt-1">—</div>
                        <div id="c-meta" class="text-sm text-indigo-100 mt-2 flex flex-wrap gap-3">—</div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ url('/campagnes') }}"
                       class="px-4 py-2.5 text-sm font-medium bg-white/10 text-white rounded-xl hover:bg-white/20 transition-all backdrop-blur flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        <span class="hidden sm:inline">Retour</span>
                    </a>
                    <button id="btn-save-all"
                            class="px-5 py-2.5 text-sm font-bold bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl transition-all shadow-lg hover:shadow-emerald-500/50 flex items-center gap-2">
                        <i class="fas fa-save"></i>
                        <span>Enregistrer</span>
                    </button>
                </div>
            </div>

            {{-- Stats cartes --}}
            <div class="mt-6 grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="bg-white/10 rounded-xl p-4 backdrop-blur border border-white/20 hover:bg-white/15 transition-colors">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs text-indigo-100 uppercase font-semibold">Objectifs</span>
                        <i class="fas fa-bullseye text-indigo-200"></i>
                    </div>
                    <div id="stat-objectifs" class="text-2xl font-bold text-white">—</div>
                </div>
                <div class="bg-white/10 rounded-xl p-4 backdrop-blur border border-white/20 hover:bg-white/15 transition-colors">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs text-indigo-100 uppercase font-semibold">Agents</span>
                        <i class="fas fa-users text-indigo-200"></i>
                    </div>
                    <div id="stat-agents" class="text-2xl font-bold text-white">—</div>
                </div>
                <div class="bg-white/10 rounded-xl p-4 backdrop-blur border border-white/20 hover:bg-white/15 transition-colors">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs text-indigo-100 uppercase font-semibold">Assignés</span>
                        <i class="fas fa-check-circle text-indigo-200"></i>
                    </div>
                    <div id="stat-attributions" class="text-2xl font-bold text-emerald-300">—</div>
                </div>
                <div class="bg-white/10 rounded-xl p-4 backdrop-blur border border-white/20 hover:bg-white/15 transition-colors">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs text-indigo-100 uppercase font-semibold">À assigner</span>
                        <i class="fas fa-clock text-indigo-200"></i>
                    </div>
                    <div id="stat-a-assigner" class="text-2xl font-bold text-amber-300">—</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════ --}}
    {{-- MATRICE D'ATTRIBUTION                                       --}}
    {{-- ═══════════════════════════════════════════════════════════ --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">

        {{-- Header matrice --}}
        <div class="p-5 border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 text-white flex items-center justify-center shadow-md">
                        <i class="fas fa-table"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-800">Matrice d'attribution</h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            <i class="fas fa-info-circle mr-1"></i>
                            Cochez une cellule pour assigner un objectif à un agent, puis saisissez la cible individuelle
                        </p>
                    </div>
                </div>
                {{-- Légende --}}
                <div class="flex items-center gap-3 text-xs">
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                        <span class="text-gray-600">Assigné</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-gray-300"></span>
                        <span class="text-gray-600">Non assigné</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Conteneur matrice --}}
        <div id="matrice-container" class="overflow-x-auto">
            <div class="p-16 text-center text-gray-400">
                <div class="inline-flex flex-col items-center gap-3">
                    <div class="w-16 h-16 rounded-full bg-indigo-50 text-indigo-400 flex items-center justify-center">
                        <i class="fas fa-spinner fa-spin text-2xl"></i>
                    </div>
                    <span class="text-sm">Chargement de la matrice...</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════ --}}
    {{-- RÉCAPITULATIF (visible si des attributions existent)       --}}
    {{-- ═══════════════════════════════════════════════════════════ --}}
    <div id="recap-section" class="hidden bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-5 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 text-white flex items-center justify-center shadow-md">
                    <i class="fas fa-list-check"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-gray-800">Récapitulatif des attributions</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Vue synthétique des objectifs assignés par agent</p>
                </div>
            </div>
        </div>
        <div id="recap-container" class="p-5"></div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/campagnes_objectifs_agents.js') }}?v={{ time() }}"></script>
@endpush