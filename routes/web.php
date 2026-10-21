<?php

use Illuminate\Support\Facades\Route;

// ============================================================
// ROUTES WEB (frontend uniquement — pas de controller)
// ============================================================
// Route::get('/', fn() => redirect()->route('login'));
// routes/web.php
Route::get('/login',            fn() => view('auth.login'))->name('login');
Route::get('/forgot-password',  fn() => view('auth.forgot-password'))->name('password.request');
Route::get('/reset-password/{token}', fn($token) => view('auth.reset-password', [
    'token' => $token,
    'email' => request('email', ''),
]))->name('password.reset');
Route::get('/verify-email-code', fn() => view('auth.verify-email-code'))->name('2fa.verify');

// ✅ PAS de middleware auth → la protection se fait côté JS
Route::get('/dashboard', fn() => view('dashboard'))->name('dashboard');

// Pages HTML (protégées côté JS)
Route::get('/utilisateurs', fn() => view('utilisateurs.index'))->name('utilisateurs.index');
Route::get('/roles',        fn() => view('roles.index'))->name('roles.index');
Route::get('/permissions',  fn() => view('permissions.index'))->name('permissions.index');

Route::get('/agents', fn() => view('agents.index'))->name('agents.index');
Route::get('/zones',  fn() => view('zones.index'))->name('zones.index');

Route::get('/produits-dsa', fn() => view('produits_dsa.index'))->name('produits_dsa.index');
Route::get('/partenaires', fn() => view('partenaires.index'))->name('partenaires.index');
Route::get('/regles-kyc', fn() => view('regles_kyc.index'))->name('regles-kyc.index');
Route::get('/succursales', fn() => view('succursales.index'))->name('succursales.index');
Route::get('/clients-dsa', fn() => view('clients_dsa.index'))->name('clients_dsa.index');
Route::get('/objectifs', fn() => view('objectifs.index'))->name('objectifs.index');
Route::get('/kyc-dossiers', fn() => view('kyc.index'))->name('kyc.index');
Route::get('/kyc-dossiers',      fn() => view('kyc.index'))->name('kyc.index');
Route::get('/kyc-dossiers/{id}', fn($id) => view('kyc.dossier', ['demandeId' => (int) $id]))->name('kyc.dossier');
Route::get('/demandes',          fn() => view('demandes.index'))->name('demandes.index');
Route::get('/campagnes',  fn() => view('campagnes.index'))->name('campagnes.index');
Route::get('/objectifs-agent/{campagneId}/{agentId}',fn($campagneId, $agentId) => view('objectifs.agent', [
        'campagneId' => (int) $campagneId,'agentId'    => (int) $agentId,]))->whereNumber('campagneId')->whereNumber('agentId')->name('objectifs.agent');
Route::get('/campagnes/{id}/objectifs-agents',fn($id) => view('campagnes.objectifs_agents', ['campagneId' => (int) $id])
)->whereNumber('id')->name('campagnes.objectifs-agents');
Route::get('/campagnes/{id}/objectifs-mesurables',fn($id) => view('campagnes.objectifs_mesurables', ['campagneId' => (int) $id]))->whereNumber('id')->name('campagnes.objectifs-mesurables');