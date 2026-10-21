@extends('layouts.auth')

@section('title', 'Connexion')

@section('content')
<div class="bg-white rounded-2xl shadow-2xl p-8 sm:p-10 relative overflow-hidden">

    <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-primary-500 via-purple-500 to-primary-700"></div>

    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl
                    bg-gradient-to-br from-primary-600 to-primary-800
                    text-white text-3xl shadow-lg shadow-primary-500/30 mb-4">
            <i class="fas fa-lock"></i>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">Bienvenue</h1>
        <p class="text-sm text-gray-500 mt-1">Connectez-vous pour continuer</p>
    </div>

    <div id="alerts-container"></div>

    <form id="form-login" novalidate class="space-y-5">
        <div>
            <label for="nom_utilisateur" class="block text-sm font-semibold text-gray-700 mb-1.5">
                Email ou nom d'utilisateur
            </label>
            <div class="relative">
                <i class="fas fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" id="nom_utilisateur" name="nom_utilisateur"
                       placeholder="jean.dupont@exemple.com"
                       class="w-full pl-10 pr-4 py-3 text-sm bg-white border border-gray-200 rounded-lg
                              focus:border-primary-500 focus:ring-4 focus:ring-primary-500/15
                              focus:outline-none transition"
                       required autofocus autocomplete="username">
            </div>
        </div>

        <div>
            <label for="mot_de_passe" class="block text-sm font-semibold text-gray-700 mb-1.5">
                Mot de passe
            </label>
            <div class="relative">
                <i class="fas fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="password" id="mot_de_passe" name="mot_de_passe"
                       placeholder="••••••••"
                       class="w-full pl-10 pr-11 py-3 text-sm bg-white border border-gray-200 rounded-lg
                              focus:border-primary-500 focus:ring-4 focus:ring-primary-500/15
                              focus:outline-none transition"
                       required autocomplete="current-password">
                <button type="button"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 transition"
                        data-toggle-password="mot_de_passe">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
        </div>

        <div class="flex items-center justify-between gap-4">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" id="remember" name="remember"
                       class="w-4 h-4 rounded accent-primary-600 cursor-pointer">
                <span class="text-sm text-gray-600">Se souvenir</span>
            </label>
            <a href="{{ route('password.request') }}"
               class="text-sm font-medium text-primary-600 hover:text-primary-700 transition">
                Mot de passe oublié ?
            </a>
        </div>

        <button type="submit"
                class="w-full flex items-center justify-center gap-2 py-3 px-4 text-sm font-semibold text-white
                       bg-gradient-to-br from-primary-600 to-primary-800 rounded-lg
                       shadow-lg shadow-primary-500/30
                       hover:from-primary-700 hover:to-primary-900 hover:shadow-primary-500/40
                       hover:-translate-y-px active:translate-y-0
                       disabled:opacity-60 disabled:cursor-not-allowed
                       transition-all">
            <span class="spinner hidden w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
            <i class="fas fa-sign-in-alt"></i>
            <span>Se connecter</span>
        </button>
    </form>
</div>
@endsection