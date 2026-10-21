@extends('layouts.app')

@section('title', 'Rôles')
@section('page_title', 'Gestion des rôles')

@section('content')
<div class="space-y-5">
    <div class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" id="search-input" placeholder="Rechercher un rôle..."
                       class="w-full pl-10 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
            </div>
            <button id="btn-refresh"
                    class="px-3 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button id="btn-create"
                    class="px-4 py-2 text-sm font-semibold text-white bg-gradient-to-br from-indigo-600 to-indigo-800 rounded-lg shadow-md hover:-translate-y-px transition-all">
                <i class="fas fa-plus mr-1"></i> Nouveau rôle
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="roles-grid">
        <div class="col-span-full text-center p-10 text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl"></i>
        </div>
    </div>

    <div id="pagination" class="p-2"></div>
</div>

@include('roles.modal')
@endsection

@push('scripts')
<script src="{{ asset('js/roles.js') }}?v={{ time() }}"></script>
@endpush