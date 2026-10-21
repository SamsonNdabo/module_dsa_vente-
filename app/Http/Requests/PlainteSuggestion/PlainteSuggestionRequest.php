<?php

namespace App\Http\Requests\PlainteSuggestion;

use App\Models\PlainteSuggestion\Plainte;
use App\Models\PlainteSuggestion\Suggestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlainteSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /* =========================================================
       DÉTECTION DU CONTEXTE
       ========================================================= */

    protected function estSuggestion(): bool
    {
        if ($this->filled('_type')) {
            return strtolower($this->input('_type')) === 'suggestion';
        }

        $routeName = strtolower($this->route()?->getName() ?? '');
        $path      = strtolower($this->path());

        return str_contains($routeName, 'suggestion') || str_contains($path, 'suggestions');
    }

    protected function action(): string
    {
        if ($this->filled('_action')) {
            return $this->input('_action');
        }

        $routeName = $this->route()?->getName() ?? '';
        $method    = $this->method();

        if (str_contains($routeName, 'affecter')) return 'affecter';
        if (str_contains($routeName, 'statut'))   return 'statut';
        if (str_contains($routeName, 'pieces'))   return 'preuves';

        return match ($method) {
            'POST'         => 'store',
            'PUT', 'PATCH' => 'update',
            default        => 'index',
        };
    }

    public function rules(): array
    {
        return match ($this->action()) {
            'store'    => $this->rulesStore(),
            'update'   => $this->rulesUpdate(),
            'affecter' => $this->rulesAffecter(),
            'statut'   => $this->rulesStatut(),
            'preuves'  => $this->rulesPreuves(),
            default    => [],
        };
    }

    /* =========================================================
       CRÉATION
       ========================================================= */
    protected function rulesStore(): array
    {
        return [
            'client_id'      => ['required', 'exists:clients,id'],
            'categorie'      => ['nullable', 'string', 'max:191'],
            'utilisateur_id' => ['nullable', 'exists:utilisateurs,id'],
            'objet'          => ['required', 'string', 'max:191'],
            'description'    => ['required', 'string'],
            'priorite'       => ['nullable', Rule::in($this->priorites())],
            'zone'           => ['nullable', 'string', 'max:191'],
            'latitude'       => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'      => ['nullable', 'numeric', 'between:-180,180'],
            'adresse_gps'    => ['nullable', 'string', 'max:191'],
            'preuves'        => ['nullable', 'array', 'max:10'],
            'preuves.*'      => ['file', 'mimes:jpg,jpeg,png,pdf,doc,docx,mp4', 'max:10240'],
        ];
    }

    /* =========================================================
       MISE À JOUR
       ========================================================= */
    protected function rulesUpdate(): array
    {
        return [
            'categorie'   => ['nullable', 'string', 'max:191'],
            'objet'       => ['sometimes', 'string', 'max:191'],
            'description' => ['sometimes', 'string'],
            'priorite'    => ['nullable', Rule::in($this->priorites())],
            'zone'        => ['nullable', 'string', 'max:191'],
            'latitude'    => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'   => ['nullable', 'numeric', 'between:-180,180'],
            'adresse_gps' => ['nullable', 'string', 'max:191'],
        ];
    }

    /* =========================================================
       AFFECTATION
       ========================================================= */
    protected function rulesAffecter(): array
    {
        return [
            'utilisateur_id' => ['required', 'exists:utilisateurs,id'],
            'commentaire'    => ['nullable', 'string', 'max:2000'],
            'priorite'       => ['nullable', Rule::in($this->priorites())],
        ];
    }

    /* =========================================================
       CHANGEMENT DE STATUT
       ========================================================= */
    protected function rulesStatut(): array
    {
        $statuts          = $this->statuts();
        $statutResolution = $this->statutResolution();
        $statutRejet      = $this->statutRejet();

        return [
            'statut' => ['required', Rule::in($statuts)],
            'commentaire_resolution' => [
                'nullable',
                'string',
                'max:2000',
                Rule::requiredIf(fn () => in_array($this->input('statut'), [
                    $statutResolution,
                    $statutRejet,
                ])),
            ],
        ];
    }

    /* =========================================================
       UPLOAD PREUVES
       ========================================================= */
    protected function rulesPreuves(): array
    {
        return [
            'preuves'   => ['required', 'array', 'min:1', 'max:10'],
            'preuves.*' => ['file', 'mimes:jpg,jpeg,png,pdf,doc,docx,mp4', 'max:10240'],
        ];
    }

    /* =========================================================
       MESSAGES
       ========================================================= */
    public function messages(): array
    {
        $type = $this->estSuggestion() ? 'suggestion' : 'plainte';

        return [
            'client_id.required'      => 'Le client est obligatoire.',
            'client_id.exists'        => 'Ce client n\'existe pas.',
            'objet.required'          => "L'objet de la {$type} est obligatoire.",
            'description.required'    => 'La description est obligatoire.',
            'priorite.in'             => 'Priorité invalide (BASSE, NORMALE, HAUTE, CRITIQUE).',
            'utilisateur_id.required' => "L'agent est obligatoire.",
            'utilisateur_id.exists'   => "Cet utilisateur/agent n'existe pas.",
            'statut.required'         => 'Le statut est obligatoire.',
            'statut.in'               => 'Statut invalide.',
            'commentaire_resolution.required_if' => 'Un commentaire est obligatoire pour résoudre ou rejeter.',
            'preuves.array'           => 'Les preuves doivent être envoyées sous forme de tableau.',
            'preuves.min'             => 'Ajoutez au moins 1 preuve.',
            'preuves.max'             => 'Maximum 10 preuves à la fois.',
            'preuves.*.file'          => 'Chaque preuve doit être un fichier valide.',
            'preuves.*.mimes'         => 'Formats acceptés : jpg, jpeg, png, pdf, doc, docx, mp4.',
            'preuves.*.max'           => 'Chaque fichier ne doit pas dépasser 10 Mo.',
        ];
    }

    /* =========================================================
       ATTRIBUTS LISIBLES
       ========================================================= */
    public function attributes(): array
    {
        return [
            'client_id'              => 'client',
            'utilisateur_id'         => 'utilisateur/agent',
            'categorie'              => 'catégorie',
            'objet'                  => 'objet',
            'description'            => 'description',
            'priorite'               => 'priorité',
            'zone'                   => 'zone',
            'latitude'               => 'latitude',
            'longitude'              => 'longitude',
            'adresse_gps'            => 'adresse GPS',
            'statut'                 => 'statut',
            'commentaire_resolution' => 'commentaire de résolution',
            'preuves'                => 'preuves',
            'preuves.*'              => 'preuve',
        ];
    }

    /* =========================================================
       HELPERS
       ========================================================= */

    protected function statuts(): array
    {
        return $this->estSuggestion()
            ? [
                Suggestion::STATUT_NOUVELLE,
                Suggestion::STATUT_EN_COURS,
                Suggestion::STATUT_RESOLUE,
                Suggestion::STATUT_REJETEE,
            ]
            : [
                Plainte::STATUT_NOUVELLE,
                Plainte::STATUT_EN_COURS,
                Plainte::STATUT_RESOLUE,
                Plainte::STATUT_REJETEE,
            ];
    }

    protected function statutResolution(): string
    {
        return $this->estSuggestion() ? Suggestion::STATUT_RESOLUE : Plainte::STATUT_RESOLUE;
    }

    protected function statutRejet(): string
    {
        return $this->estSuggestion() ? Suggestion::STATUT_REJETEE : Plainte::STATUT_REJETEE;
    }

    protected function priorites(): array
    {
        return $this->estSuggestion()
            ? [
                Suggestion::PRIORITE_BASSE,
                Suggestion::PRIORITE_NORMALE,
                Suggestion::PRIORITE_HAUTE,
                Suggestion::PRIORITE_CRITIQUE,
            ]
            : [
                Plainte::PRIORITE_BASSE,
                Plainte::PRIORITE_NORMALE,
                Plainte::PRIORITE_HAUTE,
                Plainte::PRIORITE_CRITIQUE,
            ];
    }
}