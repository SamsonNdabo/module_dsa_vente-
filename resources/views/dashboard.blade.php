@extends('layouts.app')

@section('title', 'Tableau de bord')
@section('page_title', 'Tableau de bord')

@section('content')
<div class="space-y-6">

    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">
            Bienvenue <span id="greeting-name" class="text-indigo-600">...</span>
        </h2>
        <p class="text-sm text-gray-500 mt-1">Voici votre espace de travail</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-indigo-600 p-5">
            <div class="text-xs text-gray-500 uppercase font-semibold">Email</div>
            <div id="info-email" class="text-lg font-bold text-gray-900 mt-1">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-emerald-500 p-5">
            <div class="text-xs text-gray-500 uppercase font-semibold">Rôle</div>
            <div id="info-role" class="text-lg font-bold text-gray-900 mt-1">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-amber-500 p-5">
            <div class="text-xs text-gray-500 uppercase font-semibold">Succursale</div>
            <div id="info-succursale" class="text-lg font-bold text-gray-900 mt-1">—</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-red-500 p-5">
            <div class="text-xs text-gray-500 uppercase font-semibold">2FA Email</div>
            <div id="info-2fa" class="text-lg font-bold text-gray-900 mt-1">—</div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(async function () {
    if (!API.isAuthenticated()) {
        window.location.href = window.APP_BASE + '/login';
        return;
    }
    const res = await API.me();
    if (!res.ok) {
        API.clearToken();
        window.location.href = window.APP_BASE + '/login';
        return;
    }
    const u = res.data.user || res.data;
    const nom = u.nom_complet || u.nom_utilisateur || 'Utilisateur';

    document.getElementById('greeting-name').textContent = nom;
    document.getElementById('info-email').textContent = u.email || '—';
    document.getElementById('info-role').textContent = u.role?.nom_role || '—';
    document.getElementById('info-succursale').textContent = u.succursale?.nom || '—';
    document.getElementById('info-2fa').textContent = u.two_factor_email_enabled ? 'Activé' : 'Désactivé';
})();
</script>
@endpush