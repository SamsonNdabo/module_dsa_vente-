<?php

namespace App\Services\PlainteSuggestion;

use App\Models\PlainteSuggestion\Suggestion;
use App\Models\PlainteSuggestion\SuggestionPieceJointe;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SuggestionPieceJointeService
{
    // =========================================================
    // 📋 LISTE FILTRÉE
    // =========================================================
    public function lister(int $suggestionId, array $filtres = []): LengthAwarePaginator
    {
        $query = SuggestionPieceJointe::with(['uploader:id,nom_complet'])
            ->where('suggestion_id', $suggestionId);

        if (! empty($filtres['type'])) {
            $mimes = [
                'image' => 'image/',
                'pdf'   => 'application/pdf',
                'video' => 'video/',
            ];
            $query->where('type_mime', 'like', ($mimes[$filtres['type']] ?? $filtres['type']) . '%');
        }

        if (! empty($filtres['extension'])) {
            $query->where('chemin', 'like', '%.' . $filtres['extension']);
        }
        if (! empty($filtres['uploaded_by'])) {
            $query->where('uploaded_by', $filtres['uploaded_by']);
        }
        if (! empty($filtres['taille_min'])) {
            $query->where('taille_ko', '>=', (int) $filtres['taille_min']);
        }
        if (! empty($filtres['taille_max'])) {
            $query->where('taille_ko', '<=', (int) $filtres['taille_max']);
        }
        if (! empty($filtres['date_debut'])) {
            $query->whereDate('created_at', '>=', $filtres['date_debut']);
        }
        if (! empty($filtres['date_fin'])) {
            $query->whereDate('created_at', '<=', $filtres['date_fin']);
        }
        if (! empty($filtres['annee'])) {
            $query->whereYear('created_at', $filtres['annee']);
        }
        if (! empty($filtres['mois'])) {
            $query->whereMonth('created_at', $filtres['mois']);
        }
        if (! empty($filtres['jour'])) {
            $query->whereDay('created_at', $filtres['jour']);
        }
        if (! empty($filtres['recherche'])) {
            $query->where('nom_fichier', 'like', '%' . $filtres['recherche'] . '%');
        }

        $query->orderBy($filtres['tri'] ?? 'created_at', $filtres['ordre'] ?? 'desc');

        $paginator = $query->paginate($filtres['per_page'] ?? 20);

        $paginator->getCollection()->transform(fn ($pj) => $this->enrichir($pj));

        return $paginator;
    }

    // =========================================================
    // ➕ UPLOAD
    // =========================================================
    public function upload(Suggestion $suggestion, UploadedFile $fichier): SuggestionPieceJointe
    {
        $now     = now();
        $dossier = "suggestions/{$now->format('Y')}/{$now->format('m')}/{$now->format('d')}";

        $nomSansExt = pathinfo($fichier->getClientOriginalName(), PATHINFO_FILENAME);
        $extension  = strtolower($fichier->getClientOriginalExtension());

        $nomFichier = sprintf(
            '%s_%s_%s.%s',
            $suggestion->reference,
            $now->timestamp,
            Str::slug($nomSansExt),
            $extension
        );

        $chemin = $fichier->storeAs($dossier, $nomFichier, 'public');

        return SuggestionPieceJointe::create([
            'suggestion_id' => $suggestion->id,
            'nom_fichier'   => $fichier->getClientOriginalName(),
            'chemin'        => $chemin,
            'type_mime'     => $fichier->getMimeType(),
            'taille_ko'     => (int) round($fichier->getSize() / 1024),
            'uploaded_by'   => Auth::id(),
        ]);
    }

    public function uploadMultiple(Suggestion $suggestion, array $fichiers): array
    {
        return array_map(fn ($f) => $this->upload($suggestion, $f), $fichiers);
    }

    // =========================================================
    // ⬇️ DOWNLOAD (force le téléchargement)
    // =========================================================
    public function download(SuggestionPieceJointe $piece)
    {
        if (! Storage::disk('public')->exists($piece->chemin)) {
            abort(404, 'Fichier introuvable.');
        }

        $cheminAbsolu = Storage::disk('public')->path($piece->chemin);

        return response()->download($cheminAbsolu, $piece->nom_fichier, [
            'Content-Type' => $piece->type_mime ?? 'application/octet-stream',
        ]);
    }

    // =========================================================
    // 👁️ VOIR (affichage inline — utile pour React <img>, <iframe>)
    // =========================================================
    public function voir(SuggestionPieceJointe $piece)
    {
        if (! Storage::disk('public')->exists($piece->chemin)) {
            abort(404, 'Fichier introuvable.');
        }

        $cheminAbsolu = Storage::disk('public')->path($piece->chemin);

        return response()->file($cheminAbsolu, [
            'Content-Type' => $piece->type_mime ?? 'application/octet-stream',
        ]);
    }

    // =========================================================
    // 🗑️ SUPPRESSION
    // =========================================================
    public function supprimer(SuggestionPieceJointe $piece): bool
    {
        if ($piece->chemin && Storage::disk('public')->exists($piece->chemin)) {
            Storage::disk('public')->delete($piece->chemin);
        }
        return $piece->delete();
    }

    public function supprimerTout(int $suggestionId): int
    {
        $pieces = SuggestionPieceJointe::where('suggestion_id', $suggestionId)->get();
        $total  = 0;
        foreach ($pieces as $p) {
            $this->supprimer($p);
            $total++;
        }
        return $total;
    }

    // =========================================================
    // 📊 STATS
    // =========================================================
    public function stats(int $suggestionId): array
    {
        $pieces = SuggestionPieceJointe::where('suggestion_id', $suggestionId)->get();

        return [
            'total'          => $pieces->count(),
            'poids_total_ko' => $pieces->sum('taille_ko'),
            'poids_lisible'  => $this->formaterTaille($pieces->sum('taille_ko')),
            'images'         => $pieces->filter(fn ($p) => Str::startsWith($p->type_mime, 'image/'))->count(),
            'pdfs'           => $pieces->where('type_mime', 'application/pdf')->count(),
            'videos'         => $pieces->filter(fn ($p) => Str::startsWith($p->type_mime, 'video/'))->count(),
            'par_uploader'   => $pieces->groupBy('uploaded_by')->map(fn ($g) => [
                'total' => $g->count(),
                'poids' => $this->formaterTaille($g->sum('taille_ko')),
            ]),
        ];
    }

    // =========================================================
    // 📅 ARBRE PAR PÉRIODE
    // =========================================================
    public function parPeriode(int $suggestionId): array
    {
        $pieces = SuggestionPieceJointe::where('suggestion_id', $suggestionId)
            ->orderBy('created_at')
            ->get();

        return $pieces->groupBy(fn ($p) => $p->created_at->format('Y'))
            ->map(fn ($parAnnee) => $parAnnee->groupBy(fn ($p) => $p->created_at->format('m'))
                ->map(fn ($parMois) => $parMois->groupBy(fn ($p) => $p->created_at->format('d'))
                    ->map(fn ($parJour) => $parJour->map(fn ($p) => $this->enrichir($p)))
                )
            )->toArray();
    }

    // =========================================================
    // 🛠️ HELPERS
    // =========================================================
    public function enrichir(SuggestionPieceJointe $pj): array
    {
        $existe = $pj->chemin && Storage::disk('public')->exists($pj->chemin);

        return [
            'id'             => $pj->id,
            'suggestion_id'  => $pj->suggestion_id,
            'nom_fichier'    => $pj->nom_fichier,

            // Chemin relatif (BDD)
            'chemin'         => $pj->chemin,

            // Chemins/URLs utiles au front (React)
            'url'            => $existe ? Storage::disk('public')->url($pj->chemin) : null,
            'url_public'     => $existe ? asset('storage/' . $pj->chemin) : null,
            'url_voir'       => $existe ? route('pieces-jointes.voir', ['id' => $pj->id]) : null,
            'url_download'   => $existe ? route('pieces-jointes.download', ['id' => $pj->id]) : null,

            // Métadonnées
            'extension'      => $pj->chemin ? strtolower(pathinfo($pj->chemin, PATHINFO_EXTENSION)) : null,
            'type_mime'      => $pj->type_mime,
            'taille_ko'      => $pj->taille_ko,
            'taille_lisible' => $this->formaterTaille($pj->taille_ko),
            'est_image'      => Str::startsWith($pj->type_mime ?? '', 'image/'),
            'est_pdf'        => $pj->type_mime === 'application/pdf',
            'est_video'      => Str::startsWith($pj->type_mime ?? '', 'video/'),
            'existe'         => $existe,

            // Auteur
            'uploaded_by'    => $pj->uploaded_by,
            'uploader'       => $pj->uploader?->nom_complet,

            // Dates
            'created_at'     => $pj->created_at?->toDateTimeString(),
        ];
    }

    public function formaterTaille(?int $ko): string
    {
        $ko = $ko ?? 0;
        if ($ko < 1024) {
            return "{$ko} Ko";
        }
        if ($ko < 1024 * 1024) {
            return round($ko / 1024, 2) . ' Mo';
        }
        return round($ko / (1024 * 1024), 2) . ' Go';
    }
}