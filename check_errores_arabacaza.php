<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Ver columnas de seguros_combinados que tengan "postal" o "cp" en el nombre
echo "=== Columnas con 'postal' o 'cp' en seguros_combinados ===\n";
$cols = DB::connection('mysql')->select("SHOW COLUMNS FROM seguros_combinados");
foreach ($cols as $c) {
    $field = strtolower($c->Field);
    if (str_contains($field, 'postal') || str_contains($field, '_cp') || $field === 'cp' || str_contains($field, 'codigo_p')) {
        echo "{$c->Field} ({$c->Type})\n";
    }
}
echo "\n";

// Los registros con error - usar columnas reales
echo "=== Registros con error en MySQL (IDs: 32917, 33483, 33728) ===\n";
$idsError = [32917, 33483, 33728];
foreach ($idsError as $id) {
    $s = DB::connection('mysql')->table('seguros_combinados')
        ->where('id_seguro', $id)
        ->first();
    if ($s) {
        // Extraer solo campos relevantes para diagnóstico
        $datos = (array) $s;
        // Buscar campos que parezcan código postal
        $cpFields = array_filter($datos, fn($val, $key) => str_contains(strtolower($key), 'postal') || str_contains(strtolower($key), '_cp'), ARRAY_FILTER_USE_BOTH);
        echo "ID {$id} ({$s->poliza_seguro}):\n";
        foreach ($cpFields as $k => $v) {
            echo "  {$k} = " . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
        }
        echo "\n";
    }
}
