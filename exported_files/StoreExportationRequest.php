<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExportationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'numero_contrat' => 'nullable|string',
            'date_contrat' => 'nullable|date',
            'produit' => 'nullable|string',
            'poids' => 'nullable|string',
            'designation' => 'nullable|string',
            'prix_unitaire' => 'nullable|numeric',
            'prix_total' => 'nullable|numeric',
            'frais_transport' => 'nullable|numeric',
            'client_id' => 'nullable|integer|exists:clients,id',
            'devis' => 'nullable|file',
            'proforma' => 'nullable|file',
            'phytosanitaire' => 'nullable|file',
            'eauxForets' => 'nullable|file',
            'miseFobCif' => 'nullable|file',
            'livraisonTransitaire' => 'nullable|file',
            'transmissionDocuments' => 'nullable|file',
            'recouvrement' => 'nullable|file',
            'pieceJustificative' => 'nullable|file',
            'montant_encaisse' => 'nullable|numeric',
            'commentaires' => 'nullable|string',
        ];
    }
}
