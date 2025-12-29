<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && in_array($user->role, ['admin', 'vendeur']);
    }

    public function rules(): array
    {
        return [
            'date_contrat' => ['nullable', 'date'],
            'produit' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'testQualite' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png'],
            'date_livraison_prevue' => ['nullable', 'date'],
            'produit_bon_livraison' => ['nullable', 'string', 'max:255'],
            'poids_bon_livraison' => ['nullable', 'string', 'max:100'],
            'destinataires' => ['nullable', 'string', 'max:255'],
            'livraisonClient' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png'],
            'agreageClient' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png'],
            'recouvrement' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png'],
            'pieceJustificative' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png'],
            'montant_encaisse' => ['nullable', 'numeric'],
            'commentaires' => ['nullable', 'string'],
        ];
    }
}

