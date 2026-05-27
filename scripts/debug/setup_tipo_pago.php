<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== VERIFICANDO TIPO_PAGO_SOCIEDAD ===\n";
try {
    $relaciones = DB::connection('sqlsrv')->table('tipo_pago_sociedad')->get();
    echo "Registros encontrados: " . $relaciones->count() . "\n";
    foreach ($relaciones as $r) {
        echo "Sociedad: {$r->id_sociedad}, Pago: {$r->id_pago}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== CREANDO RELACIÓN SOCIEDAD 1 + TIPO PAGO 1 ===\n";
try {
    DB::connection('sqlsrv')->table('tipo_pago_sociedad')->insertOrIgnore([
        'id_sociedad' => 1,
        'id_pago' => 1,
        'created_at' => now(),
        'updated_at' => now()
    ]);
    echo "✓ Relación creada o ya existía\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
