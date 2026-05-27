<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Producto_K 10047 en SQL Server:\n";
print_r(\Illuminate\Support\Facades\DB::connection('sqlsrv')->table('producto_k')->where('id', 10047)->first());

echo "\nAnexos KA para 10047:\n";
$ka = \Illuminate\Support\Facades\DB::connection('sqlsrv')->table('anexos_ka')->where('producto_id', 10047)->get();
print_r($ka->toArray());

echo "\nAnexos AP para 10047:\n";
$ap = \Illuminate\Support\Facades\DB::connection('sqlsrv')->table('anexos_ap')->where('producto_id', 10047)->get();
print_r($ap->toArray());

