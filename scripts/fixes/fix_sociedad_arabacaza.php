<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$idMysql  = 90;
$idSqlsrv = 10094;

echo "=== FIX: Corregir sociedad_id de seguros ArabaCaza en producto_k ===\n\n";

// 1. Obtener TODAS las pólizas de ArabaCaza en MySQL (borrado=0, finalizado=0)
$polizasMysql = DB::connection('mysql')
    ->table('seguros_combinados')
    ->where('id_sociedad', $idMysql)
    ->where('borrado', 0)
    ->where('finalizado', 0)
    ->pluck('poliza_seguro')
    ->filter()
    ->values()
    ->toArray();

echo "Pólizas de ArabaCaza en MySQL (activas): " . count($polizasMysql) . "\n";

// 2. Ver cuántas existen en SQL Server con sociedad_id incorrecto
$incorrectas = DB::connection('sqlsrv')
    ->table('producto_k')
    ->whereIn('codigo_producto', $polizasMysql)
    ->where('sociedad_id', '!=', $idSqlsrv)
    ->count();

$correctas = DB::connection('sqlsrv')
    ->table('producto_k')
    ->whereIn('codigo_producto', $polizasMysql)
    ->where('sociedad_id', $idSqlsrv)
    ->count();

echo "En SQL Server con sociedad_id CORRECTO ({$idSqlsrv}): {$correctas}\n";
echo "En SQL Server con sociedad_id INCORRECTO: {$incorrectas}\n\n";

if ($incorrectas === 0) {
    echo "✅ No hay registros con sociedad_id incorrecto. Nada que corregir.\n";
    exit(0);
}

// 3. Ver qué sociedad_id tienen los incorrectos
$distribucion = DB::connection('sqlsrv')
    ->table('producto_k')
    ->whereIn('codigo_producto', $polizasMysql)
    ->where('sociedad_id', '!=', $idSqlsrv)
    ->select('sociedad_id', DB::raw('COUNT(*) as total'))
    ->groupBy('sociedad_id')
    ->get();

echo "Distribución de sociedad_id incorrecto:\n";
foreach ($distribucion as $d) {
    $nombre = DB::connection('sqlsrv')->table('sociedad')->where('id', $d->sociedad_id)->value('nombre');
    echo "  sociedad_id={$d->sociedad_id} ({$nombre}): {$d->total}\n";
}
echo "\n";

// 4. Confirmar y ejecutar el UPDATE
echo "¿Ejecutar UPDATE de {$incorrectas} registros a sociedad_id={$idSqlsrv}? (s/n): ";
$linea = trim(fgets(STDIN));

if (strtolower($linea) !== 's') {
    echo "Cancelado.\n";
    exit(0);
}

// Ejecutar en chunks de 500 para evitar queries gigantes
$chunks = array_chunk($polizasMysql, 500);
$totalActualizados = 0;

foreach ($chunks as $chunk) {
    $updated = DB::connection('sqlsrv')
        ->table('producto_k')
        ->whereIn('codigo_producto', $chunk)
        ->where('sociedad_id', '!=', $idSqlsrv)
        ->update([
            'sociedad_id' => $idSqlsrv,
            'updated_at'  => DB::raw("GETDATE()"),
        ]);
    $totalActualizados += $updated;
    echo "  Chunk procesado: {$updated} actualizados\n";
}

echo "\n✅ Total actualizados: {$totalActualizados}\n";

// 5. Verificación final
$totalFinal = DB::connection('sqlsrv')
    ->table('producto_k')
    ->where('sociedad_id', $idSqlsrv)
    ->count();
echo "Total en producto_k con sociedad_id={$idSqlsrv}: {$totalFinal}\n";
