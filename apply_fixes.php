<?php
/**
 * Aplicación de correcciones de mapeo (PASOS 1 y 2).
 *  - Backup de todo lo afectado en tablas _bak_*
 *  - Limpieza de filas zombi (socio inexistente)
 *  - Reasignación comercial 20093 -> 20135 (Oskar, Araba Caza)
 * Todo dentro de transacción. Re-ejecutable (recrea backups).
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

function c($sql, $b = []) { return DB::selectOne($sql, $b)->c; }

const TARGET = 20135;   // Oskar Berdión, responsable Araba Caza
const ORIGEN = 20093;   // comercial borrado

echo "================ ESTADO PREVIO ================" . PHP_EOL;
$preSC = c("SELECT COUNT(*) c FROM socios_comerciales sc WHERE NOT EXISTS (SELECT 1 FROM socios s WHERE s.id = sc.id_socio)");
$preSP = c("SELECT COUNT(*) c FROM socios_productos sp WHERE NOT EXISTS (SELECT 1 FROM socios s WHERE s.id = sp.id_socio)");
$pre93 = c("SELECT COUNT(*) c FROM socios_comerciales WHERE id_comercial = ?", [ORIGEN]);
$pre35 = c("SELECT COUNT(*) c FROM socios_comerciales WHERE id_comercial = ?", [TARGET]);
echo "  socios_comerciales zombi (socio inexistente): $preSC" . PHP_EOL;
echo "  socios_productos   zombi (socio inexistente): $preSP" . PHP_EOL;
echo "  filas de comercial 20093 (a reasignar):       $pre93" . PHP_EOL;
echo "  filas actuales de comercial 20135 (Oskar):    $pre35" . PHP_EOL;

// Seguridad: que el target exista y el origen no
$okTarget = c("SELECT COUNT(*) c FROM comercial WHERE id = ?", [TARGET]);
$okOrigen = c("SELECT COUNT(*) c FROM comercial WHERE id = ?", [ORIGEN]);
echo "  comercial 20135 existe: " . ($okTarget ? 'SI' : 'NO') . " | comercial 20093 existe: " . ($okOrigen ? 'SI' : 'NO') . PHP_EOL;
if (!$okTarget) { echo "ABORTADO: el comercial destino 20135 no existe." . PHP_EOL; exit(1); }

// ¿Alguno de los 486 ya está ligado a 20135? (crearía duplicado)
$dups = c("SELECT COUNT(*) c FROM socios_comerciales a JOIN socios_comerciales b ON a.id_socio = b.id_socio WHERE a.id_comercial = ? AND b.id_comercial = ?", [ORIGEN, TARGET]);
echo "  socios de 20093 que YA están en 20135 (posibles duplicados): $dups" . PHP_EOL;

echo PHP_EOL . "================ BACKUPS ================" . PHP_EOL;
DB::statement("IF OBJECT_ID('_bak_sc_socio_huerfano') IS NOT NULL DROP TABLE _bak_sc_socio_huerfano");
DB::statement("SELECT sc.* INTO _bak_sc_socio_huerfano FROM socios_comerciales sc WHERE NOT EXISTS (SELECT 1 FROM socios s WHERE s.id = sc.id_socio)");

DB::statement("IF OBJECT_ID('_bak_sp_socio_huerfano') IS NOT NULL DROP TABLE _bak_sp_socio_huerfano");
DB::statement("SELECT sp.* INTO _bak_sp_socio_huerfano FROM socios_productos sp WHERE NOT EXISTS (SELECT 1 FROM socios s WHERE s.id = sp.id_socio)");

DB::statement("IF OBJECT_ID('_bak_sc_reasignacion_20093') IS NOT NULL DROP TABLE _bak_sc_reasignacion_20093");
DB::statement("SELECT sc.* INTO _bak_sc_reasignacion_20093 FROM socios_comerciales sc WHERE sc.id_comercial = " . ORIGEN);

$bSC = c("SELECT COUNT(*) c FROM _bak_sc_socio_huerfano");
$bSP = c("SELECT COUNT(*) c FROM _bak_sp_socio_huerfano");
$b93 = c("SELECT COUNT(*) c FROM _bak_sc_reasignacion_20093");
echo "  _bak_sc_socio_huerfano:      $bSC filas" . PHP_EOL;
echo "  _bak_sp_socio_huerfano:      $bSP filas" . PHP_EOL;
echo "  _bak_sc_reasignacion_20093:  $b93 filas" . PHP_EOL;

echo PHP_EOL . "================ MUTACIONES (transacción) ================" . PHP_EOL;
try {
    DB::beginTransaction();

    $d1 = DB::delete("DELETE FROM socios_comerciales WHERE NOT EXISTS (SELECT 1 FROM socios s WHERE s.id = socios_comerciales.id_socio)");
    echo "  [LIMPIEZA] socios_comerciales zombi borradas: $d1" . PHP_EOL;

    $d2 = DB::delete("DELETE FROM socios_productos WHERE NOT EXISTS (SELECT 1 FROM socios s WHERE s.id = socios_productos.id_socio)");
    echo "  [LIMPIEZA] socios_productos zombi borradas:   $d2" . PHP_EOL;

    $u = DB::update("UPDATE socios_comerciales SET id_comercial = ? WHERE id_comercial = ?", [TARGET, ORIGEN]);
    echo "  [REASIGNACIÓN] filas 20093 -> 20135:          $u" . PHP_EOL;

    DB::commit();
    echo "  COMMIT OK" . PHP_EOL;
} catch (\Throwable $e) {
    DB::rollBack();
    echo "  ROLLBACK por error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo PHP_EOL . "================ ESTADO POSTERIOR ================" . PHP_EOL;
echo "  socios_comerciales zombi: " . c("SELECT COUNT(*) c FROM socios_comerciales sc WHERE NOT EXISTS (SELECT 1 FROM socios s WHERE s.id = sc.id_socio)") . " (esperado 0)" . PHP_EOL;
echo "  socios_productos   zombi: " . c("SELECT COUNT(*) c FROM socios_productos sp WHERE NOT EXISTS (SELECT 1 FROM socios s WHERE s.id = sp.id_socio)") . " (esperado 0)" . PHP_EOL;
echo "  filas comercial 20093:    " . c("SELECT COUNT(*) c FROM socios_comerciales WHERE id_comercial = ?", [ORIGEN]) . " (esperado 0)" . PHP_EOL;
echo "  filas comercial 20135:    " . c("SELECT COUNT(*) c FROM socios_comerciales WHERE id_comercial = ?", [TARGET]) . " (antes $pre35, +$b93)" . PHP_EOL;
echo "  comercial inexistente en socios_comerciales: " . c("SELECT COUNT(*) c FROM socios_comerciales sc WHERE NOT EXISTS (SELECT 1 FROM comercial co WHERE co.id = sc.id_comercial)") . PHP_EOL;

echo PHP_EOL . "Backups conservados: _bak_sc_socio_huerfano, _bak_sp_socio_huerfano, _bak_sc_reasignacion_20093" . PHP_EOL;
echo "Reversión reasignación: UPDATE socios_comerciales SET id_comercial=20093 ... (ver _bak)." . PHP_EOL;
echo "FIN." . PHP_EOL;
