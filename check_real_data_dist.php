<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$total = \Illuminate\Support\Facades\DB::connection('sqlsrv')->table('producto_k')->count();
$dummy = \Illuminate\Support\Facades\DB::connection('sqlsrv')->table('producto_k')->where('dni', '66666666V')->count();
$empty = \Illuminate\Support\Facades\DB::connection('sqlsrv')->table('producto_k')->whereNull('dni')->orWhere('dni', '')->count();

echo "Total productos: $total\n";
echo "Dummy (66666666V): $dummy\n";
echo "Empty/Null: $empty\n";

$real = \Illuminate\Support\Facades\DB::connection('sqlsrv')
    ->table('producto_k')
    ->where('dni', '!=', '66666666V')
    ->whereNotNull('dni')
    ->where('dni', '!=', '')
    ->take(5)
    ->pluck('dni')->toArray();

echo "Real DNI samples:\n";
print_r($real);
