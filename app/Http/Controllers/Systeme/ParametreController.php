<?php


namespace App\Http\Controllers\Systeme;  // ← Changement ici

use App\Http\Controllers\Controller; 

use App\Models\ParametreSysteme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ParametreController extends Controller
{
    public function __construct()
    {
        // $this->middleware('permission:manage-system-config');
    }

    /**
     * Liste des paramètres
     */
    public function index(Request $request)
    {
        $query = ParametreSysteme::query();

        if ($request->has('categorie')) {
            $query->where('categorie', $request->categorie);
        }

        if ($request->has('recherche')) {
            $query->where(function($q) use ($request) {
                $q->where('cle', 'like', "%{$request->recherche}%")
                  ->orWhere('description', 'like', "%{$request->recherche}%");
            });
        }

        $params = $query->orderBy('categorie')
            ->orderBy('cle')
            ->get()
            ->groupBy('categorie');

        return response()->json([
            'success' => true,
            'data' => $params
        ]);
    }

    /**
     * Mettre à jour un paramètre
     */
    public function update(Request $request, $id)
    {
        $param = ParametreSysteme::findOrFail($id);

        if (!$param->modifiable) {
            return response()->json(['error' => 'Ce paramètre n\'est pas modifiable'], 400);
        }

        $request->validate([
            'valeur' => 'required'
        ]);

        // Valider selon le type
        $valeur = $this->validerValeur($param, $request->valeur);

        $param->valeur = $valeur;
        $param->save();

        // Vider le cache
        Cache::forget('parametre_' . $param->cle);

        return response()->json([
            'success' => true,
            'message' => 'Paramètre mis à jour',
            'data' => $param
        ]);
    }

    /**
     * Mettre à jour plusieurs paramètres
     */
    public function updateBulk(Request $request)
    {
        $request->validate([
            'parametres' => 'required|array',
            'parametres.*.id' => 'required|exists:parametres_systeme,id',
            'parametres.*.valeur' => 'required'
        ]);

        $results = [
            'success' => 0,
            'errors' => []
        ];

        foreach ($request->parametres as $data) {
            try {
                $param = ParametreSysteme::find($data['id']);
                
                if (!$param->modifiable) {
                    $results['errors'][] = "{$param->cle} non modifiable";
                    continue;
                }

                $valeur = $this->validerValeur($param, $data['valeur']);
                
                $param->valeur = $valeur;
                $param->save();
                
                Cache::forget('parametre_' . $param->cle);
                
                $results['success']++;
                
            } catch (\Exception $e) {
                $results['errors'][] = "Erreur sur ID {$data['id']}: " . $e->getMessage();
            }
        }

        return response()->json([
            'success' => true,
            'message' => "{$results['success']} paramètre(s) mis à jour",
            'results' => $results
        ]);
    }

    /**
     * Réinitialiser aux valeurs par défaut
     */
    public function reset($id)
    {
        $param = ParametreSysteme::findOrFail($id);

        if (!$param->modifiable) {
            return response()->json(['error' => 'Ce paramètre n\'est pas modifiable'], 400);
        }

        // Valeur par défaut selon le paramètre
        $defaults = [
            'SESSION_TIMEOUT_MINUTES' => '30',
            'PASSWORD_MIN_LENGTH' => '8',
            'PASSWORD_REQUIRE_UPPERCASE' => 'true',
            'PASSWORD_REQUIRE_LOWERCASE' => 'true',
            'PASSWORD_REQUIRE_NUMBER' => 'true',
            'PASSWORD_REQUIRE_SPECIAL' => 'true',
            'MAX_LOGIN_ATTEMPTS' => '5',
            'TAX_RATE' => '16.00',
            'DEFAULT_CURRENCY' => 'CDF'
        ];

        if (isset($defaults[$param->cle])) {
            $param->valeur = $defaults[$param->cle];
            $param->save();
            Cache::forget('parametre_' . $param->cle);
        }

        return response()->json([
            'success' => true,
            'message' => 'Paramètre réinitialisé',
            'data' => $param
        ]);
    }

    /**
     * Créer un paramètre (admin uniquement)
     */
    public function store(Request $request)
    {
        $request->validate([
            'categorie' => 'required|string|max:50',
            'cle' => 'required|string|max:100|unique:parametres_systeme',
            'valeur' => 'required',
            'type_valeur' => 'required|in:STRING,INTEGER,DECIMAL,BOOLEAN,JSON,DATE',
            'description' => 'nullable|string',
            'modifiable' => 'boolean',
            'visible' => 'boolean'
        ]);

        $param = ParametreSysteme::create([
            'categorie' => $request->categorie,
            'cle' => $request->cle,
            'valeur' => $this->formaterValeur($request->valeur, $request->type_valeur),
            'type_valeur' => $request->type_valeur,
            'description' => $request->description,
            'modifiable' => $request->modifiable ?? true,
            'visible' => $request->visible ?? true
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Paramètre créé',
            'data' => $param
        ], 201);
    }

    /**
     * Supprimer un paramètre
     */
    public function destroy($id)
    {
        $param = ParametreSysteme::findOrFail($id);

        if (!$param->modifiable) {
            return response()->json(['error' => 'Ce paramètre ne peut pas être supprimé'], 400);
        }

        $param->delete();
        Cache::forget('parametre_' . $param->cle);

        return response()->json([
            'success' => true,
            'message' => 'Paramètre supprimé'
        ]);
    }

    /**
     * Valider la valeur selon le type
     */
    private function validerValeur($param, $valeur)
    {
        switch ($param->type_valeur) {
            case 'INTEGER':
                if (!is_numeric($valeur) || intval($valeur) != $valeur) {
                    throw new \Exception('La valeur doit être un entier');
                }
                return (string) intval($valeur);
                
            case 'DECIMAL':
                if (!is_numeric($valeur)) {
                    throw new \Exception('La valeur doit être un nombre');
                }
                return (string) floatval($valeur);
                
            case 'BOOLEAN':
                if (!in_array($valeur, ['true', 'false', '1', '0', true, false], true)) {
                    throw new \Exception('La valeur doit être un booléen');
                }
                return $valeur ? 'true' : 'false';
                
            case 'JSON':
                if (is_string($valeur)) {
                    json_decode($valeur);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \Exception('JSON invalide');
                    }
                }
                return is_string($valeur) ? $valeur : json_encode($valeur);
                
            case 'DATE':
                if (!strtotime($valeur)) {
                    throw new \Exception('Date invalide');
                }
                return date('Y-m-d', strtotime($valeur));
                
            default:
                return (string) $valeur;
        }
    }

    /**
     * Formater la valeur selon le type
     */
    private function formaterValeur($valeur, $type)
    {
        switch ($type) {
            case 'BOOLEAN':
                return $valeur ? 'true' : 'false';
            case 'JSON':
                return is_string($valeur) ? $valeur : json_encode($valeur);
            default:
                return (string) $valeur;
        }
    }

    /**
     * Obtenir les catégories
     */
    public function categories()
    {
        $categories = ParametreSysteme::select('categorie')
            ->distinct()
            ->orderBy('categorie')
            ->pluck('categorie');

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    /**
     * Obtenir les paramètres publics
     */
    public function publics()
    {
        $params = ParametreSysteme::where('visible', true)
            ->get()
            ->mapWithKeys(function($item) {
                return [$item->cle => $item->valeur];
            });

        return response()->json([
            'success' => true,
            'data' => $params
        ]);
    }
}