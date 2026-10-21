<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\PlainteSuggestion\PlainteController;
use App\Http\Controllers\PlainteSuggestion\PlainteHistoriqueController;
use App\Http\Controllers\PlainteSuggestion\PlaintePieceJointeController;
use App\Http\Controllers\PlainteSuggestion\SuggestionController;
use App\Http\Controllers\PlainteSuggestion\SuggestionHistoriqueController;
use App\Http\Controllers\PlainteSuggestion\SuggestionPieceJointeController;
use App\Http\Controllers\Utilisateur\UtilisateurController;
use App\Http\Controllers\Utilisateur\RoleController;
use App\Http\Controllers\Utilisateur\PermissionController;
use App\Http\Controllers\Succursale\SuccursaleController;
use App\Http\Controllers\Dsa\ZoneController;
use App\Http\Controllers\Dsa\AgentDsaController;
use App\Http\Controllers\Dsa\ProduitDsaController;
use App\Http\Controllers\Dsa\PartenaireController;
use App\Http\Controllers\Devise\DeviseController;
use App\Http\Controllers\Dsa\RegleKycPartenaireController;
use App\Http\Controllers\Dsa\ClientDsaController;
use App\Http\Controllers\Dsa\AgentAffectationController;
use App\Http\Controllers\Dsa\CampagneController;
use App\Http\Controllers\Dsa\ConsentementController;
use App\Http\Controllers\Dsa\KycController;
use App\Http\Controllers\Dsa\ObjectifMesurableController;
use App\Http\Controllers\Dsa\DemandeController;
use App\Http\Controllers\Dsa\CampagneObjectifController;
use App\Http\Controllers\Dsa\CampagneObjectifAgentController;

// ============================================================
// ROUTE DE TEST
// ============================================================
Route::get('/', function () {
    return response()->json(['message' => 'API is working!']);
});

// ============================================================
// ROUTES PUBLIQUES — AUTH
// ============================================================
Route::prefix('auth')->group(function () {
    Route::post('login',              [AuthController::class, 'login']);
    Route::post('forgot-password',    [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',     [AuthController::class, 'resetPassword']);
    Route::post('verify-reset-token', [AuthController::class, 'verifyResetToken']);
    Route::post('request-email-code', [AuthController::class, 'requestEmail2faCode']);
    Route::post('verify-email-code',  [AuthController::class, 'verifyEmail2faCode']);
});

// ============================================================
// RÔLES
// ============================================================
Route::middleware('auth:sanctum')->prefix('roles')->group(function () {
    Route::get('/',                  [RoleController::class, 'index']);
    Route::post('/',                 [RoleController::class, 'store']);
    Route::get('/{id}',              [RoleController::class, 'show']);
    Route::put('/{id}',              [RoleController::class, 'update']);
    Route::delete('/{id}',           [RoleController::class, 'destroy']);

    Route::post('/{id}/permissions', [RoleController::class, 'assignPermissions']);
    Route::post('/{id}/duplicate',   [RoleController::class, 'duplicate']);
    Route::get('/{id}/permissions-list', [RoleController::class, 'permissions']);
    Route::get('/niveaux/liste',     [RoleController::class, 'niveauxListe']);
});

// ============================================================
// PERMISSIONS
// ============================================================
Route::middleware('auth:sanctum')->prefix('permissions')->group(function () {
    Route::get('/',                  [PermissionController::class, 'index']);
    Route::get('/statistiques',      [PermissionController::class, 'statistiques']);
    Route::get('/modules',           [PermissionController::class, 'modules']);
    Route::get('/categories',        [PermissionController::class, 'categories']);
    Route::post('/sync',             [PermissionController::class, 'sync']);
    Route::get('/{id}',              [PermissionController::class, 'show']);
    Route::put('/{id}',              [PermissionController::class, 'update']);
    Route::delete('/{id}',           [PermissionController::class, 'destroy']);
});

// ============================================================
// SUCCURSALES
// ============================================================
Route::middleware('auth:sanctum')->prefix('succursales')->group(function () {
    Route::get('/',                [SuccursaleController::class, 'index']);
    Route::post('/',               [SuccursaleController::class, 'store']);
    Route::get('/options',         [SuccursaleController::class, 'options']);
    Route::get('/responsables-potentiels', [SuccursaleController::class, 'responsablesPotentiels']);
    Route::get('/entites-disponibles',     [SuccursaleController::class, 'entitesDisponibles']);
    Route::get('/export',          [SuccursaleController::class, 'export']);
    Route::get('/{id}',            [SuccursaleController::class, 'show'])->whereNumber('id');
    Route::put('/{id}',            [SuccursaleController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}',         [SuccursaleController::class, 'destroy'])->whereNumber('id');
    Route::post('/{id}/activer',   [SuccursaleController::class, 'activer'])->whereNumber('id');
    Route::post('/{id}/desactiver',[SuccursaleController::class, 'desactiver'])->whereNumber('id');
    Route::post('/{id}/maintenance',[SuccursaleController::class, 'maintenance'])->whereNumber('id');
    Route::post('/{id}/assign-responsable', [SuccursaleController::class, 'assignResponsable'])->whereNumber('id');
});

// ============================================================
// ZONES — legacy (/api/zones) + alias DSA (/api/dsa/zones)
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('zones')->group(function () {
        Route::get('/',        [ZoneController::class, 'index']);
        Route::post('/',       [ZoneController::class, 'store']);
        Route::get('/options', [ZoneController::class, 'options']);
        Route::get('/{id}',    [ZoneController::class, 'show'])->whereNumber('id');
        Route::put('/{id}',    [ZoneController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [ZoneController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('dsa/zones')->group(function () {
        Route::get('/',        [ZoneController::class, 'index']);
        Route::get('/options', [ZoneController::class, 'options']);
        Route::get('/{id}',    [ZoneController::class, 'show'])->whereNumber('id');
    });
});

// ============================================================
// AGENTS DSA
// ============================================================
Route::middleware('auth:sanctum')->prefix('dsa/agents')->group(function () {
    Route::get('/',           [AgentDsaController::class, 'index']);
    Route::post('/',          [AgentDsaController::class, 'store']);
    Route::get('/options',    [AgentDsaController::class, 'options']);  // AVANT /{id}
    Route::get('/{id}',       [AgentDsaController::class, 'show'])->whereNumber('id');
    Route::put('/{id}',       [AgentDsaController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}',    [AgentDsaController::class, 'destroy'])->whereNumber('id');
    Route::post('/{id}/statut',     [AgentDsaController::class, 'changerStatut'])->whereNumber('id');
    Route::put('/{id}/affectation', [AgentDsaController::class, 'affecter'])->whereNumber('id');
});

// ============================================================
// PRODUITS DSA
// ============================================================
Route::middleware('auth:sanctum')->prefix('dsa/produits')->group(function () {
    Route::get('/',                       [ProduitDsaController::class, 'index']);
    Route::post('/',                      [ProduitDsaController::class, 'store']);
    Route::get('/options',                [ProduitDsaController::class, 'options']);
    Route::get('/produits-pos-disponibles',[ProduitDsaController::class, 'produitsPosDisponibles']);
    Route::get('/{id}',                   [ProduitDsaController::class, 'show']);
    Route::put('/{id}',                   [ProduitDsaController::class, 'update']);
    Route::delete('/{id}',                [ProduitDsaController::class, 'destroy']);
});

// ============================================================
// PARTENAIRES
// ============================================================
Route::middleware('auth:sanctum')->prefix('dsa/partenaires')->group(function () {
    Route::get('/',                  [PartenaireController::class, 'index']);
    Route::post('/',                 [PartenaireController::class, 'store']);
    Route::get('/options',           [PartenaireController::class, 'options']);
    Route::get('/{id}',              [PartenaireController::class, 'show']);
    Route::put('/{id}',              [PartenaireController::class, 'update']);
    Route::delete('/{id}',           [PartenaireController::class, 'destroy']);
    Route::post('/{id}/toggle-actif',[PartenaireController::class, 'toggleActif']);
});

// ============================================================
// DEVISES
// ============================================================
Route::middleware('auth:sanctum')->prefix('devises')->group(function () {
    Route::get('/', [DeviseController::class, 'index']);
});

// ============================================================
// RÈGLES KYC PARTENAIRES
// ============================================================
Route::middleware('auth:sanctum')->prefix('dsa')->group(function () {
    Route::get('regles-kyc',      [RegleKycPartenaireController::class, 'index']);
    Route::post('regles-kyc',     [RegleKycPartenaireController::class, 'store']);
    Route::put('regles-kyc/{id}', [RegleKycPartenaireController::class, 'update']);
});

// ============================================================
// CLIENTS DSA
// ============================================================
Route::middleware('auth:sanctum')->prefix('dsa/clients')->group(function () {
    Route::get('/',                               [ClientDsaController::class, 'index']);
    Route::get('/options',                        [ClientDsaController::class, 'options']);
    Route::get('/clients-pos-disponibles',        [ClientDsaController::class, 'clientsPosDisponibles']);
    Route::post('/',                              [ClientDsaController::class, 'store']);
    Route::get('/{id}',                           [ClientDsaController::class, 'show'])->whereNumber('id');
    Route::put('/{id}',                           [ClientDsaController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}',                        [ClientDsaController::class, 'destroy'])->whereNumber('id');
    Route::post('/{id}/statut',                   [ClientDsaController::class, 'changerStatut'])->whereNumber('id');
});

// ============================================================
// CAMPAGNES — CRUD + Statut + Objectifs types + Attribution agents
// ============================================================
Route::middleware('auth:sanctum')->prefix('dsa/campagnes')->group(function () {
    // CRUD
    Route::get('/',              [CampagneController::class, 'index']);
    Route::get('/options',       [CampagneController::class, 'options']);
    Route::post('/',             [CampagneController::class, 'store']);
    Route::get('/{id}',          [CampagneController::class, 'show'])->whereNumber('id');
    Route::put('/{id}',          [CampagneController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}',       [CampagneController::class, 'destroy'])->whereNumber('id');
    Route::post('/{id}/statut',  [CampagneController::class, 'changerStatut'])->whereNumber('id');
        // Objectifs mesurables de la campagne (par agent)
    Route::get('/{id}/objectifs-mesurables', [ObjectifMesurableController::class, 'parAgentCampagne'])->whereNumber('id');

    // Objectifs types (définir / lire)
    Route::get ('/{id}/objectifs-types', [CampagneObjectifController::class, 'index'])->whereNumber('id');
    Route::post('/{id}/objectifs-types', [CampagneObjectifController::class, 'definir'])->whereNumber('id');

    // Attribution des objectifs aux agents
    Route::get ('/{id}/objectifs-agents',    [CampagneObjectifAgentController::class, 'matrice'])->whereNumber('id');
    Route::post('/{id}/attribuer-objectifs', [CampagneObjectifAgentController::class, 'attribuer'])->whereNumber('id');
});

// ============================================================
// AFFECTATIONS AGENTS
// ============================================================
Route::middleware('auth:sanctum')->prefix('dsa/agents')->group(function () {
    Route::get('/{agentId}/affectations',  [AgentAffectationController::class, 'index'])->whereNumber('agentId');
    Route::post('/{agentId}/affectations', [AgentAffectationController::class, 'store'])->whereNumber('agentId');
});

// ============================================================
// CONSENTEMENTS
// ============================================================
Route::middleware('auth:sanctum')->prefix('dsa/clients/{clientId}/consentements')->group(function () {
    Route::get('/',  [ConsentementController::class, 'index'])->whereNumber('clientId');
    Route::post('/', [ConsentementController::class, 'store'])->whereNumber('clientId');
});

// ============================================================
// OBJECTIFS MESURABLES
// ============================================================
Route::middleware('auth:sanctum')->prefix('dsa/objectifs')->group(function () {
    Route::get('/',               [ObjectifMesurableController::class, 'index']);
    Route::post('/',              [ObjectifMesurableController::class, 'store']);
    Route::get('/{id}',           [ObjectifMesurableController::class, 'show'])->whereNumber('id');
    Route::put('/{id}',           [ObjectifMesurableController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}',        [ObjectifMesurableController::class, 'destroy'])->whereNumber('id');
    Route::post('/{id}/incrementer', [ObjectifMesurableController::class, 'incrementer'])->whereNumber('id');
    Route::post('/{id}/set-realisee', [ObjectifMesurableController::class, 'setValeurRealisee'])->whereNumber('id');
    Route::get('/{id}/clients', [ObjectifMesurableController::class, 'clientsParObjectif'])->whereNumber('id');
    // Retirer une attribution (annule l'objectif mesurable)
    Route::delete('/{id}/retirer-attribution', [CampagneObjectifAgentController::class, 'retirer'])->whereNumber('id');
});

// ============================================================
// KYC — Clients DSA
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    Route::post('dsa/clients/{id}/kyc/ouvrir',   [KycController::class, 'ouvrir'])->whereNumber('id');
    Route::get ('dsa/clients/{id}/kyc/exigences',[KycController::class, 'exigences'])->whereNumber('id');
    Route::post('dsa/demandes/{id}/kyc/pieces',   [KycController::class, 'ajouterPiece'])->whereNumber('id');
    Route::post('dsa/demandes/{id}/kyc/signature',[KycController::class, 'signature'])->whereNumber('id');
    Route::post('dsa/kyc/pieces/{id}/validation', [KycController::class, 'validerPiece'])->whereNumber('id');
    Route::get ('dsa/kyc/pieces/{id}/telecharger',[KycController::class, 'telechargerPiece'])->whereNumber('id');
});

// ============================================================
// DEMANDES DSA
// ============================================================
Route::middleware('auth:sanctum')->prefix('dsa/demandes')->group(function () {
    Route::get('/',                 [DemandeController::class, 'index']);
    Route::get('/options',          [DemandeController::class, 'options']);   // AVANT /{id}
    Route::get('/{id}',             [DemandeController::class, 'show'])->whereNumber('id');
    Route::get('/{id}/historiques', [DemandeController::class, 'historiques'])->whereNumber('id');
    Route::post('/{id}/transition', [DemandeController::class, 'transitionner'])->whereNumber('id');
});

// ============================================================
// AUTH PROTÉGÉ
// ============================================================
Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::post('logout',  [AuthController::class, 'logout']);
    Route::get('me',       [AuthController::class, 'me']);
    Route::post('refresh', [AuthController::class, 'refresh']);
});

// ============================================================
// UTILISATEURS
// ============================================================
Route::middleware('auth:sanctum')->prefix('utilisateurs')->group(function () {
    Route::get('/',           [UtilisateurController::class, 'index']);
    Route::get('/agents',     [UtilisateurController::class, 'agents']);
    Route::get('/{id}',       [UtilisateurController::class, 'show']);
});

// ============================================================
// PLAINTES
// ============================================================
Route::prefix('plaintes')->name('plaintes.')->group(function () {
    Route::get('kpi',        [PlainteController::class, 'kpi'])->name('kpi');
    Route::get('kpi/detail', [PlainteController::class, 'kpiDetail'])->name('kpi.detail');

    Route::get('/',        [PlainteController::class, 'index'])->name('index');
    Route::post('/',       [PlainteController::class, 'store'])->name('store');
    Route::get('/{id}',    [PlainteController::class, 'show'])->name('show');
    Route::put('/{id}',    [PlainteController::class, 'update'])->name('update');
    Route::delete('/{id}', [PlainteController::class, 'destroy'])->name('destroy');

    Route::post('/{id}/affecter', [PlainteController::class, 'affecter'])->name('affecter');
    Route::post('/{id}/statut',   [PlainteController::class, 'changerStatut'])->name('statut');

    Route::prefix('{plainteId}/pieces-jointes')->name('pieces.')->group(function () {
        Route::get('/',            [PlaintePieceJointeController::class, 'index'])->name('index');
        Route::post('/',           [PlaintePieceJointeController::class, 'store'])->name('store');
        Route::get('/stats',       [PlaintePieceJointeController::class, 'stats'])->name('stats');
        Route::get('/par-periode', [PlaintePieceJointeController::class, 'parPeriode'])->name('parPeriode');
        Route::delete('/all',      [PlaintePieceJointeController::class, 'destroyAll'])->name('destroyAll');
    });

    Route::prefix('{plainteId}/historiques')->name('historiques.')->group(function () {
        Route::get('/',            [PlainteHistoriqueController::class, 'index'])->name('index');
        Route::get('/stats',       [PlainteHistoriqueController::class, 'stats'])->name('stats');
        Route::get('/dernier',     [PlainteHistoriqueController::class, 'dernier'])->name('dernier');
        Route::get('/action/{action}',             [PlainteHistoriqueController::class, 'parAction'])->name('parAction');
        Route::get('/utilisateur/{utilisateurId}', [PlainteHistoriqueController::class, 'parUtilisateur'])->name('parUtilisateur');
    });
});

// ============================================================
// SUGGESTIONS
// ============================================================
Route::prefix('suggestions')->name('suggestions.')->group(function () {
    Route::get('kpi',        [SuggestionController::class, 'kpi'])->name('kpi');
    Route::get('kpi/detail', [SuggestionController::class, 'kpiDetail'])->name('kpi.detail');

    Route::get('/',        [SuggestionController::class, 'index'])->name('index');
    Route::post('/',       [SuggestionController::class, 'store'])->name('store');
    Route::get('/{id}',    [SuggestionController::class, 'show'])->name('show');
    Route::put('/{id}',    [SuggestionController::class, 'update'])->name('update');
    Route::delete('/{id}', [SuggestionController::class, 'destroy'])->name('destroy');

    Route::post('/{id}/affecter', [SuggestionController::class, 'affecter'])->name('affecter');
    Route::post('/{id}/statut',   [SuggestionController::class, 'changerStatut'])->name('statut');

    Route::prefix('{suggestionId}/pieces-jointes')->name('pieces.')->group(function () {
        Route::get('/',            [SuggestionPieceJointeController::class, 'index'])->name('index');
        Route::post('/',           [SuggestionPieceJointeController::class, 'store'])->name('store');
        Route::get('/stats',       [SuggestionPieceJointeController::class, 'stats'])->name('stats');
        Route::get('/par-periode', [SuggestionPieceJointeController::class, 'parPeriode'])->name('parPeriode');
        Route::delete('/all',      [SuggestionPieceJointeController::class, 'destroyAll'])->name('destroyAll');
    });

    Route::prefix('{suggestionId}/historiques')->name('historiques.')->group(function () {
        Route::get('/',            [SuggestionHistoriqueController::class, 'index'])->name('index');
        Route::get('/stats',       [SuggestionHistoriqueController::class, 'stats'])->name('stats');
        Route::get('/dernier',     [SuggestionHistoriqueController::class, 'dernier'])->name('dernier');
        Route::get('/action/{action}',             [SuggestionHistoriqueController::class, 'parAction'])->name('parAction');
        Route::get('/utilisateur/{utilisateurId}', [SuggestionHistoriqueController::class, 'parUtilisateur'])->name('parUtilisateur');
    });
});

// ============================================================
// PIÈCES JOINTES — Accès direct
// ============================================================
Route::prefix('pieces-jointes')->name('pieces-jointes.')->group(function () {
    Route::get('/{id}',          [PlaintePieceJointeController::class, 'show'])->name('show');
    Route::get('/{id}/voir',     [PlaintePieceJointeController::class, 'voir'])->name('voir');
    Route::get('/{id}/download', [PlaintePieceJointeController::class, 'download'])->name('download');
    Route::delete('/{id}',       [PlaintePieceJointeController::class, 'destroy'])->name('destroy');
});