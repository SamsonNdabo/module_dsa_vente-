@extends('layouts.app')

@section('title', 'Objectifs mesurables')
@section('page_title', 'Objectifs mesurables')

@section('content')
<div class="space-y-5" id="campagne-objectifs-mesurables-page" data-campagne-id="{{ $campagneId }}">

    {{-- ═══════════════════════════════════════════════════════════ --}}
    {{-- HEADER (gradient + stats + progression globale)            --}}
    {{-- ═══════════════════════════════════════════════════════════ --}}
    <div class="bg-gradient-to-br from-emerald-600 via-teal-700 to-emerald-800 rounded-2xl shadow-xl overflow-hidden relative">
        {{-- Motif décoratif --}}
        <div class="absolute inset-0 opacity-10">
            <div class="absolute -top-10 -right-10 w-48 h-48 rounded-full bg-white"></div>
            <div class="absolute -bottom-10 -left-10 w-40 h-40 rounded-full bg-white"></div>
        </div>

        <div class="relative p-6">
            <div class="flex items-start justify-between flex-wrap gap-4">
                <div class="flex items-start gap-4">
                    <div class="w-16 h-16 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center flex-shrink-0 ring-2 ring-white/30">
                        <i class="fas fa-chart-line text-white text-3xl"></i>
                    </div>
                    <div>
                        <div class="text-xs text-emerald-200 uppercase font-semibold tracking-wider">
                            <i class="fas fa-bullseye mr-1"></i> Suivi des objectifs
                        </div>
                        <div id="c-nom" class="text-2xl md:text-3xl font-bold text-white mt-1">—</div>
                        <div id="c-meta" class="text-sm text-emerald-100 mt-2 flex flex-wrap gap-3">—</div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ url('/campagnes') }}"
                       class="px-4 py-2.5 text-sm font-medium bg-white/10 text-white rounded-xl hover:bg-white/20 transition-all backdrop-blur flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        <span class="hidden sm:inline">Campagnes</span>
                    </a>
                    <a id="btn-attribuer" href="#"
                       class="px-5 py-2.5 text-sm font-semibold bg-white text-emerald-700 rounded-xl hover:bg-emerald-50 transition-all shadow-lg flex items-center gap-2">
                        <i class="fas fa-tasks"></i>
                        <span>Attribuer</span>
                    </a>
                </div>
            </div>

            {{-- Stats cartes --}}
            <div class="mt-6 grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="bg-white/10 rounded-xl p-4 backdrop-blur border border-white/20 hover:bg-white/15 transition-colors">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs text-emerald-100 uppercase font-semibold">Total</span>
                        <i class="fas fa-list text-emerald-200"></i>
                    </div>
                    <div id="stat-total" class="text-2xl font-bold text-white">—</div>
                </div>
                <div class="bg-white/10 rounded-xl p-4 backdrop-blur border border-white/20 hover:bg-white/15 transition-colors">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs text-emerald-100 uppercase font-semibold">Atteints</span>
                        <i class="fas fa-check-circle text-emerald-300"></i>
                    </div>
                    <div id="stat-atteints" class="text-2xl font-bold text-emerald-300">—</div>
                </div>
                <div class="bg-white/10 rounded-xl p-4 backdrop-blur border border-white/20 hover:bg-white/15 transition-colors">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs text-emerald-100 uppercase font-semibold">En cours</span>
                        <i class="fas fa-hourglass-half text-amber-300"></i>
                    </div>
                    <div id="stat-en-cours" class="text-2xl font-bold text-amber-300">—</div>
                </div>
                <div class="bg-white/10 rounded-xl p-4 backdrop-blur border border-white/20 hover:bg-white/15 transition-colors">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs text-emerald-100 uppercase font-semibold">Agents</span>
                        <i class="fas fa-users text-emerald-200"></i>
                    </div>
                    <div id="stat-agents" class="text-2xl font-bold text-white">—</div>
                </div>
            </div>

            {{-- Barre de progression globale --}}
            <div class="mt-4 bg-white/10 rounded-xl p-4 backdrop-blur border border-white/20">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs text-emerald-100 uppercase font-semibold">
                        <i class="fas fa-flag-checkered mr-1"></i> Progression globale
                    </span>
                    <span id="progression-pct" class="text-sm font-bold text-white">0%</span>
                </div>
                <div class="h-3 bg-white/20 rounded-full overflow-hidden">
                    <div id="progression-bar" class="h-full bg-gradient-to-r from-emerald-300 to-emerald-400 transition-all duration-500" style="width: 0%"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════ --}}
    {{-- FILTRES                                                     --}}
    {{-- ═══════════════════════════════════════════════════════════ --}}
    <div class="bg-white rounded-2xl shadow-sm p-4 border border-gray-100">
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-2 px-3 py-1 bg-gray-50 rounded-lg border border-gray-200">
                <i class="fas fa-filter text-gray-400 text-xs"></i>
                <span class="text-xs font-semibold text-gray-600 uppercase">Filtres</span>
            </div>

            <select id="filter-agent" class="px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 transition-all">
                <option value="">👤 Tous les agents</option>
            </select>
            <select id="filter-statut" class="px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 transition-all">
                <option value="">📊 Tous statuts</option>
                <option value="ACTIF">🟦 Actif</option>
                <option value="ATTEINT">🟢 Atteint</option>
                <option value="DEPASSE">🟣 Dépassé</option>
                <option value="ANNULE">⚫ Annulé</option>
            </select>
            <select id="filter-type" class="px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 transition-all">
                <option value="">🏷️ Tous types</option>
                <option value="MONTANT">💰 Montant</option>
                <option value="VOLUME">📦 Volume</option>
                <option value="CLIENTS">👥 Clients</option>
                <option value="COMPTES">🏦 Comptes</option>
                <option value="VISITES">📍 Visites</option>
                <option value="PRODUIT">🛒 Produit</option>
            </select>

            <button id="btn-reset-filters"
                    class="px-3 py-2 text-sm bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 transition-colors hidden"
                    title="Réinitialiser les filtres">
                <i class="fas fa-times-circle mr-1"></i> Réinitialiser
            </button>

            <div class="flex-1"></div>

            <button id="btn-refresh" class="px-4 py-2 text-sm bg-emerald-50 text-emerald-700 rounded-lg hover:bg-emerald-100 transition-colors font-medium">
                <i class="fas fa-sync-alt mr-1"></i> Actualiser
            </button>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════ --}}
    {{-- LISTE DES OBJECTIFS (groupés par agent)                     --}}
    {{-- ═══════════════════════════════════════════════════════════ --}}
    <div class="space-y-4" id="objectifs-container">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
            <div class="inline-flex flex-col items-center gap-3">
                <div class="w-16 h-16 rounded-full bg-emerald-50 text-emerald-400 flex items-center justify-center">
                    <i class="fas fa-spinner fa-spin text-2xl"></i>
                </div>
                <span class="text-sm text-gray-500">Chargement des objectifs...</span>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/campagnes_objectifs_mesurables.js') }}?v={{ time() }}"></script>
@endpush