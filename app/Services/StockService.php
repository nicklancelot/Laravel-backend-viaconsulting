<?php

namespace App\Services;

use App\Models\Vente\Reception;
use Illuminate\Support\Facades\Log;
use App\Exceptions\StockInsufficientException;

class StockService
{
    /**
     * Décrémente le stock HE (quantite_recue) FIFO pour un produit donné.
     *
     * @param string $produit
     * @param float $poids
     * @return float montant restant non débité (0 si tout débité)
     */
    public function decrementForProduct(string $produit, float $poids): float
    {
        if (empty($produit) || $poids <= 0) {
            return $poids;
        }

        if (stripos($produit, 'HE') === false) {
            return $poids;
        }

        $remaining = (float) $poids;

        // Construire un pattern plus permissif en détectant les mots-clés
        $normalized = strtolower(str_replace(['_', '-'], ' ', trim($produit)));
        $normalized = $this->removeAccents($normalized);

        // Mots-clés pour les catégories
        $keywords = [
            'feuilles' => ['feuill', 'feuille', 'feuilles'],
            'clous' => ['clou', 'clous'],
            'griffes' => ['griff', 'griffe', 'griffes'],
        ];

        $foundPattern = null;
        foreach ($keywords as $group => $words) {
            foreach ($words as $w) {
                if (str_contains($normalized, $w)) {
                    $foundPattern = '%' . $w . '%';
                    break 2;
                }
            }
        }

        if ($foundPattern) {
            $receptions = Reception::whereRaw('LOWER(REPLACE(REPLACE(type_produit, "_", " "), "-", " ")) LIKE ?', [$foundPattern])
                ->where('quantite_recue', '>', 0)
                ->orderBy('id')
                ->get();
        } else {
            $searchPattern = '%'.strtolower(str_replace(['_', '-'], ' ', trim($produit))).'%';
            $receptions = Reception::whereRaw('LOWER(REPLACE(REPLACE(type_produit, "_", " "), "-", " ")) LIKE ?', [$searchPattern])
                ->where('quantite_recue', '>', 0)
                ->orderBy('id')
                ->get();
        }

        // Si aucune réception disponible pour ce produit
        if ($receptions->isEmpty()) {
            Log::warning('StockService: aucune réception trouvée pour le produit demandé', ['produit' => $produit]);
            throw new StockInsufficientException('Stock insuffisant: aucune réception pour ce produit');
        }

        foreach ($receptions as $reception) {
            if ($remaining <= 0) {
                break;
            }

            $available = (float) $reception->quantite_recue;
            if ($available <= 0) {
                continue;
            }

            if ($available >= $remaining) {
                $reception->quantite_recue = $available - $remaining;
                $reception->save();

                Log::debug('reception partially consumed (service)', ['reception_id' => $reception->id, 'before' => $available, 'after' => $reception->quantite_recue, 'debited' => $remaining]);

                $remaining = 0;
            } else {
                $reception->quantite_recue = 0;
                $reception->save();

                Log::debug('reception fully consumed (service)', ['reception_id' => $reception->id, 'before' => $available, 'debited' => $available]);

                $remaining -= $available;
            }
        }

        if ($remaining > 0) {
            Log::warning('StockService: quantité demandée supérieure au stock disponible', [
                'produit' => $produit,
                'poids_demande' => $poids,
                'reste_non_debite' => $remaining,
            ]);
            throw new StockInsufficientException('Stock insuffisant: quantité demandée supérieure au stock disponible');
        }

        Log::info('StockService: quantité soustraite des réceptions', [
            'produit' => $produit,
            'poids' => $poids,
        ]);

        return 0.0;
    }

    private function removeAccents(string $str): string
    {
        $unwanted_array = [
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ā'=>'a',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ē'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ī'=>'i',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ō'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ū'=>'u',
            'ç'=>'c','ñ'=>'n'
        ];
        return strtr(mb_strtolower($str), $unwanted_array);
    }

    /**
     * Restaure le stock HE pour un produit donné (opération inverse du decrement).
     * Tentative d'ajout sur la réception la plus récente correspondant au produit.
     *
     * @param string $produit
     * @param float $poids
     * @return void
     */
    public function restoreForProduct(string $produit, float $poids): void
    {
        if (empty($produit) || $poids <= 0) {
            return;
        }

        if (stripos($produit, 'HE') === false) {
            return;
        }

        $searchPattern = '%'.strtolower(str_replace(['_', '-'], ' ', trim($produit))).'%';

        // On cherche la réception la plus récente pour ajouter le poids restitué
        $reception = Reception::whereRaw('LOWER(REPLACE(REPLACE(type_produit, "_", " "), "-", " ")) LIKE ?', [$searchPattern])
            ->orderByDesc('id')
            ->first();

        if ($reception) {
            $before = (float) $reception->quantite_recue;
            $reception->quantite_recue = $before + $poids;
            $reception->save();

            Log::info('StockService: quantité restaurée sur réception', ['reception_id' => $reception->id, 'before' => $before, 'after' => $reception->quantite_recue, 'restored' => $poids]);
        } else {
            Log::warning('StockService: aucune réception trouvée pour restaurer le stock', ['produit' => $produit, 'poids' => $poids]);
        }
    }
}
