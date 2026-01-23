<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== SOCIEDADES ===\n";
$sociedades = DB::connection('sqlsrv')->table('sociedades')->select('id', 'nombre')->get();
foreach ($sociedades as $s) {
    echo "ID: {$s->id}, Nombre: {$s->nombre}\n";
}

echo "\n=== TIPOS DE PAGO ===\n";
$tiposPago = DB::connection('sqlsrv')->table('tipo_pago')->select('id', 'nombre')->get();
foreach ($tiposPago as $tp) {
    echo "ID: {$tp->id}, Nombre: {$tp->nombre}\n";
}

echo "\n=== RELACIONES TIPO_PAGO_SOCIEDAD ===\n";
$relaciones = DB::connection('sqlsrv')->table('tipo_pago_sociedad')->select('id_sociedad', 'id_pago')->get();
foreach ($relaciones as $r) {
    echo "Sociedad: {$r->id_sociedad}, Pago: {$r->id_pago}\n";
}
