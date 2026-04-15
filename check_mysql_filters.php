<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Checking MySQL counts (borrado=0, finalizado=0)...\n";

$total = \Illuminate\Support\Facades\DB::connection('mysql')
    ->table('seguros_combinados')
    ->where('borrado', 0)
    ->where('finalizado', 0)
    ->count();

echo "Total potential records to migrate: $total\n";

$dni_10047 = '52838695J';
$check_10047 = \Illuminate\Support\Facades\DB::connection('mysql')
    ->table('seguros_combinados')
    ->join('socios', 'seguros_combinados.id_socio', '=', 'socios.id_socio')
    ->where('socios.dni', $dni_10047)
    ->count();

echo "Records in MySQL for DNI $dni_10047: $check_10047\n";
