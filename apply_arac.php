<?php
/**
 * Reasignación productos ARAC (Araba Caza) de la cuenta-basura 20083 -> Oskar 20135.
 *  - 388 productos: comercial_id y comercial_creador_id -> 20135
 *  - 5 de ellos con sociedad_id=10084 (mal etiquetado) -> 10094
 * Backup + transacción. NO toca socios_comerciales.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

function c($sql, $b = []) { return DB::selectOne($sql, $b)->c; }
const ORIGEN = 20083;
const OSKAR  = 20135;

echo "================ ESTADO PREVIO ================" . PHP_EOL;
echo "  productos con comercial_id=20083:           " . c("SELECT COUNT(*) c FROM PRODUCTO_K WHERE comercial_id=?", [ORIGEN]) . PHP_EOL;
echo "  de ellos con sociedad_id=10084 (mal etiq.):  " . c("SELECT COUNT(*) c FROM PRODUCTO_K WHERE comercial_id=? AND sociedad_id=10084", [ORIGEN]) . PHP_EOL;
echo "  productos de Oskar (20135) actuales:         " . c("SELECT COUNT(*) c FROM PRODUCTO_K WHERE comercial_id=?", [OSKAR]) . PHP_EOL;

echo PHP_EOL . "================ BACKUP ================" . PHP_EOL;
DB::statement("IF OBJECT_ID('_bak_prodk_arac_20083') IS NOT NULL DROP TABLE _bak_prodk_arac_20083");
DB::statement("SELECT * INTO _bak_prodk_arac_20083 FROM PRODUCTO_K WHERE comercial_id = " . ORIGEN);
echo "  _bak_prodk_arac_20083: " . c("SELECT COUNT(*) c FROM _bak_prodk_arac_20083") . " filas (snapshot completo)" . PHP_EOL;

echo PHP_EOL . "================ MUTACIONES (transacción) ================" . PHP_EOL;
try {
    DB::beginTransaction();

    $s = DB::update("UPDATE PRODUCTO_K SET sociedad_id=10094 WHERE comercial_id=? AND sociedad_id=10084", [ORIGEN]);
    echo "  sociedad_id 10084->10094 corregidos: $s" . PHP_EOL;

    $cre = DB::update("UPDATE PRODUCTO_K SET comercial_creador_id=? WHERE comercial_id=? AND comercial_creador_id=?", [OSKAR, ORIGEN, ORIGEN]);
    echo "  comercial_creador_id 20083->20135:    $cre" . PHP_EOL;

    $com = DB::update("UPDATE PRODUCTO_K SET comercial_id=? WHERE comercial_id=?", [OSKAR, ORIGEN]);
    echo "  comercial_id 20083->20135:            $com" . PHP_EOL;

    DB::commit();
    echo "  COMMIT OK" . PHP_EOL;
} catch (\Throwable $e) {
    DB::rollBack();
    echo "  ROLLBACK por error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo PHP_EOL . "================ ESTADO POSTERIOR ================" . PHP_EOL;
echo "  productos con comercial_id=20083:  " . c("SELECT COUNT(*) c FROM PRODUCTO_K WHERE comercial_id=?", [ORIGEN]) . " (esperado 0)" . PHP_EOL;
echo "  productos de Oskar (20135):        " . c("SELECT COUNT(*) c FROM PRODUCTO_K WHERE comercial_id=?", [OSKAR]) . PHP_EOL;
echo "  productos de Oskar con soc<>10094: " . c("SELECT COUNT(*) c FROM PRODUCTO_K WHERE comercial_id=? AND sociedad_id<>10094", [OSKAR]) . " (esperado 0)" . PHP_EOL;
echo "  productos K con comercial de otra sociedad (global): " . c("SELECT COUNT(*) c FROM PRODUCTO_K p JOIN comercial co ON p.comercial_id=co.id WHERE p.sociedad_id IS NOT NULL AND co.id_sociedad<>p.sociedad_id") . PHP_EOL;
echo "  comercial 20083 sigue existiendo (Picos): " . (c("SELECT COUNT(*) c FROM comercial WHERE id=?", [ORIGEN]) ? 'SI (intacto, responsable de 10084)' : 'NO') . PHP_EOL;

echo PHP_EOL . "Backup: _bak_prodk_arac_20083. Reversión: restaurar comercial_id/creador/sociedad_id desde el backup por id. FIN." . PHP_EOL;
