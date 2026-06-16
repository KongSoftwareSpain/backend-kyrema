<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

echo "== A. comercial_ids referenciados en socios_comerciales que NO existen ==" . PHP_EOL;
$miss = DB::table('socios_comerciales as sc')->leftJoin('comercial as c','sc.id_comercial','=','c.id')
    ->whereNull('c.id')->select('sc.id_comercial', DB::raw('COUNT(*) as n'))
    ->groupBy('sc.id_comercial')->orderBy(DB::raw('COUNT(*)'),'desc')->get();
foreach ($miss as $m) echo sprintf("  id_comercial=%s -> %d filas" . PHP_EOL, $m->id_comercial ?? 'NULL', $m->n);

echo PHP_EOL . "== B. socio_huerfano: rango de id_socio inexistentes (top) ==" . PHP_EOL;
$tot = DB::table('socios_comerciales as sc')->leftJoin('socios as s','sc.id_socio','=','s.id')->whereNull('s.id')->count();
$min = DB::table('socios_comerciales as sc')->leftJoin('socios as s','sc.id_socio','=','s.id')->whereNull('s.id')->min('sc.id_socio');
$max = DB::table('socios_comerciales as sc')->leftJoin('socios as s','sc.id_socio','=','s.id')->whereNull('s.id')->max('sc.id_socio');
echo "  total=$tot  id_socio min=$min max=$max" . PHP_EOL;

echo PHP_EOL . "== C. socios_productos por letras (legacy vs real) ==" . PHP_EOL;
$byl = DB::table('socios_productos')->select('letras_identificacion', DB::raw('COUNT(*) as n'))
    ->groupBy('letras_identificacion')->orderBy(DB::raw('COUNT(*)'),'desc')->get();
$letrasValidas = DB::table('tipo_producto')->whereNotNull('letras_identificacion')->pluck('letras_identificacion')->map(fn($x)=>strtolower($x))->toArray();
foreach ($byl as $b) {
    $ok = in_array(strtolower($b->letras_identificacion), $letrasValidas) ? 'OK ' : 'DESCONOCIDA';
    echo sprintf("  %-20s %4d  [%s]" . PHP_EOL, $b->letras_identificacion, $b->n, $ok);
}

echo PHP_EOL . "== D. Cobertura pivote socios_productos vs total productos K ==" . PHP_EOL;
$kTotal = DB::table('PRODUCTO_K')->count();
$kEnPivote = DB::table('socios_productos')->whereRaw('LOWER(letras_identificacion)=?',['producto_k'])->count();
echo "  PRODUCTO_K total=$kTotal  en socios_productos=$kEnPivote" . PHP_EOL;

echo PHP_EOL . "== E. comercial.responsable distribucion ==" . PHP_EOL;
$resp = DB::table('comercial')->select('responsable', DB::raw('COUNT(*) as n'))->groupBy('responsable')->get();
foreach ($resp as $r) echo sprintf("  responsable=%s -> %d" . PHP_EOL, $r->responsable ?? 'NULL', $r->n);

echo PHP_EOL . "== F. Sociedades multi-responsable: detalle ==" . PHP_EOL;
foreach ([1,10027,10028,10094] as $sid) {
    $cs = DB::table('comercial')->where('id_sociedad',$sid)->where('responsable',1)->select('id','nombre','usuario')->get();
    echo "  sociedad $sid:" . PHP_EOL;
    foreach ($cs as $c) echo sprintf("     comercial id=%s  %s (%s)" . PHP_EOL, $c->id, $c->nombre, $c->usuario);
}

echo PHP_EOL . "== G. PRODUCTO_K: socio_id huerfano y sin socio (detalle) ==" . PHP_EOL;
$sinSocio = DB::table('PRODUCTO_K')->whereNull('socio_id')->select('id','codigo_producto','sociedad_id')->limit(15)->get();
echo "  Sin socio_id (muestra):" . PHP_EOL;
foreach ($sinSocio as $p) echo sprintf("     id=%s cod=%s soc=%s" . PHP_EOL, $p->id, $p->codigo_producto, $p->sociedad_id);
$huerf = DB::table('PRODUCTO_K as p')->leftJoin('socios as s','p.socio_id','=','s.id')->whereNotNull('p.socio_id')->whereNull('s.id')->select('p.id','p.socio_id','p.codigo_producto')->limit(15)->get();
echo "  socio_id huerfano (muestra):" . PHP_EOL;
foreach ($huerf as $p) echo sprintf("     id=%s socio_id=%s cod=%s" . PHP_EOL, $p->id, $p->socio_id, $p->codigo_producto);

echo PHP_EOL . "== H. Comerciales sin sociedad-responsable: cuantas sociedades activas con productos ==" . PHP_EOL;
$socConProd = DB::table('PRODUCTO_K')->distinct()->pluck('sociedad_id')->filter()->count();
echo "  sociedades distintas con productos K=$socConProd de 120 totales" . PHP_EOL;
