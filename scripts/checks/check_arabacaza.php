<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$idMysql  = 90;    // ArabaCaza en MySQL
$idSqlsrv = 10094; // ArabaCaza en SQL Server (confirmado por el usuario)

echo "=== DIAGNÓSTICO ARABACAZA ===\n\n";

// 1. Confirmar que ArabaCaza existe en MySQL
echo "--- MySQL: Sociedad id=90 ---\n";
$soc = DB::connection('mysql')->table('sociedades')->where('id_sociedad', $idMysql)->first();
if ($soc) {
    echo "Nombre: {$soc->nombre}\n";
    echo "codigo_sociedad: " . ($soc->codigo_sociedad ?? 'NULL') . "\n";
} else {
    echo "NO ENCONTRADA en MySQL!\n";
}
echo "\n";

// 2. Confirmar que existe en SQL Server
echo "--- SQL Server: Sociedad id=10094 ---\n";
$socSql = DB::connection('sqlsrv')->table('sociedad')->where('id', $idSqlsrv)->first();
if ($socSql) {
    echo "ID: {$socSql->id}, Nombre: {$socSql->nombre}\n";
    echo "codigo_sociedad: " . ($socSql->codigo_sociedad ?? 'NULL') . "\n";
} else {
    echo "ID {$idSqlsrv} NO encontrado!\n";
}
echo "\n";

// 3. Cuántos seguros de cacería tiene ArabaCaza en MySQL
echo "--- Seguros de cacería en MySQL (id_sociedad=90) ---\n";
$totalCaceria = DB::connection('mysql')->table('seguro_cacerias')
    ->where('id_sociedad', $idMysql)->where('borrado', 0)->count();
$totalCaceriaAnulados = DB::connection('mysql')->table('seguro_cacerias')
    ->where('id_sociedad', $idMysql)->where('borrado', 1)->count();
echo "Activos (borrado=0): {$totalCaceria}\n";
echo "Anulados (borrado=1): {$totalCaceriaAnulados}\n";
echo "\n";

// 4. Tipos de pago usados por ArabaCaza
echo "--- Tipos de pago usados por ArabaCaza en cacería ---\n";
$tiposPago = DB::connection('mysql')->table('seguro_cacerias')
    ->where('id_sociedad', $idMysql)->where('borrado', 0)
    ->select('tipo_pago', DB::raw('COUNT(*) as total'))
    ->groupBy('tipo_pago')->get();
foreach ($tiposPago as $tp) {
    echo "  tipo_pago={$tp->tipo_pago}: {$tp->total} registros\n";
}
echo "\n";

// 5. ¿Hay seguros de otras tablas?
echo "--- Otras tablas de seguros para id_sociedad=90 ---\n";
$tablas = ['seguros_combinados', 'seguro_rehalas', 'seguro_servicios_juridicos', 'seguro_perros'];
foreach ($tablas as $tabla) {
    try {
        $exists = DB::connection('mysql')->getSchemaBuilder()->hasTable($tabla);
        if (!$exists) {
            echo "  {$tabla}: tabla no existe en MySQL\n";
            continue;
        }
        $count = DB::connection('mysql')->table($tabla)->where('id_sociedad', $idMysql)->count();
        echo "  {$tabla}: {$count} registros de ArabaCaza\n";
    } catch (\Exception $e) {
        echo "  {$tabla}: " . $e->getMessage() . "\n";
    }
}
echo "\n";

// 6. ¿Cuántos seguros de ArabaCaza ya están en SQL Server?
echo "--- Seguros de ArabaCaza ya en SQL Server (producto_c) ---\n";
try {
    $yaEnSqlsrv = DB::connection('sqlsrv')->table('producto_c')
        ->where('sociedad_id', $idSqlsrv)->count();
    echo "producto_c con sociedad_id={$idSqlsrv}: {$yaEnSqlsrv}\n";
} catch (\Exception $e) {
    echo "Error al consultar producto_c: " . $e->getMessage() . "\n";
}
echo "\n";

// 7. Comerciales (users emisores) de ArabaCaza en MySQL y su estado en SQL Server
echo "--- Comerciales emisores de seguros ArabaCaza (top 10) ---\n";
$emisores = DB::connection('mysql')->table('seguro_cacerias')
    ->where('id_sociedad', $idMysql)->where('borrado', 0)
    ->select('id_emisor', DB::raw('COUNT(*) as total'))
    ->groupBy('id_emisor')
    ->orderByDesc('total')->limit(10)->get();
foreach ($emisores as $e) {
    $user = DB::connection('mysql')->table('users')->where('id_user', $e->id_emisor)->first();
    $nombre = $user->nombre ?? ($user->email ?? 'Desconocido');
    $email  = $user->email ?? '';
    // Comprobar si existe en SQL Server por email
    $existeEnKong = $email
        ? DB::connection('sqlsrv')->table('comercial')->where('email', $email)->value('id')
        : null;
    $estadoKong = $existeEnKong ? "✔ en KONG id={$existeEnKong}" : "✘ NO en KONG";
    echo "  id_emisor={$e->id_emisor} | {$nombre} | {$email} | {$e->total} seguros | {$estadoKong}\n";
}
echo "\n";

echo "=== FIN DIAGNÓSTICO ===\n";
