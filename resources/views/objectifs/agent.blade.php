@extends('layouts.app')

@section('title', 'Mes objectifs')
@section('page_title', 'Mes objectifs')

@section('content')
<div class="space-y-5" id="objectifs-agent-page"
     data-campagne-id="{{ $campagneId ?? '' }}"
     data-agent-id="{{ $agentId ?? '' }}">

    {{-- Header --}}
    <div class="bg-gradient-to-br from-indigo-600 to-indigo-800 rounded-2xl shadow-lg p-6">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center">
                <i class="fas fa-bullseye text-white text-2xl"></i>
            </div>
            <div>
                <div class="text-xs text-indigo-200 uppercase font-semibold tracking-wider">Objectifs mesurables</div>
                <div class="text-2xl font-bold text-white mt-1">Suivi de vos objectifs</div>
                <div id="campagne-nom" class="text-sm text-indigo-100 mt-1">—</div>
            </div>
        </div>
    </div>

    {{-- Liste des objectifs --}}
    <div id="objectifs-list" class="space-y-3">
        <div class="text-sm text-gray-500 italic text-center p-6">Chargement...</div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/objectifs_agent.js') }}?v={{ time() }}"></script>
@endpush