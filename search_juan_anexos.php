<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$dni = '52838695J'; // DNI de Juan en producto 10047
$name = 'Juan';

echo "Buscando anexos de Juan (DNI: $dni) en SQL Server...\n";

$found_ka = \Illuminate\Support\Facades\DB::connection('sqlsrv')
    ->table('anexos_ka')
    ->where('dni', $dni)
    ->orWhere('nombre_socio', 'like', "%$name%")
    ->get();

echo "Encontrados en anexos_ka: " . count($found_ka) . "\n";
foreach ($found_ka as $f) {
    echo "  -> ID: {$f->id} | producto_id: {$f->producto_id} | Nombre: {$f->nombre_socio}\n";
}

$found_ap = \Illuminate\Support\Facades\DB::connection('sqlsrv')
    ->table('anexos_ap')
    ->where('dni_propietario', $dni)
    ->orWhere('nombre_completo_propietario', 'like', "%$name%")
    ->get();

echo "Encontrados en anexos_ap: " . count($found_ap) . "\n";
foreach ($found_ap as $f) {
    echo "  -> ID: {$f->id} | producto_id: {$f->producto_id} | Propietario: {$f->nombre_completo_propietario}\n";
}
