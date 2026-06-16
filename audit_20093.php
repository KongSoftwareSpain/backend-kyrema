<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

echo "== 1. Los 486 socios colgados de comercial 20093 ==" . PHP_EOL;
$socios = DB::table('socios_comerciales as sc')
    ->join('socios as s', 'sc.id_socio', '=', 's.id')
    ->where('sc.id_comercial', 20093)
    ->select('s.id','s.categoria_id')
    ->get();
echo "  total socios validos: " . $socios->count() . PHP_EOL;
echo "  por categoria: ";
foreach ($socios->groupBy('categoria_id') as $cat => $g) echo "cat $cat=".count($g)."  ";
echo PHP_EOL;

echo PHP_EOL . "== 2. Comerciales con id cercano a 20093 (pista de quien era) ==" . PHP_EOL;
$cerca = DB::table('comercial')->whereBetween('id', [20085, 20100])->select('id','nombre','usuario','id_sociedad','responsable')->orderBy('id')->get();
foreach ($cerca as $c) echo sprintf("  id=%s soc=%s resp=%s  %s (%s)" . PHP_EOL, $c->id, $c->id_sociedad, $c->responsable, $c->nombre, $c->usuario);

echo PHP_EOL . "== 3. Esos socios, en que sociedad estan sus productos? (via PRODUCTO_K.socio_id) ==" . PHP_EOL;
$ids = $socios->pluck('id')->all();
$prodSoc = DB::table('PRODUCTO_K as p')
    ->join('sociedad as s', 'p.sociedad_id', '=', 's.id')
    ->whereIn('p.socio_id', $ids)
    ->select('p.sociedad_id', 's.nombre', DB::raw('COUNT(*) as n'))
    ->groupBy('p.sociedad_id','s.nombre')->orderBy(DB::raw('COUNT(*)'),'desc')->get();
if ($prodSoc->count()==0) echo "  (esos socios no tienen productos en PRODUCTO_K)" . PHP_EOL;
foreach ($prodSoc as $r) echo sprintf("  sociedad %s (%s) -> %d productos" . PHP_EOL, $r->sociedad_id, $r->nombre, $r->n);

echo PHP_EOL . "== 4. Esos socios tambien tienen OTRO comercial valido? (multi-comercial) ==" . PHP_EOL;
$conOtro = DB::table('socios_comerciales as sc')
    ->join('comercial as c', 'sc.id_comercial', '=', 'c.id')
    ->whereIn('sc.id_socio', $ids)
    ->where('sc.id_comercial', '<>', 20093)
    ->select('sc.id_socio','c.id as com_id','c.nombre')
    ->get();
echo "  socios (de los 486) que YA tienen otro comercial valido: " . $conOtro->pluck('id_socio')->unique()->count() . PHP_EOL;
foreach ($conOtro->groupBy('com_id') as $cid => $g) {
    $nom = $g->first()->nombre;
    echo sprintf("     -> comercial %s (%s): %d socios" . PHP_EOL, $cid, $nom, $g->pluck('id_socio')->unique()->count());
}

echo PHP_EOL . "== 5. created_at de esas 486 filas (cuando se crearon) ==" . PHP_EOL;
$fechas = DB::table('socios_comerciales')->where('id_comercial',20093)
    ->select(DB::raw('MIN(created_at) as min'), DB::raw('MAX(created_at) as max'))->first();
echo "  desde {$fechas->min} hasta {$fechas->max}" . PHP_EOL;
