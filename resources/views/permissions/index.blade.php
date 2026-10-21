@extends('layouts.app')

@section('title', 'Permissions')
@section('page_title', 'Permissions système')

@section('content')
<div class="space-y-5">

    {{-- Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-indigo-600 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Total</div>
            <div id="stat-total" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-amber-500 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Système</div>
            <div id="stat-systeme" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-emerald-500 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Assignées</div>
            <div id="stat-assignees" class="text-2xl font-bold text-gray-900">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-gray-400 p-4">
            <div class="text-xs text-gray-500 uppercase font-semibold">Non assignées</div>
            <div id="stat-non-assignees" class="text-2xl font-bold text-gray-900">—</div>
        </div>
    </div>

    {{-- Filtres --}}
    <div class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex flex-wrap items-center gap-3">
            <input type="text" id="search-input" placeholder="Rechercher..."
                   class="flex-1 min-w-[200px] px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-indigo-500">
            <select id="filter-module" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Tous les modules</option>
            </select>
            <select id="filter-action" class="px-3 py-2 text-sm border border-gray-200 rounded-lg">
                <option value="">Toutes les actions</option>
            </select>
        </div>
    </div>

    {{-- Liste --}}
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-600 font-bold">
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Module</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">Description</th>
                        <th class="px-4 py-3">Rôles</th>
                        <th class="px-4 py-3">Type</th>
                    </tr>
                </thead>
                <tbody id="table-body" class="divide-y divide-gray-100">
                    <tr><td colspan="6" class="p-10 text-center text-gray-400">
                        <i class="fas fa-spinner fa-spin text-2xl"></i>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/permissions.js') }}?v={{ time() }}"></script>
@endpush