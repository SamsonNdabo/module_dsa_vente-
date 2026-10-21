<?php

namespace App\Traits;

use App\Models\PeriodesComptable;
use Carbon\Carbon;
use Illuminate\Http\Request;

trait FiltersComptables
{
    /**
     * Récupère la période en cours (retourne un MODÈLE, pas un JSON !)
     */
    protected function getPeriodeEnCours()
    {
        return PeriodesComptable::where('is_cloture', false)
            ->orderBy('date_debut', 'asc')
            ->first();
    }

    /**
     * Récupère les infos d'une période par ID
     */
    protected function getPeriodeInfo($periodeId)
    {
        if (empty($periodeId) || $periodeId === 'all') {
            return null;
        }

        if ($periodeId === 'en-cours') {
            return $this->getPeriodeEnCours();
        }

        return PeriodesComptable::find($periodeId);
    }

    /**
     * Formate les infos période pour le JSON
     * ✅ CORRIGÉ : Vérifie que $periode est bien un objet avant d'accéder à ses propriétés
     */
    protected function formatPeriodeInfo($periode): ?array
    {
        // ✅ Protection : si c'est un JsonResponse ou null, on retourne null
        if (!$periode || !is_object($periode) || !isset($periode->id)) {
            return null;
        }

        return [
            'id' => $periode->id,
            'code' => $periode->code,
            'libelle' => $periode->libelle,
            'date_debut' => $periode->date_debut instanceof Carbon
                ? $periode->date_debut->format('d/m/Y')
                : $periode->date_debut,
            'date_fin' => $periode->date_fin instanceof Carbon
                ? $periode->date_fin->format('d/m/Y')
                : $periode->date_fin,
            'is_cloture' => $periode->is_cloture,
        ];
    }

    /**
     * Applique le filtre temporel sur une query
     */
    protected function applyTimeFilter($query, Request $request, string $dateColumn = 'date_ecriture')
    {
        $timeFilter = $request->input('time_filter');
        if (empty($timeFilter) || $timeFilter === 'all') {
            return;
        }

        $now = Carbon::now();

        switch ($timeFilter) {
            case 'today':
                $query->whereDate($dateColumn, $now->toDateString());
                break;

            case 'yesterday':
                $query->whereDate($dateColumn, $now->copy()->subDay()->toDateString());
                break;

            case 'this_week':
                $query->whereBetween($dateColumn, [
                    $now->copy()->startOfWeek()->toDateString(),
                    $now->copy()->endOfWeek()->toDateString()
                ]);
                break;

            case 'last_week':
                $query->whereBetween($dateColumn, [
                    $now->copy()->subWeek()->startOfWeek()->toDateString(),
                    $now->copy()->subWeek()->endOfWeek()->toDateString()
                ]);
                break;

            case 'this_month':
                $query->whereBetween($dateColumn, [
                    $now->copy()->startOfMonth()->toDateString(),
                    $now->copy()->endOfMonth()->toDateString()
                ]);
                break;

            case 'last_month':
                $query->whereBetween($dateColumn, [
                    $now->copy()->subMonth()->startOfMonth()->toDateString(),
                    $now->copy()->subMonth()->endOfMonth()->toDateString()
                ]);
                break;

            case 'last_7_days':
                $query->whereDate($dateColumn, '>=', $now->copy()->subDays(7)->toDateString());
                break;

            case 'last_30_days':
                $query->whereDate($dateColumn, '>=', $now->copy()->subDays(30)->toDateString());
                break;

            case 'this_year':
                $query->whereYear($dateColumn, $now->year);
                break;

            case 'last_year':
                $query->whereYear($dateColumn, $now->year - 1);
                break;

            case 'custom':
                $startDate = $request->input('start_date');
                $endDate = $request->input('end_date');
                if ($startDate && $endDate) {
                    $query->whereBetween($dateColumn, [
                        Carbon::parse($startDate)->toDateString(),
                        Carbon::parse($endDate)->toDateString()
                    ]);
                } elseif ($startDate) {
                    $query->whereDate($dateColumn, '>=', Carbon::parse($startDate)->toDateString());
                } elseif ($endDate) {
                    $query->whereDate($dateColumn, '<=', Carbon::parse($endDate)->toDateString());
                }
                break;
        }
    }

    /**
     * Récupère la liste des filtres appliqués
     */
    protected function getAppliedFilters(Request $request): array
    {
        $filters = [];

        $periodeId = $request->input('periode_id');
        if ($periodeId && $periodeId !== 'all') {
            $filters['periode'] = $periodeId;
        }

        $timeFilter = $request->input('time_filter');
        if ($timeFilter && $timeFilter !== 'all') {
            $filters['time_filter'] = $timeFilter;
        }

        $entiteId = $request->input('entite_id');
        if ($entiteId && $entiteId !== 'all') {
            $filters['entite'] = $entiteId;
        }

        $succursaleId = $request->input('succursale_id');
        if ($succursaleId && $succursaleId !== 'all') {
            $filters['succursale'] = $succursaleId;
        }

        $compte = $request->input('compte_numero');
        if ($compte) {
            $filters['compte'] = $compte;
        }

        return $filters;
    }
}