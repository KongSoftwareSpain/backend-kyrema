<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "SQL Server Producto_K DNIs (Careful check):\n";
$new = \Illuminate\Support\Facades\DB::connection('sqlsrv')
    ->table('producto_k')
    ->take(5)
    ->pluck('dni')->toArray();

foreach ($new as $d) {
    echo "DNI: [" . $d . "] | Length: " . strlen($d) . "\n";
}

echo "\nMySQL Socios DNIs (Careful check):\n";
$old = \Illuminate\Support\Facades\DB::connection('mysql')
    ->table('socios')
    ->take(5)
    ->pluck('dni')->toArray();

foreach ($old as $d) {
    echo "DNI: [" . $d . "] | Length: " . strlen($d) . "\n";
}
