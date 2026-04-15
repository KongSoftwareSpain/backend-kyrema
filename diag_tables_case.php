<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

$tables = ['anexos_ka', 'ANEXOS_KA', 'anexos_ap', 'ANEXOS_Ap'];

foreach ($tables as $t) {
    echo "Check table [$t]: " . (Schema::hasTable($t) ? "FOUND" : "NOT FOUND") . "\n";
}

$tipo_producto_id = 202; // PRODUCTO_K
$tiposAnexos = DB::table('tipo_producto')
    ->where('tipo_producto_asociado', $tipo_producto_id)
    ->get();

foreach ($tiposAnexos as $ta) {
    $letras = $ta->letras_identificacion;
    $lower = strtolower($letras);
    echo "Tipo Anexo ID {$ta->id}: Letras [$letras] | Lower [$lower] | HasTable: " . (Schema::hasTable($lower) ? "YES" : "NO") . "\n";
}
