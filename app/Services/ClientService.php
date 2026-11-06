<?php
// app/Services/ClientService.php

namespace App\Services;

use App\Models\Client;
use App\Models\KycDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\ClientWelcomeMail;

class ClientService
{
    public function creerClient(array $data): Client
    {
        return DB::transaction(function () use ($data) {
            $client = Client::create([
                'nom_complet' => $data['nom_complet'],
                'email' => $data['email'],
                'telephone' => $data['telephone'] ?? null,
                'adresse' => $data['adresse'] ?? null,
                'categorie_client' => $data['categorie_client'] ?? 'STANDARD',
                'statut' => 'EN_ATTENTE'
            ]);
            
            $client->save();
            
            
            return $client;
        });
    }
    
    private function envoyerEmailBienvenue(Client $client, string $motDePasseTemp): void
    {   
        Mail::to($client->email)->send(new ClientWelcomeMail($client, $motDePasseTemp));
    }
    
    public function authentifier(string $email, string $password, string $ip): ?Client
    {
        $client = Client::where('email', $email)->first();
        
        if (!$client) {
            return null;
        }
        
        if (!Hash::check($password, $client->password)) {
            $client->enregistrerConnexion($ip, false);
            return null;
        }
        
        if (in_array($client->statut, ['BLOQUE', 'INACTIF'])) {
            $client->enregistrerConnexion($ip, false);
            return null;
        }
        
        $client->enregistrerConnexion($ip, true);
        
        return $client;
    }
    
    public function uploadDocumentKyc(int $clientId, string $typeDocument, $file, ?string $numeroDocument = null): KycDocument
    {
        return DB::transaction(function () use ($clientId, $typeDocument, $file, $numeroDocument) {
            $client = Client::findOrFail($clientId);
            
            $path = $file->store('kyc/' . $clientId, 'public');
            
            $document = KycDocument::create([
                'client_id' => $clientId,
                'type_document' => $typeDocument,
                'numero_document' => $numeroDocument,
                'file_path' => $path,
                'statut' => 'EN_ATTENTE'
            ]);
            
            return $document;
        });
    }
    
    public function verifierDocument(int $documentId, int $verifieParId, bool $valider, ?string $motifRejet = null): KycDocument
    {
        $document = KycDocument::findOrFail($documentId);
        $document->verifier($verifieParId, $valider ? null : $motifRejet);
        
        if ($valider && $document->client->kyc_complet && $document->client->statut === 'EN_ATTENTE') {
            $document->client->activer();
        }
        
        return $document;
    }
    
    public function changerMotDePasse(int $clientId, string $ancienMotDePasse, string $nouveauMotDePasse): bool
    {
        $client = Client::findOrFail($clientId);
        
        if (!Hash::check($ancienMotDePasse, $client->password)) {
            return false;
        }
        
        $client->password = $nouveauMotDePasse;
        $client->save();
        
        return true;
    }
    
    public function reinitialiserMotDePasse(string $email): ?string
    {
        $client = Client::where('email', $email)->first();
        
        if (!$client) {
            return null;
        }
        
        $nouveauMotDePasse = $client->reinitialiserMotDePasse();
        
        Log::info('Mot de passe réinitialisé', [
            'client_id' => $client->id,
            'email' => $client->email,
            'nouveau_mot_de_passe' => $nouveauMotDePasse
        ]);
        
        return $nouveauMotDePasse;
    }
    
    public function incrementerAchats(int $clientId, float $montant): void
    {
        $client = Client::find($clientId);
        if ($client) {
            $client->incrementerAchats($montant);
        }
    }
    
    public function getStatistiques(Client $client): array
    {
        return [
            'documents_soumis' => $client->kycDocuments()->count(),
            'documents_verifies' => $client->kycDocuments()->where('statut', 'VERIFIE')->count(),
            'kyc_complet' => $client->kyc_complet,
            'dernier_connexion' => $client->dernier_connexion,
            'nb_connexions' => $client->historiqueConnexions()->count(),
            'nombre_achats' => $client->nombre_achats,
            'total_achats' => $client->total_achats,
            'categorie' => $client->categorie_label,
            'prochaine_categorie' => $this->getProchaineCategorie($client)
        ];
    }
    
    private function getProchaineCategorie(Client $client): ?array
    {
        if ($client->categorie_client === 'STANDARD') {
            return [
                'categorie' => 'PREMIUM',
                'seuil_achats' => Client::CATEGORIES['PREMIUM']['seuil_achats'] - $client->nombre_achats,
                'seuil_montant' => Client::CATEGORIES['PREMIUM']['seuil_montant'] - $client->total_achats
            ];
        }
        
        if ($client->categorie_client === 'PREMIUM') {
            return [
                'categorie' => 'VIP',
                'seuil_achats' => Client::CATEGORIES['VIP']['seuil_achats'] - $client->nombre_achats,
                'seuil_montant' => Client::CATEGORIES['VIP']['seuil_montant'] - $client->total_achats
            ];
        }
        
        return null;
    }
}