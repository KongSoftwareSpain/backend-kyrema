<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

echo "================ 1. FICHA COMERCIAL 20083 ================" . PHP_EOL;
$c = DB::table('comercial')->where('id', 20083)->first();
foreach (['id','nombre','usuario','email','id_sociedad','responsable','dni','telefono','fecha_alta','pagina_web'] as $f) {
    echo "  $f = " . ($c->$f ?? 'NULL') . PHP_EOL;
}

echo PHP_EOL . "================ 2. SOCIEDADES 10084 (Picos) vs 10094 (Araba) ================" . PHP_EOL;
foreach ([10084, 10094] as $sid) {
    $s = DB::table('sociedad')->where('id', $sid)->first();
    echo "  --- Sociedad $sid ---" . PHP_EOL;
    foreach (['nombre','cif','correo_electronico','dominio','tipo_sociedad','sociedad_padre_id','poblacion'] as $f) {
        echo "     $f = " . ($s->$f ?? 'NULL') . PHP_EOL;
    }
    $resp = DB::table('comercial')->where('id_sociedad', $sid)->select('id','nombre','usuario','email','responsable')->get();
    echo "     comerciales de esta sociedad:" . PHP_EOL;
    foreach ($resp as $r) echo sprintf("        id=%s resp=%s %s (%s) <%s>" . PHP_EOL, $r->id, $r->responsable, $r->nombre, $r->usuario, $r->email);
}

echo PHP_EOL . "================ 3. LOS 383 PRODUCTOS: ¿de quién son los socios? ================" . PHP_EOL;
$socioIds = DB::table('PRODUCTO_K')->where('comercial_id', 20083)->where('sociedad_id', 10094)->whereNotNull('socio_id')->pluck('socio_id')->unique();
echo "  socios distintos en esos 383 productos: " . $socioIds->count() . PHP_EOL;
// ¿a qué comercial pertenecen ahora esos socios?
$porCom = DB::table('socios_comerciales')->whereIn('id_socio', $socioIds)->select('id_comercial', DB::raw('COUNT(DISTINCT id_socio) n'))->groupBy('id_comercial')->orderByRaw('COUNT(DISTINCT id_socio) desc')->get();
echo "  esos socios, ¿a qué comercial están asignados (socios_comerciales)?" . PHP_EOL;
foreach ($porCom as $r) {
    $nom = DB::table('comercial')->where('id', $r->id_comercial)->value('nombre') ?? 'BORRADO';
    $soc = DB::table('comercial')->where('id', $r->id_comercial)->value('id_sociedad');
    echo sprintf("        comercial %s (%s, soc=%s) -> %d socios" . PHP_EOL, $r->id_comercial, $nom, $soc, $r->n);
}

echo PHP_EOL . "================ 4. ¿20083 tiene socios asignados? ================" . PHP_EOL;
echo "  socios_comerciales de 20083: " . DB::table('socios_comerciales')->where('id_comercial', 20083)->count() . PHP_EOL;

echo PHP_EOL . "================ 5. CÓDIGOS DE PRODUCTO (muestra de los 383) ================" . PHP_EOL;
$cods = DB::table('PRODUCTO_K')->where('comercial_id', 20083)->where('sociedad_id', 10094)->select('codigo_producto','subproducto_codigo','sociedad')->limit(8)->get();
foreach ($cods as $r) echo sprintf("     cod=%s  sub=%s  sociedad(txt)=%s" . PHP_EOL, $r->codigo_producto, $r->subproducto_codigo, $r->sociedad);

echo PHP_EOL . "================ 6. ¿20083 creó productos en OTRAS sociedades? ================" . PHP_EOL;
$otras = DB::table('PRODUCTO_K')->where('comercial_id', 20083)->select('sociedad_id', DB::raw('COUNT(*) n'))->groupBy('sociedad_id')->get();
foreach ($otras as $r) {
    $nom = DB::table('sociedad')->where('id', $r->sociedad_id)->value('nombre') ?? '?';
    echo sprintf("     sociedad %s (%s) -> %d productos" . PHP_EOL, $r->sociedad_id, $nom, $r->n);
}
