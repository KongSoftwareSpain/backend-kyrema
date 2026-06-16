<?php
/**
 * Fusión de comerciales duplicados (PASO 4).
 *  10027: 20026 -> 23   (conservar Joaquin)
 *  10028: 20027 -> 22   (conservar Manuel, corregir email)
 * Mueve socios + productos, deduplica solapes, jubila la cuenta origen (responsable=0).
 * Backups en _bak_*. Transacción. NO borra cuentas.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

function c($sql, $b = []) { return DB::selectOne($sql, $b)->c; }
function colExists($t, $col) { return count(DB::select("SELECT 1 x FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=? AND COLUMN_NAME=?", [$t, $col])) > 0; }

// Pares: origen => destino
$fusiones = [
    ['origen' => 20026, 'destino' => 23, 'soc' => 10027],
    ['origen' => 20027, 'destino' => 22, 'soc' => 10028],
];
$NUEVO_EMAIL_22 = 'info@tecorportas.com';

// Tablas físicas producto + anexos
$tablas = collect(DB::select("SELECT TABLE_NAME t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' AND (TABLE_NAME LIKE 'PRODUCTO[_]%' OR TABLE_NAME LIKE 'ANEXOS[_]%')"))->pluck('t')->all();

echo "================ ESTADO PREVIO ================" . PHP_EOL;
foreach ($fusiones as $f) {
    $o = $f['origen']; $d = $f['destino'];
    echo "  $o -> $d  | socios origen=" . c("SELECT COUNT(*) c FROM socios_comerciales WHERE id_comercial=?", [$o])
        . "  socios destino=" . c("SELECT COUNT(*) c FROM socios_comerciales WHERE id_comercial=?", [$d]) . PHP_EOL;
    $solape = c("SELECT COUNT(DISTINCT a.id_socio) c FROM socios_comerciales a JOIN socios_comerciales b ON a.id_socio=b.id_socio WHERE a.id_comercial=? AND b.id_comercial=?", [$o, $d]);
    echo "       socios en AMBAS cuentas (solape a deduplicar): $solape" . PHP_EOL;
}

echo PHP_EOL . "================ BACKUPS ================" . PHP_EOL;
DB::statement("IF OBJECT_ID('_bak_sc_fusion') IS NOT NULL DROP TABLE _bak_sc_fusion");
DB::statement("SELECT * INTO _bak_sc_fusion FROM socios_comerciales WHERE id_comercial IN (20026,20027)");
DB::statement("IF OBJECT_ID('_bak_comercial_fusion') IS NOT NULL DROP TABLE _bak_comercial_fusion");
DB::statement("SELECT * INTO _bak_comercial_fusion FROM comercial WHERE id IN (22,23,20026,20027)");
echo "  _bak_sc_fusion: " . c("SELECT COUNT(*) c FROM _bak_sc_fusion") . " filas socios_comerciales" . PHP_EOL;
echo "  _bak_comercial_fusion: " . c("SELECT COUNT(*) c FROM _bak_comercial_fusion") . " cuentas" . PHP_EOL;

// Backup de productos afectados (si los hubiera)
$prodAfectados = 0;
foreach ($tablas as $t) {
    if (colExists($t,'comercial_id')) $prodAfectados += c("SELECT COUNT(*) c FROM [$t] WHERE comercial_id IN (20026,20027)");
    if (colExists($t,'comercial_creador_id')) $prodAfectados += c("SELECT COUNT(*) c FROM [$t] WHERE comercial_creador_id IN (20026,20027)");
}
echo "  productos que referencian 20026/20027 (comercial_id o creador): $prodAfectados" . PHP_EOL;

echo PHP_EOL . "================ MUTACIONES (transacción) ================" . PHP_EOL;
try {
    DB::beginTransaction();

    foreach ($fusiones as $f) {
        $o = $f['origen']; $d = $f['destino'];

        // 1) Deduplicar solapes: borrar la fila del ORIGEN cuando el socio ya está en DESTINO
        $dedup = DB::delete("DELETE FROM socios_comerciales WHERE id_comercial=? AND id_socio IN (SELECT id_socio FROM socios_comerciales WHERE id_comercial=?)", [$o, $d]);
        echo "  [$o->$d] solapes eliminados del origen: $dedup" . PHP_EOL;

        // 2) Mover socios restantes
        $movS = DB::update("UPDATE socios_comerciales SET id_comercial=? WHERE id_comercial=?", [$d, $o]);
        echo "  [$o->$d] socios movidos: $movS" . PHP_EOL;

        // 3) Mover productos (todas las tablas)
        $movP = 0;
        foreach ($tablas as $t) {
            if (colExists($t,'comercial_id'))         $movP += DB::update("UPDATE [$t] SET comercial_id=? WHERE comercial_id=?", [$d, $o]);
            if (colExists($t,'comercial_creador_id')) $movP += DB::update("UPDATE [$t] SET comercial_creador_id=? WHERE comercial_creador_id=?", [$d, $o]);
        }
        echo "  [$o->$d] referencias de producto movidas: $movP" . PHP_EOL;

        // 4) Jubilar la cuenta origen
        DB::update("UPDATE comercial SET responsable=0 WHERE id=?", [$o]);
        echo "  [$o->$d] comercial $o -> responsable=0" . PHP_EOL;
    }

    // 5) Corregir email de la cuenta 22
    DB::update("UPDATE comercial SET email=? WHERE id=22", [$NUEVO_EMAIL_22]);
    echo "  comercial 22 email -> $NUEVO_EMAIL_22" . PHP_EOL;

    DB::commit();
    echo "  COMMIT OK" . PHP_EOL;
} catch (\Throwable $e) {
    DB::rollBack();
    echo "  ROLLBACK por error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo PHP_EOL . "================ ESTADO POSTERIOR ================" . PHP_EOL;
foreach ([['s'=>10027,'keep'=>23,'old'=>20026],['s'=>10028,'keep'=>22,'old'=>20027]] as $r) {
    echo "  Sociedad {$r['s']}:" . PHP_EOL;
    echo "    cuenta superviviente {$r['keep']}: socios=" . c("SELECT COUNT(*) c FROM socios_comerciales WHERE id_comercial=?", [$r['keep']]) . PHP_EOL;
    echo "    cuenta jubilada {$r['old']}: socios=" . c("SELECT COUNT(*) c FROM socios_comerciales WHERE id_comercial=?", [$r['old']])
        . "  responsable=" . c("SELECT COUNT(*) c FROM comercial WHERE id=? AND responsable=1", [$r['old']]) . PHP_EOL;
    echo "    responsables en la sociedad: " . c("SELECT COUNT(*) c FROM comercial WHERE id_sociedad=? AND responsable=1", [$r['s']]) . " (esperado 1)" . PHP_EOL;
}
echo "  email comercial 22: " . DB::table('comercial')->where('id',22)->value('email') . PHP_EOL;
echo "  socios_comerciales duplicados exactos restantes: " . c("SELECT COUNT(*) c FROM (SELECT id_socio,id_comercial FROM socios_comerciales GROUP BY id_socio,id_comercial HAVING COUNT(*)>1) z") . PHP_EOL;

echo PHP_EOL . "Backups: _bak_sc_fusion, _bak_comercial_fusion. FIN." . PHP_EOL;
