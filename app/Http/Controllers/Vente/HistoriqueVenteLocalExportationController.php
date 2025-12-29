<?php

namespace App\Http\Controllers\Vente;

use App\Http\Controllers\Controller;
use App\Models\Vente\Local;
use App\Models\Vente\Exportation;
use Illuminate\Support\Facades\Auth;

class HistoriqueVenteLocalExportationController extends Controller
{
    /**
     * Récupère les ventes locales et exportations en une seule réponse.
     */
    public function index()
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role, ['admin', 'vendeur'])) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        if ($user->role === 'admin') {
            $locals = Local::with('client')->get();
            $exportations = Exportation::with('client')->get();
        } else {
            $locals = Local::with('client')->where('utilisateur_id', $user->id)->get();
            $exportations = Exportation::with('client')->where('utilisateur_id', $user->id)->get();
        }

        return response()->json([
            'locals' => $locals,
            'exportations' => $exportations,
        ]);
    }
    
}

