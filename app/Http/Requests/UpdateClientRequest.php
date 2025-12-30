<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && in_array($user->role, ['admin', 'vendeur']);
    }

    /**
     * Get the validation rules that apply to the request.
     * For updates we keep the same rule: nom_entreprise required.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nom_entreprise' => ['required', 'string', 'max:255'],
            'nom_client' => ['nullable', 'string', 'max:255'],
            'prenom_client' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'rue_numero' => ['nullable', 'string', 'max:255'],
            'quartier_lot' => ['nullable', 'string', 'max:255'],
            'ville' => ['nullable', 'string', 'max:255'],
            'code_postal' => ['nullable', 'string', 'max:50'],
            'informations' => ['nullable', 'string'],
        ];
    }
}

