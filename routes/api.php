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

use App\Http\Controllers\Partenaire\PartenaireController;
use App\Http\Controllers\Campagne\CampagneController;
use App\Http\Controllers\Zone\ZoneController;
use App\Http\Controllers\Produit\ProduitController;
use App\Http\Controllers\Produit\ProduitEpargneController;
// use App\Http\Controllers\Dsa\ZoneController;
use App\Http\Controllers\Dsa\AgentDsaController;
use App\Http\Controllers\Devise\DeviseController;
use App\Http\Controllers\Dsa\RegleKycPartenaireController;
use App\Http\Controllers\Dsa\ClientDsaController;
use App\Http\Controllers\Dsa\AgentAffectationController;
use App\Http\Controllers\Dsa\ConsentementController;
use App\Http\Controllers\Dsa\KycController;
use App\Http\Controllers\Dsa\DemandeController;
use App\Http\Controllers\Dsa\SouscriptionController;
use App\Http\Controllers\Dsa\ProduitEpargneController;
use App\Http\Controllers\Dsa\ActivationSimController;

// ============================================================
// ROUTE DE TEST
// ============================================================
Route::get('/', function () {
    return response()->json(['message' => 'API is working!']);
});

// ============================================================
// ROUTES PUBLIQUES - AUTH
// ============================================================
Route::prefix('auth')->group(function () {
    Route::post('login',              [AuthController::class, 'login'])->name('login');
    Route::post('forgot-password',    [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',     [AuthController::class, 'resetPassword']);
    Route::post('verify-reset-token', [AuthController::class, 'verifyResetToken']);
    Route::post('request-email-code', [AuthController::class, 'requestEmail2faCode']);
    Route::post('verify-email-code',  [AuthController::class, 'verifyEmail2faCode']);
});

// ============================================================
// ROUTES PROTEGEES - AUTH UNIQUEMENT
// ============================================================
Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::post('logout',  [AuthController::class, 'logout']);
    Route::get('me',       [AuthController::class, 'me']);
    Route::post('refresh', [AuthController::class, 'refresh']);
});

Route::middleware('auth:sanctum')->prefix('utilisateurs')->group(function () {
    Route::get('/',       [UtilisateurController::class, 'index']);
    Route::get('/agents', [UtilisateurController::class, 'agents']);
    Route::get('/{id}',   [UtilisateurController::class, 'show']);
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
// Route::middleware('auth:sanctum')->group(function () {
//     Route::prefix('zones')->group(function () {
//         Route::get('/',        [ZoneController::class, 'index']);
//         Route::post('/',       [ZoneController::class, 'store']);
//         Route::get('/options', [ZoneController::class, 'options']);
//         Route::get('/{id}',    [ZoneController::class, 'show'])->whereNumber('id');
//         Route::put('/{id}',    [ZoneController::class, 'update'])->whereNumber('id');
//         Route::delete('/{id}', [ZoneController::class, 'destroy'])->whereNumber('id');
//     });

//     Route::prefix('dsa/zones')->group(function () {
//         Route::get('/',        [ZoneController::class, 'index']);
//         Route::get('/options', [ZoneController::class, 'options']);
//         Route::get('/{id}',    [ZoneController::class, 'show'])->whereNumber('id');
//     });
// });

// ============================================================
// AGENTS DSA
// ============================================================
// Route::middleware('auth:sanctum')->prefix('dsa/agents')->group(function () {
//     Route::get('/',           [AgentDsaController::class, 'index']);
//     Route::post('/',          [AgentDsaController::class, 'store']);
//     Route::get('/options',    [AgentDsaController::class, 'options']);  // AVANT /{id}
//     Route::get('/{id}',       [AgentDsaController::class, 'show'])->whereNumber('id');
//     Route::put('/{id}',       [AgentDsaController::class, 'update'])->whereNumber('id');
//     Route::delete('/{id}',    [AgentDsaController::class, 'destroy'])->whereNumber('id');
//     Route::post('/{id}/statut',     [AgentDsaController::class, 'changerStatut'])->whereNumber('id');
//     Route::put('/{id}/affectation', [AgentDsaController::class, 'affecter'])->whereNumber('id');
// });
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
    Route::get('/produits-epargne', [ProduitEpargneController::class, 'index']);
    Route::get('/souscriptions/produits', [SouscriptionController::class, 'produits']);
    Route::get('/clients/{clientId}/souscriptions-eligibles', [SouscriptionController::class, 'eligibles']);
    Route::post('/souscriptions/{id}/finaliser', [SouscriptionController::class, 'finaliser']);
    Route::post('regles-kyc',     [RegleKycPartenaireController::class, 'store']);
    Route::put('regles-kyc/{id}', [RegleKycPartenaireController::class, 'update']);
    Route::post('/souscriptions', [SouscriptionController::class, 'store']);
    
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

// ═══ Activations SIM ═══
Route::middleware('auth:sanctum')->prefix('dsa/activations-sim')->group(function () {
    Route::get('/', [ActivationSimController::class, 'index']);
    Route::post('/', [ActivationSimController::class, 'store']);
    Route::get('/{id}', [ActivationSimController::class, 'show']);
    Route::get('/{id}/preuve', [ActivationSimController::class, 'telechargerPreuve']);
    Route::delete('/{id}', [ActivationSimController::class, 'destroy']);
});




























// ============================================================
// PLAINTES
// ============================================================
Route::prefix('plaintes')->name('plaintes.')->group(function () {
    Route::get('kpi',        [PlainteController::class, 'kpi'])->name('kpi');
    Route::get('kpi/detail', [PlainteController::class, 'kpiDetail'])->name('kpi.detail');
    Route::get('export/pdf',   [PlainteController::class, 'exportPdf'])->name('export.pdf');
    Route::get('export/excel', [PlainteController::class, 'exportExcel'])->name('export.excel');
    Route::get('historique', [PlainteController::class, 'historique'])->name('historique');
    Route::get('/',        [PlainteController::class, 'index'])->name('index');
    Route::post('/',       [PlainteController::class, 'store'])->name('store');
    Route::get('/{id}',    [PlainteController::class, 'show'])->name('show');
    Route::put('/{id}',    [PlainteController::class, 'update'])->name('update');
    Route::delete('/{id}', [PlainteController::class, 'destroy'])->name('destroy');
    Route::post('/{id}/affecter', [PlainteController::class, 'affecter'])->name('affecter');
    Route::post('/{id}/statut', [PlainteController::class, 'changerStatut'])->name('statut');

    Route::prefix('{plainteId}/pieces-jointes')->name('pieces.')->group(function () {
        Route::get('/',            [PlaintePieceJointeController::class, 'index'])->name('index');
        Route::post('/',           [PlaintePieceJointeController::class, 'store'])->name('store');
        Route::get('/stats',       [PlaintePieceJointeController::class, 'stats'])->name('stats');
        Route::get('/par-periode', [PlaintePieceJointeController::class, 'parPeriode'])->name('parPeriode');
        Route::delete('/all',      [PlaintePieceJointeController::class, 'destroyAll'])->name('destroyAll');
    });

    Route::prefix('{plainteId}/historiques')->name('historiques.')->group(function () {
        Route::get('/',                             [PlainteHistoriqueController::class, 'index'])->name('index');
        Route::get('/stats',                        [PlainteHistoriqueController::class, 'stats'])->name('stats');
        Route::get('/dernier',                      [PlainteHistoriqueController::class, 'dernier'])->name('dernier');
        Route::get('/action/{action}',              [PlainteHistoriqueController::class, 'parAction'])->name('parAction');
        Route::get('/utilisateur/{utilisateurId}',  [PlainteHistoriqueController::class, 'parUtilisateur'])->name('parUtilisateur');
    });
});

// ============================================================
// SUGGESTIONS
// ============================================================
Route::prefix('suggestions')->name('suggestions.')->group(function () {
    Route::get('kpi',        [SuggestionController::class, 'kpi'])->name('kpi');
    Route::get('kpi/detail', [SuggestionController::class, 'kpiDetail'])->name('kpi.detail');
    Route::get('export/pdf',   [SuggestionController::class, 'exportPdf'])->name('export.pdf');
    Route::get('export/excel', [SuggestionController::class, 'exportExcel'])->name('export.excel');
    Route::get('historique', [SuggestionController::class, 'historique'])->name('historique');
    Route::get('/',        [SuggestionController::class, 'index'])->name('index');
    Route::post('/',       [SuggestionController::class, 'store'])->name('store');
    Route::get('/{id}',    [SuggestionController::class, 'show'])->name('show');
    Route::put('/{id}',    [SuggestionController::class, 'update'])->name('update');
    Route::delete('/{id}', [SuggestionController::class, 'destroy'])->name('destroy');
    Route::post('/{id}/affecter', [SuggestionController::class, 'affecter'])->name('affecter');
    Route::post('/{id}/statut', [SuggestionController::class, 'changerStatut'])->name('statut');

    Route::prefix('{suggestionId}/pieces-jointes')->name('pieces.')->group(function () {
        Route::get('/',            [SuggestionPieceJointeController::class, 'index'])->name('index');
        Route::post('/',           [SuggestionPieceJointeController::class, 'store'])->name('store');
        Route::get('/stats',       [SuggestionPieceJointeController::class, 'stats'])->name('stats');
        Route::get('/par-periode', [SuggestionPieceJointeController::class, 'parPeriode'])->name('parPeriode');
        Route::delete('/all',      [SuggestionPieceJointeController::class, 'destroyAll'])->name('destroyAll');
    });

    Route::prefix('{suggestionId}/historiques')->name('historiques.')->group(function () {
        Route::get('/',                             [SuggestionHistoriqueController::class, 'index'])->name('index');
        Route::get('/stats',                        [SuggestionHistoriqueController::class, 'stats'])->name('stats');
        Route::get('/dernier',                      [SuggestionHistoriqueController::class, 'dernier'])->name('dernier');
        Route::get('/action/{action}',              [SuggestionHistoriqueController::class, 'parAction'])->name('parAction');
        Route::get('/utilisateur/{utilisateurId}',  [SuggestionHistoriqueController::class, 'parUtilisateur'])->name('parUtilisateur');
    });
});

// ============================================================
// PIECES JOINTES - Acces direct
// ============================================================
Route::prefix('pieces-jointes')->name('pieces-jointes.')->group(function () {
    Route::get('/{id}',          [PlaintePieceJointeController::class, 'show'])->name('show');
    Route::get('/{id}/voir',     [PlaintePieceJointeController::class, 'voir'])->name('voir');
    Route::get('/{id}/download', [PlaintePieceJointeController::class, 'download'])->name('download');
    Route::delete('/{id}',       [PlaintePieceJointeController::class, 'destroy'])->name('destroy');
});

// ============================================================
// PARTENAIRES
// ============================================================
Route::middleware('auth:sanctum')->prefix('partenaires')->name('partenaires.')->group(function () {
    Route::get('stats/globales', [PartenaireController::class, 'statistiquesGlobales'])->name('stats.globales');

    Route::get('/',        [PartenaireController::class, 'index'])->name('index');
    Route::post('/',       [PartenaireController::class, 'store'])->name('store');

    Route::get('/{partenaire}',        [PartenaireController::class, 'show'])->name('show');
    Route::put('/{partenaire}',        [PartenaireController::class, 'update'])->name('update');
    Route::patch('/{partenaire}/statut', [PartenaireController::class, 'changerStatut'])->name('statut');

    Route::get('/{partenaire}/dashboard',  [PartenaireController::class, 'dashboard'])->name('dashboard');
    Route::get('/{partenaire}/produits',   [PartenaireController::class, 'suiviProduits'])->name('produits');
    Route::get('/{partenaire}/agents',     [PartenaireController::class, 'suiviAgents'])->name('agents');
    Route::get('/{partenaire}/export',     [PartenaireController::class, 'export'])->name('export');

    Route::get('/{partenaire}/rapprochements',  [PartenaireController::class, 'rapprochements'])->name('rapprochements');
    Route::post('/{partenaire}/rapprochements', [PartenaireController::class, 'creerRapprochement'])->name('rapprochements.store');

    Route::post('/{partenaire}/produits',               [PartenaireController::class, 'attacherProduit'])->name('produits.attacher');
    Route::delete('/{partenaire}/produits/{idProduit}', [PartenaireController::class, 'detacherProduit'])->name('produits.detacher');
});

// ============================================================
// CAMPAGNES
// ============================================================
Route::prefix('campagnes')->name('campagnes.')->group(function () {
    
    // ROUTES SPECIFIQUES (DOIVENT ETRE AVANT /{campagne})
    Route::get('stats/globales', [CampagneController::class, 'statistiquesGlobales'])->name('stats.globales');
    
    // ROUTE POUR LES TYPES (AJOUTEE ICI)
    Route::get('types', [CampagneController::class, 'getTypesCampagne'])->name('types');

    // CRUD PRINCIPAL
    Route::get('/',        [CampagneController::class, 'index'])->name('index');
    Route::post('/',       [CampagneController::class, 'store'])->name('store');
    Route::get('/{campagne}',        [CampagneController::class, 'show'])->name('show');
    Route::put('/{campagne}',        [CampagneController::class, 'update'])->name('update');
    Route::delete('/{campagne}',     [CampagneController::class, 'destroy'])->name('destroy');

    // CYCLE DE VIE
    Route::patch('/{campagne}/valider',   [CampagneController::class, 'valider'])->name('valider');
    Route::patch('/{campagne}/activer',   [CampagneController::class, 'activer'])->name('activer');
    Route::patch('/{campagne}/pause',     [CampagneController::class, 'pause'])->name('pause');
    Route::patch('/{campagne}/reprendre', [CampagneController::class, 'reprendre'])->name('reprendre');
    Route::patch('/{campagne}/cloturer',  [CampagneController::class, 'cloturer'])->name('cloturer');

    // AGENTS
    Route::post('/{campagne}/agents',             [CampagneController::class, 'affecterAgent'])->name('agents.affecter');
    Route::delete('/{campagne}/agents/{idAgent}', [CampagneController::class, 'retirerAgent'])->name('agents.retirer');

    // REGLES
    Route::post('/{campagne}/regles', [CampagneController::class, 'ajouterRegle'])->name('regles.store');

    // STATISTIQUES & COMPARAISONS (pour une campagne specifique)
    Route::get('/{campagne}/stats',                 [CampagneController::class, 'statistiques'])->name('stats');
    Route::get('/{campagne}/comparaison/agents',    [CampagneController::class, 'comparaisonParAgent'])->name('comparaison.agents');
    Route::get('/{campagne}/comparaison/produits',  [CampagneController::class, 'comparaisonParProduit'])->name('comparaison.produits');
    Route::get('/{campagne}/comparaison/zones',     [CampagneController::class, 'comparaisonParZone'])->name('comparaison.zones');
    Route::get('/{campagne}/classement',            [CampagneController::class, 'classement'])->name('classement');

    // EXPORT
    Route::get('/{campagne}/export', [CampagneController::class, 'export'])->name('export');
});

// ============================================================
// ZONES
// ============================================================
Route::middleware('auth:sanctum')->prefix('zones')->name('zones.')->group(function () {
    Route::get('options', [ZoneController::class, 'options'])->name('options');
    Route::get('/',   [ZoneController::class, 'index'])->name('index');
    Route::post('/',  [ZoneController::class, 'store'])->name('store');
    Route::get('/{zone}',          [ZoneController::class, 'show'])->name('show');
    Route::put('/{zone}',          [ZoneController::class, 'update'])->name('update');
    Route::delete('/{zone}',       [ZoneController::class, 'destroy'])->name('destroy');
    Route::patch('/{zone}/statut', [ZoneController::class, 'changerStatut'])->name('statut');
});

// ============================================================
// PRODUITS
// ============================================================
Route::middleware('auth:sanctum')->prefix('produits')->name('produits.')->group(function () {
    Route::get('options',                [ProduitController::class, 'options'])->name('options');
    Route::get('generer-code-barre',     [ProduitController::class, 'genererCodeBarreUnique'])->name('generer-code-barre');
    Route::post('valider-code-barre',    [ProduitController::class, 'validerCodeBarre'])->name('valider-code-barre');
    Route::post('bulk-destroy',          [ProduitController::class, 'bulkDestroy'])->name('bulk-destroy');
    Route::post('imprimer-codes-barres', [ProduitController::class, 'imprimerCodesBarres'])->name('imprimer-codes-barres');

    Route::get('/',   [ProduitController::class, 'index'])->name('index');
    Route::post('/',  [ProduitController::class, 'store'])->name('store');

    Route::get('/{produit}',        [ProduitController::class, 'show'])->name('show');
    Route::put('/{produit}',        [ProduitController::class, 'update'])->name('update');
    Route::delete('/{produit}',     [ProduitController::class, 'destroy'])->name('destroy');

    Route::get('/{produit}/code-barre',   [ProduitController::class, 'genererCodeBarre'])->name('code-barre');
    Route::get('/{produit}/etiquette',    [ProduitController::class, 'genererEtiquette'])->name('etiquette');
    Route::get('/{produit}/images',         [ProduitController::class, 'getImages'])->name('images');
    Route::post('/{produit}/images',        [ProduitController::class, 'uploadImage'])->name('images.upload');
    Route::delete('/{produit}/images',      [ProduitController::class, 'deleteImage'])->name('images.delete');
});

// ============================================================
// PRODUITS EPARGNE
// ============================================================
Route::middleware('auth:sanctum')->prefix('produits-epargne')->name('produits-epargne.')->group(function () {
    Route::get('/',   [ProduitEpargneController::class, 'index'])->name('index');
    Route::post('/',  [ProduitEpargneController::class, 'store'])->name('store');
    Route::get('/{produitEpargne}',    [ProduitEpargneController::class, 'show'])->name('show');
    Route::put('/{produitEpargne}',    [ProduitEpargneController::class, 'update'])->name('update');
    Route::delete('/{produitEpargne}', [ProduitEpargneController::class, 'destroy'])->name('destroy');
});

// ============================================================
// OPERATIONS
// ============================================================
Route::middleware('auth:sanctum')->prefix('operations')->name('operations.')->group(function () {
    Route::post('/', [CampagneController::class, 'enregistrerOperation'])->name('store');
    Route::get('/',  [CampagneController::class, 'listerOperations'])->name('index');
});

// ============================================================
// COMMISSIONS
// ============================================================
Route::middleware('auth:sanctum')->prefix('commissions')->name('commissions.')->group(function () {
    Route::patch('/{commission}/valider', [CampagneController::class, 'validerCommission'])->name('valider');
    Route::patch('/{commission}/payer',   [CampagneController::class, 'payerCommission'])->name('payer');
    Route::patch('/{commission}/annuler', [CampagneController::class, 'annulerCommission'])->name('annuler');
});

// ============================================================
// MOBILE AGENT
// ============================================================
Route::middleware('auth:sanctum')->prefix('mobile')->name('mobile.')->group(function () {
    Route::get('dashboard', [CampagneController::class, 'mobileDashboard'])->name('dashboard');
});