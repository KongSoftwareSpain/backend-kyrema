<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Checking for NULL prices in SQL Server...\n";

$nulls = \Illuminate\Support\Facades\DB::connection('sqlsrv')
    ->table('producto_k')
    ->whereNull('precio_final')
    ->orWhereNull('precio_total')
    ->count();

echo "Products with NULL prices: $nulls\n";

if ($nulls > 0) {
    echo "Sample products with NULL prices:\n";
    $samples = \Illuminate\Support\Facades\DB::connection('sqlsrv')
        ->table('producto_k')
        ->whereNull('precio_final')
        ->orWhereNull('precio_total')
        ->take(5)
        ->get();
    print_r($samples);
}
