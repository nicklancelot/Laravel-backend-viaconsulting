<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Vente\Reception;
use App\Services\StockService;

$pattern = '%he feuilles%';
echo "Receptions matching 'HE feuilles' BEFORE:\n";
$rows = Reception::whereRaw('LOWER(REPLACE(REPLACE(type_produit, "_", " "), "-", " ")) LIKE ?', [$pattern])->get();
foreach ($rows as $r) {
    echo "id={$r->id} type_produit={$r->type_produit} quantite_recue={$r->quantite_recue}\n";
}

$service = new StockService();
$remaining = $service->decrementForProduct('HE Feuilles', 2);

echo "\nAfter decrement 2 kg, remaining not debited: {$remaining}\n";

echo "Receptions AFTER:\n";
$rows = Reception::whereRaw('LOWER(REPLACE(REPLACE(type_produit, "_", " "), "-", " ")) LIKE ?', [$pattern])->get();
foreach ($rows as $r) {
    echo "id={$r->id} type_produit={$r->type_produit} quantite_recue={$r->quantite_recue}\n";
}
