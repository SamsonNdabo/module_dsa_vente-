<?php
// app/Http/Controllers/Client/ClientController.php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\KycDocument;
use App\Services\ClientService;
use Illuminate\Http\Request;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\ClientNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    protected ClientService $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'nom_complet' => 'required|string|max:255',
                'email' => 'required|email|unique:clients,email',
                'telephone' => 'nullable|string|max:20',
                'adresse' => 'nullable|string',
                'categorie_client' => 'nullable|in:STANDARD,PREMIUM,VIP',
                'statut' => 'nullable|in:ACTIF,INACTIF,BLOQUE,EN_ATTENTE'
            ]);

            $client = $this->clientService->creerClient($request->all());

            if ($request->filled('statut') && $request->statut !== 'EN_ATTENTE') {
                if ($request->statut === 'ACTIF') {
                    $client->activer();
                } elseif ($request->statut === 'INACTIF') {
                    $client->desactiver();
                } elseif ($request->statut === 'BLOQUE') {
                    $client->bloquer();
                }
            }

            // ✅ Créer une notification pour le client - Utilisation d'URL directe
            $this->createNotification($client, [
                'type' => 'CREATION_CLIENT',
                'message' => 'Votre compte client a été créé avec succès.',
                'lien' => url('/clients/' . $client->id) // ✅ URL directe au lieu de route()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Client créé avec succès. Un email contenant ses identifiants lui a été envoyé.',
                'data' => $client->fresh(['kycDocuments'])
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur création client', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $client = Client::findOrFail($id);

            if ($client->factures()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer un client qui a des factures'
                ], 422);
            }

            foreach ($client->kycDocuments as $doc) {
                if ($doc->file_path && Storage::disk('public')->exists($doc->file_path)) {
                    Storage::disk('public')->delete($doc->file_path);
                }
            }

            $client->delete();

            return response()->json([
                'success' => true,
                'message' => 'Client supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur suppression client', [
                'client_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            // ✅ Sélection des colonnes nécessaires
            $query = Client::select([
                'id', 'code_client', 'nom_complet', 'email', 'telephone',
                'adresse', 'categorie_client', 'statut', 'nombre_achats',
                'total_achats', 'date_dernier_achat', 'dernier_connexion',
                'created_at', 'updated_at'
            ])->withCount('kycDocuments');

            if ($request->filled('statut')) {
                $query->where('statut', $request->statut);
            }

            if ($request->filled('categorie')) {
                $query->where('categorie_client', $request->categorie);
            }

            if ($request->boolean('kyc_incomplet')) {
                $query->kycIncomplet();
            }

            if ($request->filled('recherche')) {
                $search = $request->recherche;
                $query->where(function($q) use ($search) {
                    $q->where('nom_complet', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('telephone', 'like', "%{$search}%")
                      ->orWhere('code_client', 'like', "%{$search}%");
                });
            }

            $clients = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            // ✅ Statistiques en UNE SEULE REQUÊTE
            $stats = $this->getClientStats();

            return response()->json([
                'success' => true,
                'data' => $clients,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur liste clients', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $client = Client::select([
                'id', 'code_client', 'nom_complet', 'email', 'telephone',
                'adresse', 'categorie_client', 'statut', 'nombre_achats',
                'total_achats', 'date_dernier_achat', 'dernier_connexion',
                'created_at', 'updated_at'
            ])->with([
                'kycDocuments' => function($q) {
                    $q->select('id', 'client_id', 'type_document', 'numero_document', 'statut', 'file_path', 'created_at');
                },
                'historiqueConnexions' => function($q) {
                    $q->select('id', 'client_id', 'ip_address', 'login_at', 'success')
                      ->latest()
                      ->limit(10);
                },
                'factures' => function($q) {
                    $q->select('id', 'client_id', 'reference', 'total_ttc', 'statut', 'date_facture')
                      ->latest()
                      ->limit(10);
                }
            ])->findOrFail($id);

            $statistiques = $this->clientService->getStatistiques($client);

            return response()->json([
                'success' => true,
                'data' => $client,
                'statistiques' => $statistiques
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur affichage client', [
                'client_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $client = Client::findOrFail($id);

            $request->validate([
                'nom_complet' => 'sometimes|string|max:255',
                'telephone' => 'nullable|string|max:20',
                'adresse' => 'nullable|string',
                'categorie_client' => 'sometimes|in:STANDARD,PREMIUM,VIP'
            ]);

            $client->update($request->only([
                'nom_complet', 'telephone', 'adresse', 'categorie_client'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Client mis à jour',
                'data' => $client->fresh()
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur mise à jour client', [
                'client_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ Créer une notification pour le client
     */
    protected function createNotification(Client $client, array $data): void
    {
        try {
            // Vérifier si le modèle ClientNotification existe
            if (class_exists('App\Models\ClientNotification')) {
                ClientNotification::create([
                    'client_id' => $client->id,
                    'type' => $data['type'],
                    'message' => $data['message'],
                    'lien' => $data['lien'] ?? null,
                    'statut' => 'NON_LU'
                ]);
            } else {
                // Fallback: Log uniquement si le modèle n'existe pas
                Log::info('Notification client (modèle inexistant)', [
                    'client_id' => $client->id,
                    'message' => $data['message']
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Erreur création notification client', [
                'client_id' => $client->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Statistiques des clients - UNE SEULE REQUÊTE
     */
    private function getClientStats(): array
    {
        $stats = Client::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN statut = 'ACTIF' THEN 1 ELSE 0 END) as actifs,
            SUM(CASE WHEN statut = 'EN_ATTENTE' THEN 1 ELSE 0 END) as en_attente,
            SUM(CASE WHEN statut = 'BLOQUE' THEN 1 ELSE 0 END) as bloques,
            SUM(CASE WHEN statut = 'INACTIF' THEN 1 ELSE 0 END) as inactifs
        ")->first();

        $parCategorie = Client::select('categorie_client', DB::raw('count(*) as total'))
            ->groupBy('categorie_client')
            ->get()
            ->mapWithKeys(fn($item) => [$item->categorie_client => $item->total]);

        return [
            'total' => (int) $stats->total,
            'actifs' => (int) $stats->actifs,
            'en_attente' => (int) $stats->en_attente,
            'bloques' => (int) $stats->bloques,
            'inactifs' => (int) $stats->inactifs,
            'par_categorie' => [
                'STANDARD' => $parCategorie['STANDARD'] ?? 0,
                'PREMIUM' => $parCategorie['PREMIUM'] ?? 0,
                'VIP' => $parCategorie['VIP'] ?? 0
            ]
        ];
    }
}