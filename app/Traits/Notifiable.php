<?php
// app/Traits/Notifiable.php

namespace App\Traits;

use App\Models\NotificationStock;
use App\Models\Utilisateur;
use App\Models\Succursale;
use Illuminate\Support\Facades\Log;
use App\Models\StockSuccursale;

trait Notifiable
{
    /**
     * Notifier les utilisateurs concernés
     */
    public function notifierUtilisateurs($data)
    {
        try {
            if (isset($data['destinataires']) && !empty($data['destinataires'])) {
                $this->notifierDestinatairesSpecifiques($data);
                return;
            }

            $utilisateurs = $this->getUtilisateursANotifier($data['type']);

            if ($utilisateurs->isNotEmpty()) {
                NotificationStock::notifierUtilisateurs(
                    $utilisateurs->pluck('id')->toArray(),
                    $data['type'],
                    $data['titre'],
                    $data['message'],
                    $data['donnees'] ?? null,
                    $data['succursale_id'] ?? null,
                    $data['produit_id'] ?? null,
                    $data['priorite'] ?? 'NORMALE'
                );
                
                Log::info('Notification envoyée à ' . $utilisateurs->count() . ' utilisateurs', [
                    'type' => $data['type']
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Erreur notification', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    /**
     * Notifier des destinataires spécifiques (CORRIGÉ)
     */
    public function notifierDestinatairesSpecifiques($data)
    {
        $destinataires = $data['destinataires'];
        $destinataireType = $destinataires['type'] ?? 'utilisateur';
        
        try {
            switch ($destinataireType) {
                case 'utilisateur':
                    if (isset($destinataires['id'])) {
                        NotificationStock::notifierUtilisateur(
                            $destinataires['id'],
                            $data['type'],
                            $data['titre'],
                            $data['message'],
                            $data['donnees'] ?? null,
                            $data['succursale_id'] ?? null,
                            $data['produit_id'] ?? null,
                            $data['priorite'] ?? 'NORMALE'
                        );
                        Log::info('Notification envoyée à utilisateur', ['user_id' => $destinataires['id']]);
                    }
                    break;
                    
                case 'multiples':
                    if (isset($destinataires['ids']) && is_array($destinataires['ids'])) {
                        NotificationStock::notifierUtilisateurs(
                            $destinataires['ids'],
                            $data['type'],
                            $data['titre'],
                            $data['message'],
                            $data['donnees'] ?? null,
                            $data['succursale_id'] ?? null,
                            $data['produit_id'] ?? null,
                            $data['priorite'] ?? 'NORMALE'
                        );
                        Log::info('Notification envoyée à multiples utilisateurs', ['count' => count($destinataires['ids'])]);
                    }
                    break;
                    
                case 'role':
                    if (isset($destinataires['code'])) {
                        NotificationStock::notifierRole(
                            $destinataires['code'],
                            $data['type'],
                            $data['titre'],
                            $data['message'],
                            $data['donnees'] ?? null,
                            $data['succursale_id'] ?? null,
                            $data['priorite'] ?? 'NORMALE'
                        );
                        Log::info('Notification envoyée au rôle', ['role' => $destinataires['code']]);
                    }
                    break;
                    
                case 'succursale':
                    if (isset($destinataires['id'])) {
                        NotificationStock::notifierSuccursale(
                            $destinataires['id'],
                            $data['type'],
                            $data['titre'],
                            $data['message'],
                            $data['donnees'] ?? null,
                            $data['priorite'] ?? 'NORMALE'
                        );
                        Log::info('Notification envoyée à succursale', ['succursale_id' => $destinataires['id']]);
                    }
                    break;
                    
                case 'responsable_succursale':
                    if (isset($destinataires['succursale_id'])) {
                        $succursale = Succursale::find($destinataires['succursale_id']);
                        
                        if ($succursale && $succursale->responsable_id) {
                            $responsable = Utilisateur::find($succursale->responsable_id);
                            if ($responsable && $responsable->statut === 'ACTIF' && !$responsable->compte_verrouille) {
                                NotificationStock::notifierUtilisateur(
                                    $responsable->id,
                                    $data['type'],
                                    $data['titre'],
                                    $data['message'],
                                    $data['donnees'] ?? null,
                                    $succursale->id,
                                    $data['produit_id'] ?? null,
                                    $data['priorite'] ?? 'NORMALE'
                                );
                                Log::info('Notification envoyée au responsable de succursale', [
                                    'succursale_id' => $destinataires['succursale_id'],
                                    'responsable_id' => $responsable->id
                                ]);
                            }
                        } else {
                            Log::warning('Succursale sans responsable', ['succursale_id' => $destinataires['succursale_id']]);
                        }
                    }
                    break;
                    
                case 'public':
                    NotificationStock::notifierPublic(
                        $data['type'],
                        $data['titre'],
                        $data['message'],
                        $data['donnees'] ?? null,
                        $data['priorite'] ?? 'NORMALE'
                    );
                    Log::info('Notification publique envoyée');
                    break;
                    
                default:
                    Log::warning('Type de destinataire inconnu', ['type' => $destinataireType]);
            }
        } catch (\Exception $e) {
            Log::error('Erreur dans notifierDestinatairesSpecifiques', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    /**
     * Notifier toutes les sources potentielles pour une demande à l'aveugle
     */
    protected function notifierToutesSources($demande)
    {
        try {
            $sources = StockSuccursale::whereHas('succursale', function($q) {
                    $q->where('est_active', true);
                })
                ->with('succursale')
                ->get();

            if ($sources->isEmpty()) {
                Log::warning('Aucune source trouvée pour notification', ['demande_id' => $demande->id]);
                return;
            }

            $produitsListe = $demande->lignes->map(function($ligne) {
                return $ligne->quantite_demandee . 'x ' . ($ligne->produit->nom ?? 'Produit inconnu');
            })->implode(', ');

            $typeNotification = $demande->type_demande === 'APPROVISIONNEMENT' 
                ? 'DEMANDE_APPRO_A_L_AVEUGLE' 
                : 'DEMANDE_TRANSFERT_A_L_AVEUGLE';

            foreach ($sources as $source) {
                if (!$source->succursale) {
                    continue;
                }

                $this->notifierDestinatairesSpecifiques([
                    'destinataires' => [
                        'type' => 'responsable_succursale',
                        'succursale_id' => $source->succursale_id
                    ],
                    'type' => $typeNotification,
                    'titre' => "📦 Nouvelle demande à l'aveugle",
                    'message' => "Demande #{$demande->reference} - Produits: {$produitsListe}",
                    'donnees' => [
                        'demande_id' => $demande->id,
                        'reference' => $demande->reference,
                        'type' => $demande->type_demande,
                        'quantite_totale' => $demande->quantite_demandee,
                        'produits' => $produitsListe
                    ],
                    'succursale_id' => $source->succursale_id,
                    'priorite' => 'HAUTE'
                ]);
            }
            
            Log::info('Notifications envoyées pour demande à l\'aveugle', [
                'demande_id' => $demande->id,
                'nb_sources' => $sources->count()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur dans notifierToutesSources', [
                'demande_id' => $demande->id ?? null,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * ✅ NOUVELLE MÉTHODE : Notifier la prise en charge
     */
    public function notifierPriseEnCharge($demande, $user, $sourceStock)
    {
        try {
            $produitsListe = $demande->lignes->map(function($ligne) {
                return $ligne->quantite_demandee . 'x ' . ($ligne->produit->nom ?? 'Produit inconnu');
            })->implode(', ');

            $typeNotification = $demande->type_demande === 'APPROVISIONNEMENT' 
                ? 'DEMANDE_APPRO_PRISE' 
                : 'DEMANDE_TRANSFERT_PRISE';

            // 1. Notifier tous les chefs d'agence
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'role',
                    'code' => 'CHEF_AGENCE'
                ],
                'type' => $typeNotification,
                'titre' => "✅ Demande prise en charge",
                'message' => "La demande #{$demande->reference} a été prise en charge par {$user->nom_complet} du dépôt {$sourceStock->succursale->nom}",
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference,
                    'pris_en_charge_par' => $user->id,
                    'pris_en_charge_par_nom' => $user->nom_complet,
                    'depot_source_id' => $sourceStock->id,
                    'depot_source_nom' => $sourceStock->succursale->nom,
                    'produits' => $produitsListe
                ],
                'priorite' => 'NORMALE'
            ]);

            // 2. Notifier le demandeur
            if ($demande->demandeur_id) {
                $this->notifierDestinatairesSpecifiques([
                    'destinataires' => [
                        'type' => 'utilisateur',
                        'id' => $demande->demandeur_id
                    ],
                    'type' => $typeNotification,
                    'titre' => "✅ Votre demande a été prise en charge",
                    'message' => "Votre demande #{$demande->reference} a été prise en charge par {$user->nom_complet} du dépôt {$sourceStock->succursale->nom}",
                    'donnees' => [
                        'demande_id' => $demande->id,
                        'reference' => $demande->reference,
                        'pris_en_charge_par' => $user->id,
                        'pris_en_charge_par_nom' => $user->nom_complet,
                        'depot_source_id' => $sourceStock->id,
                        'depot_source_nom' => $sourceStock->succursale->nom,
                        'produits' => $produitsListe
                    ],
                    'priorite' => 'HAUTE'
                ]);
            }

            // 3. Notifier les gestionnaires de stock
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'role',
                    'code' => 'GESTIONNAIRE_STOCK'
                ],
                'type' => $typeNotification,
                'titre' => "📋 Demande prise en charge",
                'message' => "La demande #{$demande->reference} a été prise en charge par {$user->nom_complet}",
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference
                ],
                'priorite' => 'NORMALE'
            ]);

            Log::info('Notifications de prise en charge envoyées', [
                'demande_id' => $demande->id,
                'pris_par' => $user->id
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur dans notifierPriseEnCharge', [
                'demande_id' => $demande->id ?? null,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Obtenir les utilisateurs à notifier par type de notification
     */
    protected function getUtilisateursANotifier($type)
    {
        $query = Utilisateur::query()
            ->where('statut', 'ACTIF')
            ->where('compte_verrouille', false)
            ->with('role');

        switch ($type) {
            case 'ALERTE_STOCK':
            case 'SEUIL_CRITIQUE':
            case 'RUPTURE_STOCK':
                $query->whereHas('role', function($q) {
                    $q->whereIn('code', ['CHEF_AGENCE', 'SUPER_ADMIN', 'GESTIONNAIRE_STOCK']);
                });
                break;

            case 'DEMANDE_APPROVISIONNEMENT':
            case 'APPROVISIONNEMENT_VALIDE':
            case 'APPROVISIONNEMENT_EXPEDIE':
                $query->whereHas('role', function($q) {
                    $q->whereIn('code', ['CHEF_AGENCE', 'SUPER_ADMIN', 'GESTIONNAIRE_STOCK', 'GESTIONNAIRE_DEPOT']);
                });
                break;

            case 'DEMANDE_APPRO_A_L_AVEUGLE':
            case 'DEMANDE_APPRO_PRISE':
            case 'DEMANDE_TRANSFERT_A_L_AVEUGLE':
            case 'DEMANDE_TRANSFERT_PRISE':
                $query->whereHas('role', function($q) {
                    $q->whereIn('code', ['CHEF_AGENCE', 'SUPER_ADMIN', 'GESTIONNAIRE_STOCK', 'GESTIONNAIRE_DEPOT']);
                });
                break;

            case 'DEMANDE_TRANSFERT':
            case 'TRANSFERT_PREPARE':
            case 'TRANSFERT_EXPEDIE':
            case 'TRANSFERT_RECU':
            case 'DEMANDE_EXPEDIEE':
                $query->whereHas('role', function($q) {
                    $q->whereIn('code', ['CHEF_AGENCE', 'SUPER_ADMIN', 'GESTIONNAIRE_STOCK', 'RECEPTEUR']);
                });
                break;

            case 'MOUVEMENT_CREE':
            case 'MOUVEMENT_VALIDE':
            case 'MOUVEMENT_ANNULE':
                $query->whereHas('role', function($q) {
                    $q->whereIn('code', ['CHEF_AGENCE', 'SUPER_ADMIN', 'GESTIONNAIRE_STOCK']);
                });
                break;

            case 'ECART_TRANSFERT':
            case 'ECART_INVENTAIRE':
                $query->whereHas('role', function($q) {
                    $q->whereIn('code', ['SUPER_ADMIN', 'AUDITEUR', 'CHEF_AGENCE']);
                });
                break;

            default:
                $query->whereHas('role', function($q) {
                    $q->where('code', 'SUPER_ADMIN');
                });
        }

        return $query->get();
    }
}