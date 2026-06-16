<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

// Tablas físicas de producto (padre)
$tablas = DB::table('tipo_producto')->whereNull('padre_id')->whereNull('tipo_producto_asociado')
    ->whereNotNull('letras_identificacion')->pluck('letras_identificacion')->toArray();
$tablas = array_filter($tablas, fn($t) => count(DB::select("SELECT 1 x FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME=?", [$t])) > 0);

function colExists($t, $col) { return count(DB::select("SELECT 1 x FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=? AND COLUMN_NAME=?", [$t, $col])) > 0; }

$pares = [
    '10027' => [['id'=>23,'desc'=>'Joaquin Canalejo (jcanalejo@canamaseguros.es)'], ['id'=>20026,'desc'=>'jcanalejo (usuario suelto)']],
    '10028' => [['id'=>22,'desc'=>'Manuel (info@tecorportas.com)'], ['id'=>20027,'desc'=>'info (usuario suelto)']],
];

foreach ($pares as $soc => $cuentas) {
    echo "############### SOCIEDAD $soc ###############" . PHP_EOL;
    foreach ($cuentas as $cta) {
        $id = $cta['id'];
        echo PHP_EOL . ">>> comercial $id — {$cta['desc']}" . PHP_EOL;

        // ¿responsable? ¿qué id_sociedad?
        $com = DB::table('comercial')->where('id', $id)->first();
        echo "    id_sociedad={$com->id_sociedad}  responsable={$com->responsable}  email={$com->email}  fecha_alta={$com->fecha_alta}" . PHP_EOL;

        // socios asignados
        $nSoc = DB::table('socios_comerciales')->where('id_comercial', $id)->count();
        echo "    socios asignados (socios_comerciales): $nSoc" . PHP_EOL;

        // productos creados / asignados en cada tabla
        $totCreador = 0; $totComercial = 0; $detalle = [];
        foreach ($tablas as $t) {
            $cCre = colExists($t,'comercial_creador_id') ? DB::table($t)->where('comercial_creador_id',$id)->count() : 0;
            $cCom = colExists($t,'comercial_id') ? DB::table($t)->where('comercial_id',$id)->count() : 0;
            if ($cCre || $cCom) $detalle[] = "$t(creador=$cCre, asignado=$cCom)";
            $totCreador += $cCre; $totComercial += $cCom;
        }
        echo "    productos CREADOS por él:   $totCreador" . PHP_EOL;
        echo "    productos ASIGNADOS a él:   $totComercial" . PHP_EOL;
        echo "    detalle: " . (count($detalle) ? implode('  ', $detalle) : '(ninguno)') . PHP_EOL;

        // última actividad (max created_at de productos que creó, en PRODUCTO_K)
        if (in_array('PRODUCTO_K', $tablas) && colExists('PRODUCTO_K','comercial_creador_id')) {
            $ult = DB::table('PRODUCTO_K')->where('comercial_creador_id',$id)->max('created_at');
            echo "    última creación en PRODUCTO_K: " . ($ult ?: '(ninguna)') . PHP_EOL;
        }
    }
    echo PHP_EOL;
}
