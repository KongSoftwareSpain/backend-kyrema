<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "SQL Server Producto_K DNIs:\n";
$new = \Illuminate\Support\Facades\DB::connection('sqlsrv')
    ->table('producto_k')
    ->take(5)
    ->pluck('dni')->toArray();
print_r($new);

echo "\nMySQL Socios DNIs:\n";
$old = \Illuminate\Support\Facades\DB::connection('mysql')
    ->table('socios')
    ->take(5)
    ->pluck('dni')->toArray();
print_r($old);
