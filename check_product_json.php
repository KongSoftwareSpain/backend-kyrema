<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Product ID 10047 JSON:\n";
$p = \Illuminate\Support\Facades\DB::connection('sqlsrv')
    ->table('producto_k')
    ->where('id', 10047)
    ->first();

echo json_encode($p, JSON_PRETTY_PRINT);
