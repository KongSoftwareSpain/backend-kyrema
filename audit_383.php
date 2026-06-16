<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

// Mapa jerárquico de sociedades
$socs = DB::table('sociedad')->select('id','nombre','sociedad_padre_id')->get()->keyBy('id');
function ancestros($id, $socs) {
    $r = []; $cur = $socs[$id]->sociedad_padre_id ?? null;
    $guard = 0;
    while ($cur !== null && isset($socs[$cur]) && $guard++ < 50) { $r[] = $cur; $cur = $socs[$cur]->sociedad_padre_id; }
    return $r;
}

// Productos K con comercial de otra sociedad
$rows = DB::table('PRODUCTO_K as p')
    ->join('comercial as c', 'p.comercial_id', '=', 'c.id')
    ->whereNotNull('p.sociedad_id')
    ->whereColumn('c.id_sociedad', '<>', 'p.sociedad_id')
    ->select('p.id', 'p.sociedad_id as prod_soc', 'p.comercial_id', 'c.nombre as com_nombre', 'c.id_sociedad as com_soc', 'c.responsable')
    ->get();

echo "TOTAL productos K con comercial de otra sociedad: " . $rows->count() . PHP_EOL . PHP_EOL;

// Clasificación
$cat = ['admin' => 0, 'pagina_web' => 0, 'jerarquia' => 0, 'error' => 0];
$porComercial = [];
foreach ($rows as $r) {
    $comSoc = $r->com_soc; $prodSoc = $r->prod_soc;
    $esAdmin = ($r->comercial_id == 1 || $comSoc == 1);
    $esWeb = stripos($r->com_nombre, 'web') !== false || stripos($r->com_nombre, 'pagina') !== false;
    // jerarquía: prodSoc es ancestro o descendiente de comSoc
    $prodEsAncestro = in_array($prodSoc, ancestros($comSoc, $socs));
    $comEsAncestro = in_array($comSoc, ancestros($prodSoc, $socs));
    $esJerarquia = $prodEsAncestro || $comEsAncestro;

    if ($esAdmin) $tipo = 'admin';
    elseif ($esWeb) $tipo = 'pagina_web';
    elseif ($esJerarquia) $tipo = 'jerarquia';
    else $tipo = 'error';
    $cat[$tipo]++;

    $k = $r->comercial_id;
    if (!isset($porComercial[$k])) $porComercial[$k] = ['nombre'=>$r->com_nombre, 'com_soc'=>$comSoc, 'resp'=>$r->responsable, 'n'=>0, 'tipo'=>$tipo, 'prodSocs'=>[]];
    $porComercial[$k]['n']++;
    $porComercial[$k]['prodSocs'][$prodSoc] = ($porComercial[$k]['prodSocs'][$prodSoc] ?? 0) + 1;
}

echo "=== CLASIFICACIÓN ===" . PHP_EOL;
echo "  admin (comercial admin / sociedad 1):           {$cat['admin']}" . PHP_EOL;
echo "  pagina_web (cuentas de contratación web):       {$cat['pagina_web']}" . PHP_EOL;
echo "  jerarquia (sociedad padre/hija, legítimo):      {$cat['jerarquia']}" . PHP_EOL;
echo "  ERROR (sin relación aparente, REVISAR):         {$cat['error']}" . PHP_EOL;

echo PHP_EOL . "=== DESGLOSE POR COMERCIAL ===" . PHP_EOL;
uasort($porComercial, fn($a,$b)=>$b['n']<=>$a['n']);
foreach ($porComercial as $cid => $d) {
    $socName = $socs[$d['com_soc']]->nombre ?? '?';
    $destinos = [];
    foreach ($d['prodSocs'] as $ps => $n) { $destinos[] = ($socs[$ps]->nombre ?? $ps) . "($n)"; }
    echo sprintf("[%s] com %s \"%s\" (soc %s=%s, resp=%s) -> %d prods en: %s" . PHP_EOL,
        strtoupper($d['tipo']), $cid, $d['nombre'], $d['com_soc'], $socName, $d['resp'], $d['n'], implode(', ', $destinos));
}

echo PHP_EOL . "=== SOLO LOS 'ERROR' (detalle para revisar) ===" . PHP_EOL;
$errs = array_filter($porComercial, fn($d)=>$d['tipo']==='error');
if (!$errs) echo "  (ninguno sin explicación jerárquica/admin/web)" . PHP_EOL;
foreach ($errs as $cid => $d) {
    $socName = $socs[$d['com_soc']]->nombre ?? '?';
    foreach ($d['prodSocs'] as $ps => $n) {
        echo sprintf("  com %s \"%s\" (de %s) tiene %d productos en sociedad %s (%s)" . PHP_EOL,
            $cid, $d['nombre'], $socName, $n, $ps, $socs[$ps]->nombre ?? '?');
    }
}
