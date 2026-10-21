<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\AuditSysteme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class BackupController extends Controller
{
    private $backupPath;
    private $tempPath;
    private $dbConfig;

    public function __construct()
    {
        $this->backupPath = storage_path('app/backups');
        $this->tempPath = storage_path('app/temp');
        $this->dbConfig = config('database.connections.mysql');
        
        // Créer les dossiers s'ils n'existent pas
        $this->ensureDirectoriesExist();
    }

    /**
     * Créer les dossiers nécessaires
     */
    private function ensureDirectoriesExist()
    {
        $directories = [$this->backupPath, $this->tempPath];
        foreach ($directories as $directory) {
            if (!File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
            }
        }
    }

    /**
     * Sauvegarde complète de la base de données
     */
    public function createFullBackup(Request $request)
    {
        try {
            $request->validate([
                'description' => 'nullable|string|max:255',
                'tables' => 'nullable|array',
                'tables.*' => 'string|exists_in_database'
            ]);

            $filename = $this->generateBackupFilename('full');
            $filepath = "{$this->backupPath}/{$filename}";
            
            // Récupérer les tables à sauvegarder
            $tables = $request->input('tables', []);
            $allTables = empty($tables) ? $this->getAllTables() : $tables;
            
            // Informations de connexion
            $dbHost = $this->dbConfig['host'];
            $dbPort = $this->dbConfig['port'] ?? '3306';
            $dbUser = $this->dbConfig['username'];
            $dbPass = $this->dbConfig['password'];
            $dbName = $this->dbConfig['database'];
            
            // Créer le fichier de sauvegarde
            $header = "-- ========================================\n";
            $header .= "-- BACKUP DATABASE: {$dbName}\n";
            $header .= "-- Date: " . now()->format('Y-m-d H:i:s') . "\n";
            $header .= "-- Tables: " . implode(', ', $allTables) . "\n";
            $header .= "-- Description: " . ($request->description ?? 'Sauvegarde manuelle') . "\n";
            $header .= "-- ========================================\n\n";
            
            File::put($filepath, $header);
            
            // Sauvegarder chaque table
            foreach ($allTables as $table) {
                $this->backupTable($table, $filepath);
            }
            
            // Compression du fichier
            $compressedFile = $this->compressBackup($filename);
            $fileSize = File::size($compressedFile);
            
            // Enregistrer dans l'audit
            $this->auditBackup('CREATE', $filename, $allTables, $fileSize, $request);
            
            return response()->json([
                'success' => true,
                'message' => 'Sauvegarde créée avec succès',
                'data' => [
                    'filename' => basename($compressedFile),
                    'size' => $this->formatFileSize($fileSize),
                    'tables_count' => count($allTables),
                    'tables' => $allTables,
                    'date' => now()->format('Y-m-d H:i:s'),
                    'description' => $request->description ?? 'Sauvegarde manuelle'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur backup: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du backup',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur interne'
            ], 500);
        }
    }

    /**
     * Restaurer des tables spécifiques depuis un backup
     */
    public function restoreTables(Request $request)
    {
        $request->validate([
            'backup_file' => 'required|string',
            'tables' => 'required|array|min:1',
            'tables.*' => 'string|exists_in_database',
            'drop_existing' => 'boolean',
            'confirm_restore' => 'required|boolean|accepted'
        ]);

        try {
            $filename = basename($request->backup_file);
            $backupFile = "{$this->backupPath}/{$filename}";
            
            // Vérifier que le fichier existe
            if (!File::exists($backupFile)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fichier de backup introuvable'
                ], 404);
            }
            
            $tablesToRestore = $request->input('tables');
            $dropExisting = $request->input('drop_existing', false);
            
            DB::beginTransaction();
            
            // Créer un backup de sécurité avant restauration
            $safetyBackup = $this->createSafetyBackup($tablesToRestore);
            
            // Restaurer les tables sélectionnées
            $results = [];
            foreach ($tablesToRestore as $table) {
                $result = $this->restoreTableFromBackup(
                    $backupFile, 
                    $table, 
                    $dropExisting
                );
                $results[$table] = $result;
            }
            
            DB::commit();
            
            // Enregistrer dans l'audit
            $this->auditBackup('RESTORE', $filename, $tablesToRestore, null, $request);
            
            // Nettoyer le backup de sécurité si tout s'est bien passé
            if (File::exists($safetyBackup)) {
                File::delete($safetyBackup);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Restauration effectuée avec succès',
                'data' => [
                    'tables_restored' => $results,
                    'safety_backup' => basename($safetyBackup)
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erreur restore: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la restauration. Le backup de sécurité est conservé.',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur interne'
            ], 500);
        }
    }

    /**
     * Obtenir l'historique des audits avec filtres avancés
     */
    public function getAuditHistory(Request $request)
    {
        $request->validate([
            'table' => 'nullable|string',
            'action' => 'nullable|string|in:CREATE,RESTORE,MODIFY,DELETE',
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'utilisateur_id' => 'nullable|integer',
            'importance' => 'nullable|string|in:basse,moyenne,haute,critique',
            'recherche' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        try {
            $query = AuditSysteme::query()->with('utilisateur:id,nom,prenom,email');
            
            // Appliquer les filtres
            if ($table = $request->input('table')) {
                $query->parTable($table);
            }
            
            if ($action = $request->input('action')) {
                $query->where('type_action', $action);
            }
            
            if ($request->has('date_debut') && $request->has('date_fin')) {
                $query->parPeriode($request->date_debut, $request->date_fin);
            }
            
            if ($userId = $request->input('utilisateur_id')) {
                $query->parUtilisateur($userId);
            }
            
            if ($importance = $request->input('importance')) {
                $query->parImportance($importance);
            }
            
            if ($recherche = $request->input('recherche')) {
                $query->recherche($recherche);
            }
            
            // Ordonner et paginer
            $audits = $query->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 15));
            
            // Statistiques
            $stats = [
                'total_modifications' => AuditSysteme::count(),
                'par_action' => AuditSysteme::select('type_action', DB::raw('count(*) as total'))
                    ->groupBy('type_action')
                    ->get(),
                'tables_modifiees' => AuditSysteme::distinct('table_affectee')
                    ->pluck('table_affectee')
            ];
            
            return response()->json([
                'success' => true,
                'data' => $audits,
                'statistics' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur audit: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique'
            ], 500);
        }
    }

    /**
     * Obtenir la liste des tables modifiées avec statistiques
     */
    public function getModifiedTables()
    {
        try {
            $tables = AuditSysteme::select(
                'table_affectee',
                DB::raw('count(*) as total_modifications'),
                DB::raw('MAX(created_at) as derniere_modification'),
                DB::raw("SUM(CASE WHEN type_action = 'CREATE' THEN 1 ELSE 0 END) as creations"),
                DB::raw("SUM(CASE WHEN type_action = 'MODIFY' THEN 1 ELSE 0 END) as modifications"),
                DB::raw("SUM(CASE WHEN type_action = 'DELETE' THEN 1 ELSE 0 END) as suppressions")
            )
            ->groupBy('table_affectee')
            ->orderBy('derniere_modification', 'desc')
            ->get()
            ->map(function($table) {
                return [
                    'table' => $table->table_affectee,
                    'total_modifications' => $table->total_modifications,
                    'derniere_modification' => $table->derniere_modification,
                    'actions' => [
                        'creations' => $table->creations,
                        'modifications' => $table->modifications,
                        'suppressions' => $table->suppressions
                    ],
                    'niveau_risque' => $this->calculerNiveauRisque($table)
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $tables,
                'total_tables' => $tables->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur tables modifiées: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des tables'
            ], 500);
        }
    }

    /**
     * Obtenir les détails d'un backup spécifique
     */
    public function getBackupDetails($filename)
    {
        try {
            $filename = basename($filename);
            $filepath = "{$this->backupPath}/{$filename}";
            
            if (!File::exists($filepath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Backup introuvable'
                ], 404);
            }
            
            // Extraire les informations du backup
            $content = File::get($filepath);
            $details = $this->parseBackupFile($content);
            
            $details['file_info'] = [
                'filename' => $filename,
                'size' => $this->formatFileSize(File::size($filepath)),
                'date_creation' => date('Y-m-d H:i:s', File::lastModified($filepath)),
                'tables_count' => count($details['tables'])
            ];
            
            return response()->json([
                'success' => true,
                'data' => $details
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur details backup: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la lecture du backup'
            ], 500);
        }
    }

    /**
     * Supprimer un backup
     */
    public function deleteBackup(Request $request)
    {
        $request->validate([
            'filename' => 'required|string',
            'confirm_delete' => 'required|boolean|accepted'
        ]);

        try {
            $filename = basename($request->filename);
            $filepath = "{$this->backupPath}/{$filename}";
            
            if (!File::exists($filepath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Backup introuvable'
                ], 404);
            }
            
            File::delete($filepath);
            
            $this->auditBackup('DELETE', $filename, [], null, $request);
            
            return response()->json([
                'success' => true,
                'message' => 'Backup supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur suppression backup: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du backup'
            ], 500);
        }
    }

    // ============ MÉTHODES PRIVÉES ============

    /**
     * Sauvegarder une table spécifique
     */
    private function backupTable($table, $filepath)
    {
        $command = sprintf(
            'mysqldump -u%s -p%s -h%s --port=%s %s %s >> %s 2>/dev/null',
            escapeshellarg($this->dbConfig['username']),
            escapeshellarg($this->dbConfig['password']),
            escapeshellarg($this->dbConfig['host']),
            escapeshellarg($this->dbConfig['port'] ?? '3306'),
            escapeshellarg($this->dbConfig['database']),
            escapeshellarg($table),
            escapeshellarg($filepath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \RuntimeException("Erreur lors de la sauvegarde de la table {$table}");
        }
    }

    /**
     * Restaurer une table depuis un backup
     */
    private function restoreTableFromBackup($backupFile, $table, $dropExisting)
    {
        // Extraire seulement les données de la table concernée
        $tableBackup = "{$this->tempPath}/{$table}_" . uniqid() . '.sql';
        
        // Filtrer le backup pour cette table
        $content = File::get($backupFile);
        preg_match_all("/-- Table structure for table `{$table}`.*?(?=-- Table structure|$)/s", $content, $matches);
        
        if (empty($matches[0])) {
            throw new \RuntimeException("Table {$table} non trouvée dans le backup");
        }
        
        File::put($tableBackup, implode("\n", $matches[0]));
        
        // Restaurer
        if ($dropExisting) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
        
        $command = sprintf(
            'mysql -u%s -p%s -h%s --port=%s %s < %s 2>/dev/null',
            escapeshellarg($this->dbConfig['username']),
            escapeshellarg($this->dbConfig['password']),
            escapeshellarg($this->dbConfig['host']),
            escapeshellarg($this->dbConfig['port'] ?? '3306'),
            escapeshellarg($this->dbConfig['database']),
            escapeshellarg($tableBackup)
        );
        
        exec($command, $output, $returnCode);
        
        // Nettoyer le fichier temporaire
        if (File::exists($tableBackup)) {
            File::delete($tableBackup);
        }
        
        return [
            'success' => $returnCode === 0,
            'records_restored' => DB::table($table)->count()
        ];
    }

    /**
     * Créer un backup de sécurité avant restauration
     */
    private function createSafetyBackup($tables)
    {
        $filename = 'safety_' . now()->format('Y-m-d_H-i-s') . '.sql';
        $filepath = "{$this->backupPath}/{$filename}";
        
        foreach ($tables as $table) {
            $this->backupTable($table, $filepath);
        }
        
        return $filepath;
    }

    /**
     * Enregistrer l'action dans la table d'audit
     */
    private function auditBackup($action, $filename, $tables, $fileSize, $request)
    {
        try {
            AuditSysteme::create([
                'table_affectee' => 'system_backups',
                'type_action' => "BACKUP_{$action}",
                'id_enregistrement' => null,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => [
                    'filename' => $filename,
                    'tables' => $tables,
                    'size' => $fileSize ? $this->formatFileSize($fileSize) : null,
                    'description' => $request->description ?? null
                ],
                'id_utilisateur' => $request->user()?->id,
                'adresse_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'description' => "Backup {$action} - " . ($request->description ?? 'Action système'),
                'niveau_importance' => $action === 'RESTORE' ? 'critique' : 'haute'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur audit backup: ' . $e->getMessage());
        }
    }

    /**
     * Générer un nom de fichier pour le backup
     */
    private function generateBackupFilename($type)
    {
        return sprintf(
            '%s_%s_%s.sql',
            $type,
            config('app.name', 'app'),
            now()->format('Y-m-d_H-i-s')
        );
    }

    /**
     * Compresser un fichier de backup
     */
    private function compressBackup($filename)
    {
        $sourcePath = "{$this->backupPath}/{$filename}";
        $compressedPath = $sourcePath . '.gz';
        
        $command = sprintf('gzip -c %s > %s', escapeshellarg($sourcePath), escapeshellarg($compressedPath));
        exec($command);
        
        // Supprimer le fichier original non compressé
        if (File::exists($sourcePath)) {
            File::delete($sourcePath);
        }
        
        return $compressedPath;
    }

    /**
     * Obtenir toutes les tables de la base de données
     */
    private function getAllTables()
    {
        return collect(DB::select('SHOW TABLES'))
            ->map(function($table) {
                $tableArray = (array) $table;
                return array_values($tableArray)[0];
            })
            ->toArray();
    }

    /**
     * Parser un fichier de backup pour en extraire les informations
     */
    private function parseBackupFile($content)
    {
        $tables = [];
        preg_match_all('/CREATE TABLE `(\w+)`/', $content, $matches);
        
        if (!empty($matches[1])) {
            $tables = array_unique($matches[1]);
        }
        
        // Extraire la date du backup
        preg_match('/Date: (.+)/', $content, $dateMatch);
        $backupDate = $dateMatch[1] ?? 'Inconnue';
        
        return [
            'tables' => $tables,
            'backup_date' => $backupDate
        ];
    }

    /**
     * Calculer le niveau de risque d'une table
     */
    private function calculerNiveauRisque($table)
    {
        $score = 0;
        
        if ($table->modifications > 10) $score++;
        if ($table->suppressions > 0) $score += 2;
        if (strtotime($table->derniere_modification) > strtotime('-1 hour')) $score += 2;
        
        return match(true) {
            $score >= 4 => 'critique',
            $score >= 3 => 'haut',
            $score >= 2 => 'moyen',
            default => 'faible'
        };
    }

    /**
     * Formater la taille d'un fichier
     */
    private function formatFileSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}