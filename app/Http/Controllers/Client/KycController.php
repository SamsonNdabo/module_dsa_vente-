<?php
// app/Http/Controllers/Client/KycController.php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\Client;
use App\Models\ClientNotification;
use App\Services\ClientService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KycController extends Controller
{
    protected ClientService $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    /**
     * Liste des documents KYC - OPTIMISÉ
     * GET /api/kyc
     */
    public function index(Request $request)
    {
        try {
            $query = KycDocument::select([
                'id', 'client_id', 'type_document', 'numero_document',
                'file_path', 'statut', 'verifie_par_id', 'motif_rejet',
                'metadata', 'created_at', 'updated_at'
            ])->with([
                'client' => function($q) {
                    $q->select('id', 'code_client', 'nom_complet', 'email', 'telephone', 'statut');
                },
                'verifiePar' => function($q) {
                    $q->select('id', 'nom_complet', 'email');
                }
            ]);

            // Filtres
            if ($request->filled('statut')) {
                $query->where('statut', $request->statut);
            }

            if ($request->filled('type_document')) {
                $query->where('type_document', $request->type_document);
            }

            if ($request->filled('client_id')) {
                $query->where('client_id', $request->client_id);
            }

            if ($request->filled('date_debut')) {
                $query->whereDate('created_at', '>=', $request->date_debut);
            }

            if ($request->filled('date_fin')) {
                $query->whereDate('created_at', '<=', $request->date_fin);
            }

            if ($request->filled('recherche')) {
                $search = $request->recherche;
                $query->whereHas('client', function($q) use ($search) {
                    $q->where('nom_complet', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('code_client', 'like', "%{$search}%");
                });
            }

            $documents = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            $stats = $this->getKycStats();

            return response()->json([
                'success' => true,
                'data' => $documents,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur liste KYC', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE - UPLOADER UN DOCUMENT KYC (PDF, JPG, PNG)
     * POST /api/kyc
     * 
     *  Supporte l'upload de fichiers PDF, JPG, PNG
     *  Stocke le fichier dans storage/app/public/kyc
     */
    public function store(Request $request)
    {
        try {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'client_id' => 'required|exists:clients,id',
                'type_document' => 'required|string|in:CIN,PASSEPORT,ATTESTATION',
                'numero_document' => 'nullable|string|max:100',
                //  Fichier obligatoire
                'fichier' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB max
                'metadata' => 'nullable|array',
                'notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $client = Client::findOrFail($request->client_id);

            // Vérifier si un document du même type existe déjà en attente ou vérifié
            $existing = KycDocument::where('client_id', $client->id)
                ->where('type_document', $request->type_document)
                ->whereIn('statut', ['EN_ATTENTE', 'VERIFIE'])
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => "Un document de type {$request->type_document} existe déjà pour ce client",
                    'data' => [
                        'document_existant' => [
                            'id' => $existing->id,
                            'statut' => $existing->statut
                        ]
                    ]
                ], 409);
            }

            DB::beginTransaction();

            //  Upload du fichier
            $file = $request->file('fichier');
            $extension = $file->getClientOriginalExtension();
            $fileName = 'kyc_' . Str::uuid() . '.' . $extension;
            $path = $file->storeAs('kyc', $fileName, 'public');

            if (!$path) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de l\'upload du fichier'
                ], 500);
            }

            //  Création du document
            $document = KycDocument::create([
                'client_id' => $client->id,
                'type_document' => $request->type_document,
                'numero_document' => $request->numero_document,
                'file_path' => $path,
                'statut' => 'EN_ATTENTE',
                'motif_rejet' => null,
                'verifie_par_id' => null,
                'metadata' => array_merge($request->metadata ?? [], [
                    'filename_original' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'file_mime' => $file->getMimeType(),
                    'uploaded_by' => auth()->id(),
                    'uploaded_at' => now()->toDateTimeString(),
                    'client_notes' => $request->notes,
                ])
            ]);

            //  Mise à jour du statut du client
            $client->statut = 'EN_ATTENTE';
            $client->save();

            //  Notification
            $this->createNotification(
                $client,
                'KYC_UPLOAD',
                '📄 Document KYC soumis',
                "Votre document de type {$request->type_document} a été soumis avec succès. Il sera vérifié par notre équipe.",
                [
                    'document_id' => $document->id,
                    'type_document' => $request->type_document,
                    'date_soumission' => now()->toDateTimeString()
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document KYC soumis avec succès',
                'data' => [
                    'document' => $document->fresh(['client']),
                    'client' => [
                        'id' => $client->id,
                        'statut' => $client->statut,
                        'kyc_complet' => $client->kyc_complet
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur upload KYC', [
                'client_id' => $request->client_id ?? null,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Détail d'un document KYC - OPTIMISÉ
     * GET /api/kyc/{id}
     */
    public function show($id)
    {
        try {
            $document = KycDocument::select([
                'id', 'client_id', 'type_document', 'numero_document',
                'file_path', 'statut', 'verifie_par_id', 'motif_rejet',
                'metadata', 'created_at', 'updated_at'
            ])->with([
                'client' => function($q) {
                    $q->select('id', 'code_client', 'nom_complet', 'email', 'telephone', 'statut');
                },
                'verifiePar' => function($q) {
                    $q->select('id', 'nom_complet', 'email');
                }
            ])->findOrFail($id);

            $autresDocuments = KycDocument::where('client_id', $document->client_id)
                ->where('id', '!=', $id)
                ->select(['id', 'type_document', 'statut', 'created_at'])
                ->get();

            //  Générer l'URL du fichier
            $fileUrl = null;
            if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
                $fileUrl = Storage::disk('public')->url($document->file_path);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'document' => $document,
                    'file_url' => $fileUrl,
                    'autres_documents' => $autresDocuments,
                    'client' => $document->client,
                    'statut_kyc_client' => $document->client->kyc_complet
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur affichage document KYC', [
                'document_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 404);
        }
    }


    /**
 * Télécharger le fichier d'un document KYC
 * GET /api/kyc/{id}/download
 */
public function download($id)
{
    try {

        $document = KycDocument::findOrFail($id);

        // ============================================================
        // VÉRIFICATION DES PERMISSIONS
        // ============================================================

        $user = auth()->user();

        if (
            !$user->hasPermission('view-kyc') &&
            $document->client_id != $user->client_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé'
            ], 403);
        }

        // ============================================================
        // VÉRIFICATION DU CHEMIN
        // ============================================================

        if (!$document->file_path) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun fichier associé à ce document'
            ], 404);
        }

        /*
         * IMPORTANT
         *
         * file_path doit contenir par exemple :
         *
         * kyc/document_123.pdf
         *
         * et non :
         *
         * storage/app/public/kyc/document_123.pdf
         */

        $relativePath = ltrim(
            str_replace('\\', '/', $document->file_path),
            '/'
        );

        // ============================================================
        // DISQUE PUBLIC
        // ============================================================

        $disk = Storage::disk('public');

        if (!$disk->exists($relativePath)) {

            Log::error('Fichier KYC introuvable', [
                'document_id' => $document->id,
                'file_path' => $relativePath,
                'storage_root' => $disk->path($relativePath),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Fichier KYC introuvable'
            ], 404);
        }

        // ============================================================
        // CHEMIN ABSOLU
        // ============================================================

        $fullPath = $disk->path($relativePath);

        if (!is_file($fullPath) || !is_readable($fullPath)) {

            return response()->json([
                'success' => false,
                'message' => 'Le fichier KYC n\'est pas accessible'
            ], 403);
        }

        // ============================================================
        // NOM ORIGINAL
        // ============================================================

        $metadata = $document->metadata;

        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }

        if (!is_array($metadata)) {
            $metadata = [];
        }

        $originalName =
            $metadata['filename_original']
            ?? basename($relativePath);

        /*
         * Éviter qu'un nom de fichier fourni en metadata
         * contienne un chemin.
         */
        $originalName = basename($originalName);

        // ============================================================
        // TYPE MIME RÉEL
        // ============================================================

        $mimeType = $disk->mimeType($relativePath);

        if (!$mimeType && function_exists('mime_content_type')) {
            $mimeType = mime_content_type($fullPath);
        }

        $mimeType = $mimeType ?: 'application/octet-stream';

        // ============================================================
        // LOG
        // ============================================================

        Log::info('Téléchargement fichier KYC', [
            'document_id' => $document->id,
            'file_path' => $relativePath,
            'full_path' => $fullPath,
            'mime_type' => $mimeType,
            'filename' => $originalName,
        ]);

        // ============================================================
        // TÉLÉCHARGEMENT
        // ============================================================

        return response()->download(
            $fullPath,
            $originalName,
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]
        );

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {

        return response()->json([
            'success' => false,
            'message' => 'Document KYC introuvable'
        ], 404);

    } catch (\Throwable $e) {

        Log::error('Erreur téléchargement KYC', [
            'document_id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors du téléchargement du fichier'
        ], 500);
    }
}
    /**
     * Vérifier et approuver un document KYC
     * POST /api/kyc/{id}/verify
     */
    public function verify(Request $request, $id)
    {
        try {
            $request->validate([
                'notes' => 'nullable|string|max:500'
            ]);

            DB::beginTransaction();

            $document = KycDocument::findOrFail($id);

            if ($document->statut !== 'EN_ATTENTE') {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce document a déjà été traité'
                ], 422);
            }

            $document->verifier(auth()->id(), null);

            if ($request->filled('notes')) {
                $metadata = $document->metadata ?? [];
                $metadata['notes_verification'] = $request->notes;
                $metadata['verifie_le'] = now()->toDateTimeString();
                $metadata['verifie_par'] = auth()->user()->nom_complet;
                $document->metadata = $metadata;
                $document->save();
            }

            $client = $document->client;
            $kycComplet = $client->kyc_complet;

            if ($kycComplet && $client->statut === 'EN_ATTENTE') {
                $client->activer();
                $this->createNotification(
                    $client,
                    'KYC_VALIDE',
                    ' KYC validé',
                    'Félicitations ! Votre dossier KYC a été validé. Votre compte est maintenant actif.',
                    ['date_validation' => now()->toDateTimeString()]
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document vérifié avec succès',
                'data' => [
                    'document' => $document->fresh(),
                    'client' => $client,
                    'kyc_complet' => $kycComplet,
                    'client_active' => $client->statut === 'ACTIF'
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur vérification KYC', [
                'document_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Rejeter un document KYC
     * POST /api/kyc/{id}/reject
     */
    public function reject(Request $request, $id)
    {
        try {
            $request->validate([
                'motif_rejet' => 'required|string|min:3|max:500',
                'notes' => 'nullable|string|max:500'
            ]);

            DB::beginTransaction();

            $document = KycDocument::findOrFail($id);

            if ($document->statut !== 'EN_ATTENTE') {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce document a déjà été traité'
                ], 422);
            }

            $document->verifier(auth()->id(), $request->motif_rejet);

            $metadata = $document->metadata ?? [];
            $metadata['notes_rejet'] = $request->notes;
            $metadata['rejete_le'] = now()->toDateTimeString();
            $metadata['rejete_par'] = auth()->user()->nom_complet;
            $document->metadata = $metadata;
            $document->save();

            $this->createNotification(
                $document->client,
                'KYC_REJETE',
                '❌ Document KYC rejeté',
                "Votre document {$document->type_document} a été rejeté. Motif: {$request->motif_rejet}",
                [
                    'document_id' => $document->id,
                    'type_document' => $document->type_document,
                    'motif' => $request->motif_rejet,
                    'date_rejet' => now()->toDateTimeString()
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Document rejeté',
                'data' => [
                    'document' => $document->fresh(),
                    'client' => $document->client
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur rejet KYC', [
                'document_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer un document KYC
     * DELETE /api/kyc/{id}
     */
    public function destroy($id)
    {
        try {
            $document = KycDocument::findOrFail($id);

            if ($document->statut === 'VERIFIE') {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer un document déjà vérifié'
                ], 422);
            }

            if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
                Storage::disk('public')->delete($document->file_path);
            }

            $document->delete();

            return response()->json([
                'success' => true,
                'message' => 'Document supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur suppression document KYC', [
                'document_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vérification en masse de plusieurs documents
     * POST /api/kyc/bulk-verify
     */
    public function bulkVerify(Request $request)
    {
        try {
            $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'exists:kyc_documents,id'
            ]);

            DB::beginTransaction();

            $documents = KycDocument::whereIn('id', $request->ids)
                ->where('statut', 'EN_ATTENTE')
                ->get();

            $compteur = 0;
            $clientsActives = [];

            foreach ($documents as $document) {
                $document->verifier(auth()->id(), null);
                $compteur++;

                $client = $document->client;
                if ($client->kyc_complet && $client->statut === 'EN_ATTENTE') {
                    $client->activer();
                    $clientsActives[] = $client->id;
                    
                    $this->createNotification(
                        $client,
                        'KYC_VALIDE',
                        ' KYC validé',
                        'Félicitations ! Votre dossier KYC a été validé. Votre compte est maintenant actif.',
                        ['date_validation' => now()->toDateTimeString()]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$compteur} document(s) vérifié(s) avec succès",
                'data' => [
                    'verifies' => $compteur,
                    'clients_actives' => $clientsActives
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur vérification en masse KYC', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les documents en attente par client - OPTIMISÉ
     * GET /api/kyc/clients/en-attente
     */
    public function clientsEnAttente(Request $request)
    {
        try {
            $clients = Client::where('statut', 'EN_ATTENTE')
                ->select(['id', 'code_client', 'nom_complet', 'email', 'telephone', 'statut'])
                ->with(['kycDocuments' => function($q) {
                    $q->where('statut', 'EN_ATTENTE')
                      ->select(['id', 'client_id', 'type_document', 'created_at']);
                }])
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'data' => $clients
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération clients en attente', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Statistiques KYC - UNE SEULE REQUÊTE
     */
    private function getKycStats(): array
    {
        $stats = KycDocument::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN statut = 'EN_ATTENTE' THEN 1 ELSE 0 END) as en_attente,
            SUM(CASE WHEN statut = 'VERIFIE' THEN 1 ELSE 0 END) as verifies,
            SUM(CASE WHEN statut = 'REJETE' THEN 1 ELSE 0 END) as rejetes
        ")->first();

        $parType = KycDocument::select('type_document', DB::raw('count(*) as total'))
            ->groupBy('type_document')
            ->get()
            ->mapWithKeys(fn($item) => [$item->type_document => $item->total]);

        $clientsEnAttente = Client::where('statut', 'EN_ATTENTE')->count();
        $clientsKycComplet = Client::whereHas('kycDocuments', function($q) {
            $q->where('statut', 'VERIFIE')
              ->whereIn('type_document', ['CIN', 'ATTESTATION']);
        })->count();

        return [
            'total' => (int) $stats->total,
            'en_attente' => (int) $stats->en_attente,
            'verifies' => (int) $stats->verifies,
            'rejetes' => (int) $stats->rejetes,
            'par_type' => [
                'CIN' => $parType['CIN'] ?? 0,
                'PASSEPORT' => $parType['PASSEPORT'] ?? 0,
                'ATTESTATION' => $parType['ATTESTATION'] ?? 0,
            ],
            'clients_en_attente' => $clientsEnAttente,
            'clients_kyc_complet' => $clientsKycComplet
        ];
    }

    /**
     * Créer une notification - MÉTHODE CENTRALISÉE
     */
    private function createNotification($client, string $type, string $titre, string $message, array $data = []): void
    {
        if ($client instanceof Client) {
            ClientNotification::creer($client, $type, $titre, $message, $data);
        } elseif (is_numeric($client)) {
            $client = Client::find($client);
            if ($client) {
                ClientNotification::creer($client, $type, $titre, $message, $data);
            }
        }
    }
}