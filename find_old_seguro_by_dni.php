<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$dni = '52838695J';
echo "Buscando seguros en MySQL para DNI: $dni\n";

$results = \Illuminate\Support\Facades\DB::connection('mysql')
    ->table('seguros_combinados')
    ->join('socios', 'seguros_combinados.id_socio', '=', 'socios.id_socio')
    ->where('socios.dni', $dni)
    ->get(['seguros_combinados.id_seguro', 'seguros_combinados.poliza_seguro', 'seguros_combinados.fecha_emision']);

foreach ($results as $r) {
    echo "ID: {$r->id_seguro} | Poliza: {$r->poliza_seguro} | Emision: {$r->fecha_emision}\n";
}
