<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Los seguros de ArabaCaza tienen pólizas que empiezan por 'ARAC' o '...1235...'
// Buscar en producto_k todas las pólizas de ArabaCaza
echo "=== Buscando pólizas de ArabaCaza en producto_k (por prefijo ARAC) ===\n";

$porsociedad = DB::connection('sqlsrv')
    ->table('producto_k')
    ->where('codigo_producto', 'LIKE', '%1235%')
    ->select('sociedad_id', DB::raw('COUNT(*) as total'))
    ->groupBy('sociedad_id')
    ->orderByDesc('total')
    ->get();

echo "Distribución por sociedad_id de pólizas '1235':\n";
foreach ($porsociedad as $r) {
    $nombre = DB::connection('sqlsrv')->table('sociedad')->where('id', $r->sociedad_id)->value('nombre') ?? 'Desconocida';
    echo "  sociedad_id={$r->sociedad_id} ({$nombre}): {$r->total} registros\n";
}

echo "\n";

// También buscar por prefijo ARAC
$porsociedadARAC = DB::connection('sqlsrv')
    ->table('producto_k')
    ->where('codigo_producto', 'LIKE', 'ARAC%')
    ->select('sociedad_id', DB::raw('COUNT(*) as total'))
    ->groupBy('sociedad_id')
    ->orderByDesc('total')
    ->get();

echo "Distribución por sociedad_id de pólizas 'ARAC*':\n";
foreach ($porsociedadARAC as $r) {
    $nombre = DB::connection('sqlsrv')->table('sociedad')->where('id', $r->sociedad_id)->value('nombre') ?? 'Desconocida';
    echo "  sociedad_id={$r->sociedad_id} ({$nombre}): {$r->total} registros\n";
}

echo "\n";

// Muestra de los primeros 5 con el prefijo ARAC
echo "Primeros 5 registros ARAC* en producto_k:\n";
$muestras = DB::connection('sqlsrv')->table('producto_k')
    ->where('codigo_producto', 'LIKE', 'ARAC%')
    ->limit(5)
    ->get(['id', 'codigo_producto', 'sociedad_id', 'subproducto_codigo', 'nombre_socio']);
foreach ($muestras as $m) {
    echo "  id={$m->id} | {$m->codigo_producto} | sociedad_id={$m->sociedad_id} | {$m->subproducto_codigo} | {$m->nombre_socio}\n";
}
