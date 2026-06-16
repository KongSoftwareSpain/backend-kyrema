<?php
/**
 * Auditoría de mapeo: Sociedades · Comerciales · Socios · Productos
 * Script temporal de solo-lectura. Ejecutar: php audit_mapping.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const ADMIN_ID = 1;

function h($t) { echo PHP_EOL . "==================== $t ====================" . PHP_EOL; }
function sub($t) { echo PHP_EOL . "--- $t ---" . PHP_EOL; }
function rows($rows, $cols) {
    if (count($rows) === 0) { echo "  (ninguno)" . PHP_EOL; return; }
    foreach ($rows as $r) {
        $parts = [];
        foreach ($cols as $c) { $parts[] = "$c=" . ($r->$c ?? 'NULL'); }
        echo "  " . implode("  ", $parts) . PHP_EOL;
    }
}

// Estructuras de apoyo
$report = [];

// Tablas físicas de producto (padre) y anexos
$tablasProducto = DB::table('tipo_producto')
    ->whereNull('padre_id')
    ->whereNull('tipo_producto_asociado')
    ->whereNotNull('letras_identificacion')
    ->pluck('letras_identificacion', 'id')
    ->toArray();

$tablasAnexo = DB::table('tipo_producto')
    ->whereNotNull('tipo_producto_asociado')
    ->whereNotNull('letras_identificacion')
    ->pluck('letras_identificacion', 'id')
    ->toArray();

function tableExists($name) {
    $r = DB::select("SELECT 1 AS x FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?", [$name]);
    return count($r) > 0;
}
function colExists($table, $col) {
    $r = DB::select("SELECT 1 AS x FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?", [$table, $col]);
    return count($r) > 0;
}

/* ============================================================
   1. INVENTARIO GENERAL
   ============================================================ */
h("1. INVENTARIO GENERAL");
$counts = [
    'sociedad'            => DB::table('sociedad')->count(),
    'comercial'           => DB::table('comercial')->count(),
    'socios'              => DB::table('socios')->count(),
    'socios_comerciales'  => DB::table('socios_comerciales')->count(),
    'socios_productos'    => DB::table('socios_productos')->count(),
    'tipo_producto'       => DB::table('tipo_producto')->count(),
    'tipo_producto_sociedad' => DB::table('tipo_producto_sociedad')->count(),
    'categorias'          => DB::table('categorias')->count(),
];
foreach ($counts as $k => $v) echo sprintf("  %-24s %d" . PHP_EOL, $k, $v);

/* ============================================================
   2. SOCIEDAD ↔ COMERCIAL
   ============================================================ */
h("2. SOCIEDAD <-> COMERCIAL");

sub("2.1 Comerciales con id_sociedad NULL");
$r = DB::table('comercial')->whereNull('id_sociedad')->select('id','nombre','usuario','email','responsable')->get();
rows($r, ['id','nombre','usuario','responsable']);
$report['comercial_sin_sociedad'] = count($r);

sub("2.2 Comerciales apuntando a una sociedad inexistente");
$r = DB::table('comercial as c')
    ->leftJoin('sociedad as s', 'c.id_sociedad', '=', 's.id')
    ->whereNotNull('c.id_sociedad')
    ->whereNull('s.id')
    ->select('c.id','c.nombre','c.id_sociedad')->get();
rows($r, ['id','nombre','id_sociedad']);
$report['comercial_sociedad_huerfana'] = count($r);

sub("2.3 Sociedades sin ningun comercial");
$r = DB::table('sociedad as s')
    ->leftJoin('comercial as c', 'c.id_sociedad', '=', 's.id')
    ->whereNull('c.id')
    ->select('s.id','s.nombre','s.tipo_sociedad','s.sociedad_padre_id')->get();
rows($r, ['id','nombre','tipo_sociedad','sociedad_padre_id']);
$report['sociedad_sin_comercial'] = count($r);

sub("2.4 Sociedades sin comercial responsable (responsable=1)");
$conResp = DB::table('comercial')->where('responsable', 1)->whereNotNull('id_sociedad')->pluck('id_sociedad')->unique()->toArray();
$r = DB::table('sociedad')->whereNotIn('id', $conResp ?: [0])->select('id','nombre','tipo_sociedad','sociedad_padre_id')->get();
rows($r, ['id','nombre','tipo_sociedad','sociedad_padre_id']);
$report['sociedad_sin_responsable'] = count($r);

sub("2.5 Sociedades con MAS de un comercial responsable");
$r = DB::table('comercial')->where('responsable', 1)->whereNotNull('id_sociedad')
    ->select('id_sociedad', DB::raw('COUNT(*) as n'))
    ->groupBy('id_sociedad')->having(DB::raw('COUNT(*)'), '>', 1)->get();
rows($r, ['id_sociedad','n']);
$report['sociedad_multi_responsable'] = count($r);

sub("2.6 Jerarquia de sociedades: padre inexistente");
$r = DB::table('sociedad as s')
    ->leftJoin('sociedad as p', 's.sociedad_padre_id', '=', 'p.id')
    ->whereNotNull('s.sociedad_padre_id')
    ->whereNull('p.id')
    ->select('s.id','s.nombre','s.sociedad_padre_id')->get();
rows($r, ['id','nombre','sociedad_padre_id']);
$report['sociedad_padre_huerfano'] = count($r);

/* ============================================================
   3. SOCIO ↔ COMERCIAL
   ============================================================ */
h("3. SOCIO <-> COMERCIAL");

sub("3.1 Socios SIN comercial asignado (sin fila en socios_comerciales)");
$r = DB::table('socios as s')
    ->leftJoin('socios_comerciales as sc', 'sc.id_socio', '=', 's.id')
    ->whereNull('sc.id')
    ->select('s.id','s.dni','s.nombre_socio','s.apellido_1','s.email')->get();
echo "  TOTAL: " . count($r) . PHP_EOL;
rows($r->take(30), ['id','dni','nombre_socio','apellido_1']);
if (count($r) > 30) echo "  ... (" . (count($r)-30) . " mas)" . PHP_EOL;
$report['socio_sin_comercial'] = count($r);

sub("3.2 socios_comerciales -> socio inexistente");
$r = DB::table('socios_comerciales as sc')
    ->leftJoin('socios as s', 'sc.id_socio', '=', 's.id')
    ->whereNull('s.id')
    ->select('sc.id','sc.id_socio','sc.id_comercial')->get();
rows($r, ['id','id_socio','id_comercial']);
$report['sc_socio_huerfano'] = count($r);

sub("3.3 socios_comerciales -> comercial inexistente");
$r = DB::table('socios_comerciales as sc')
    ->leftJoin('comercial as c', 'sc.id_comercial', '=', 'c.id')
    ->whereNull('c.id')
    ->select('sc.id','sc.id_socio','sc.id_comercial')->get();
rows($r, ['id','id_socio','id_comercial']);
$report['sc_comercial_huerfano'] = count($r);

sub("3.4 Socios con MULTIPLES comerciales (modelo asume hasOne)");
$r = DB::table('socios_comerciales')
    ->select('id_socio', DB::raw('COUNT(*) as n'))
    ->groupBy('id_socio')->having(DB::raw('COUNT(*)'), '>', 1)->get();
echo "  TOTAL socios con >1 comercial: " . count($r) . PHP_EOL;
rows($r->take(30), ['id_socio','n']);
$report['socio_multi_comercial'] = count($r);

sub("3.5 Filas duplicadas exactas (mismo socio + mismo comercial)");
$r = DB::table('socios_comerciales')
    ->select('id_socio','id_comercial', DB::raw('COUNT(*) as n'))
    ->groupBy('id_socio','id_comercial')->having(DB::raw('COUNT(*)'), '>', 1)->get();
rows($r->take(30), ['id_socio','id_comercial','n']);
$report['sc_duplicados'] = count($r);

/* ============================================================
   4. SOCIO ↔ PRODUCTO
   ============================================================ */
h("4. SOCIO <-> PRODUCTO (socios_productos)");

sub("4.1 socios_productos -> socio inexistente");
$r = DB::table('socios_productos as sp')
    ->leftJoin('socios as s', 'sp.id_socio', '=', 's.id')
    ->whereNull('s.id')
    ->select('sp.id','sp.id_socio','sp.id_producto','sp.letras_identificacion')->get();
echo "  TOTAL: " . count($r) . PHP_EOL;
rows($r->take(30), ['id','id_socio','id_producto','letras_identificacion']);
$report['sp_socio_huerfano'] = count($r);

sub("4.2 socios_productos con letras_identificacion NULL o vacia");
$r = DB::table('socios_productos')->whereNull('letras_identificacion')->orWhere('letras_identificacion','')->select('id','id_socio','id_producto')->get();
rows($r->take(30), ['id','id_socio','id_producto']);
$report['sp_sin_letras'] = count($r);

sub("4.3 socios_productos apuntando a tabla/letras inexistente");
$letrasValidas = DB::table('tipo_producto')->whereNotNull('letras_identificacion')->pluck('letras_identificacion')->map(fn($x)=>strtolower($x))->toArray();
$distintas = DB::table('socios_productos')->whereNotNull('letras_identificacion')->distinct()->pluck('letras_identificacion');
$malLetras = [];
foreach ($distintas as $l) {
    if (!in_array(strtolower($l), $letrasValidas)) $malLetras[] = $l;
}
echo "  letras_identificacion no reconocidas: " . (count($malLetras) ? implode(', ', $malLetras) : '(ninguna)') . PHP_EOL;
$report['sp_letras_desconocidas'] = count($malLetras);

sub("4.4 socios_productos -> producto inexistente en su tabla (huerfanos)");
$totalHuerfanos = 0;
$distintasOk = DB::table('socios_productos')->whereNotNull('letras_identificacion')->distinct()->pluck('letras_identificacion');
foreach ($distintasOk as $l) {
    if (!tableExists($l)) continue;
    $huerf = DB::table('socios_productos as sp')
        ->leftJoin($l . ' as p', 'sp.id_producto', '=', 'p.id')
        ->where('sp.letras_identificacion', $l)
        ->whereNull('p.id')
        ->count();
    if ($huerf > 0) { echo sprintf("  %-14s -> %d socios_productos huerfanos" . PHP_EOL, $l, $huerf); $totalHuerfanos += $huerf; }
}
if ($totalHuerfanos === 0) echo "  (ninguno)" . PHP_EOL;
$report['sp_producto_huerfano'] = $totalHuerfanos;

/* ============================================================
   5. PRODUCTOS: integridad de asociaciones por tabla
   ============================================================ */
h("5. PRODUCTOS - integridad por tabla");
echo "Tablas de producto (padre) auditadas: " . implode(', ', $tablasProducto) . PHP_EOL;

$resumenProd = [];
foreach ($tablasProducto as $tpId => $tabla) {
    if (!tableExists($tabla)) continue;
    $total = DB::table($tabla)->count();

    $sinSocio = colExists($tabla,'socio_id') ? DB::table($tabla)->whereNull('socio_id')->count() : null;
    $socioHuerf = colExists($tabla,'socio_id') ? DB::table($tabla . ' as p')->leftJoin('socios as s','p.socio_id','=','s.id')->whereNotNull('p.socio_id')->whereNull('s.id')->count() : null;

    $sinComercial = colExists($tabla,'comercial_id') ? DB::table($tabla)->whereNull('comercial_id')->count() : null;
    $comercialHuerf = colExists($tabla,'comercial_id') ? DB::table($tabla . ' as p')->leftJoin('comercial as c','p.comercial_id','=','c.id')->whereNotNull('p.comercial_id')->whereNull('c.id')->count() : null;

    $sinCreador = colExists($tabla,'comercial_creador_id') ? DB::table($tabla)->whereNull('comercial_creador_id')->count() : null;
    $creadorHuerf = colExists($tabla,'comercial_creador_id') ? DB::table($tabla . ' as p')->leftJoin('comercial as c','p.comercial_creador_id','=','c.id')->whereNotNull('p.comercial_creador_id')->whereNull('c.id')->count() : null;

    $sinSociedad = colExists($tabla,'sociedad_id') ? DB::table($tabla)->whereNull('sociedad_id')->count() : null;
    $sociedadHuerf = colExists($tabla,'sociedad_id') ? DB::table($tabla . ' as p')->leftJoin('sociedad as s','p.sociedad_id','=','s.id')->whereNotNull('p.sociedad_id')->whereNull('s.id')->count() : null;

    // Productos sin fila en socios_productos (no enlazados al socio por la pivote)
    $sinPivote = DB::table($tabla . ' as p')
        ->leftJoin('socios_productos as sp', function($j) use ($tabla) {
            $j->on('sp.id_producto','=','p.id')->where('sp.letras_identificacion','=', $tabla);
        })
        ->whereNull('sp.id')->count();

    // Coherencia: comercial del producto pertenece a la sociedad del producto
    $incoherSoc = null;
    if (colExists($tabla,'comercial_id') && colExists($tabla,'sociedad_id')) {
        $incoherSoc = DB::table($tabla . ' as p')
            ->join('comercial as c','p.comercial_id','=','c.id')
            ->whereNotNull('p.sociedad_id')
            ->whereColumn('c.id_sociedad','<>','p.sociedad_id')
            ->count();
    }

    $resumenProd[$tabla] = compact('total','sinSocio','socioHuerf','sinComercial','comercialHuerf','sinCreador','creadorHuerf','sinSociedad','sociedadHuerf','sinPivote','incoherSoc');

    echo PHP_EOL . "### $tabla (total=$total)" . PHP_EOL;
    echo sprintf("   socio:    sin=%s  huerfano=%s" . PHP_EOL, $sinSocio, $socioHuerf);
    echo sprintf("   comercial:sin=%s  huerfano=%s" . PHP_EOL, $sinComercial, $comercialHuerf);
    echo sprintf("   creador:  sin=%s  huerfano=%s" . PHP_EOL, $sinCreador, $creadorHuerf);
    echo sprintf("   sociedad: sin=%s  huerfano=%s" . PHP_EOL, $sinSociedad, $sociedadHuerf);
    echo sprintf("   sin fila en socios_productos: %s" . PHP_EOL, $sinPivote);
    echo sprintf("   comercial NO pertenece a la sociedad del producto: %s" . PHP_EOL, $incoherSoc);
}

/* ============================================================
   6. TIPO_PRODUCTO ↔ SOCIEDAD  y categorias
   ============================================================ */
h("6. TIPO_PRODUCTO <-> SOCIEDAD / CATEGORIA");

sub("6.1 tipo_producto_sociedad -> sociedad inexistente");
$r = DB::table('tipo_producto_sociedad as tps')
    ->leftJoin('sociedad as s','tps.id_sociedad','=','s.id')
    ->whereNull('s.id')->select('tps.id','tps.id_sociedad','tps.id_tipo_producto')->get();
rows($r, ['id','id_sociedad','id_tipo_producto']);
$report['tps_sociedad_huerfana'] = count($r);

sub("6.2 tipo_producto_sociedad -> tipo_producto inexistente");
$r = DB::table('tipo_producto_sociedad as tps')
    ->leftJoin('tipo_producto as tp','tps.id_tipo_producto','=','tp.id')
    ->whereNull('tp.id')->select('tps.id','tps.id_sociedad','tps.id_tipo_producto')->get();
rows($r, ['id','id_sociedad','id_tipo_producto']);
$report['tps_tipoproducto_huerfano'] = count($r);

sub("6.3 tipo_producto_sociedad duplicados (misma sociedad + mismo tipo)");
$r = DB::table('tipo_producto_sociedad')
    ->select('id_sociedad','id_tipo_producto', DB::raw('COUNT(*) as n'))
    ->groupBy('id_sociedad','id_tipo_producto')->having(DB::raw('COUNT(*)'),'>',1)->get();
rows($r->take(40), ['id_sociedad','id_tipo_producto','n']);
$report['tps_duplicados'] = count($r);

sub("6.4 Tipos de producto PADRE activos NO asignados a ninguna sociedad");
$asignados = DB::table('tipo_producto_sociedad')->distinct()->pluck('id_tipo_producto')->toArray();
$r = DB::table('tipo_producto')->where('estado',1)->whereNull('padre_id')->whereNull('tipo_producto_asociado')
    ->whereNotIn('id', $asignados ?: [0])->select('id','nombre','letras_identificacion')->get();
rows($r, ['id','nombre','letras_identificacion']);
$report['tipoproducto_sin_sociedad'] = count($r);

sub("6.5 tipo_producto con categoria inexistente");
$r = DB::table('tipo_producto as tp')->leftJoin('categorias as c','tp.categoria_id','=','c.id')
    ->whereNotNull('tp.categoria_id')->whereNull('c.id')->select('tp.id','tp.nombre','tp.categoria_id')->get();
rows($r, ['id','nombre','categoria_id']);
$report['tipoproducto_categoria_huerfana'] = count($r);

sub("6.6 Subproductos/anexos con padre o asociado inexistente");
$r = DB::table('tipo_producto as tp')->leftJoin('tipo_producto as pa','tp.padre_id','=','pa.id')
    ->whereNotNull('tp.padre_id')->whereNull('pa.id')->select('tp.id','tp.nombre','tp.padre_id')->get();
echo "  Padre inexistente:" . PHP_EOL; rows($r, ['id','nombre','padre_id']);
$r2 = DB::table('tipo_producto as tp')->leftJoin('tipo_producto as a','tp.tipo_producto_asociado','=','a.id')
    ->whereNotNull('tp.tipo_producto_asociado')->whereNull('a.id')->select('tp.id','tp.nombre','tp.tipo_producto_asociado')->get();
echo "  Asociado inexistente:" . PHP_EOL; rows($r2, ['id','nombre','tipo_producto_asociado']);
$report['tipoproducto_padre_huerfano'] = count($r);
$report['tipoproducto_asociado_huerfano'] = count($r2);

sub("6.7 categorias con comercial_responsable inexistente");
$r = DB::table('categorias as c')->leftJoin('comercial as co','c.comercial_responsable_id','=','co.id')
    ->whereNotNull('c.comercial_responsable_id')->whereNull('co.id')->select('c.id','c.nombre','c.comercial_responsable_id')->get();
rows($r, ['id','nombre','comercial_responsable_id']);
$report['categoria_comercial_huerfano'] = count($r);

/* ============================================================
   7. RESUMEN FINAL
   ============================================================ */
h("7. RESUMEN DE ANOMALIAS");
foreach ($report as $k => $v) {
    $flag = $v > 0 ? '  <-- REVISAR' : '';
    echo sprintf("  %-34s %5d%s" . PHP_EOL, $k, $v, $flag);
}

echo PHP_EOL . "Fin de la auditoria." . PHP_EOL;

// Volcado JSON para el README
file_put_contents(__DIR__ . '/audit_result.json', json_encode([
    'counts' => $counts,
    'anomalias' => $report,
    'productos' => $resumenProd,
], JSON_PRETTY_PRINT));
