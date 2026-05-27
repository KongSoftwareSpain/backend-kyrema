<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$campos = \Illuminate\Support\Facades\DB::table('campos')->where('tipo_producto_id', 10253)->pluck('nombre_codigo')->toArray();
$columns = \Illuminate\Support\Facades\Schema::getColumnListing('anexos_ka');

echo "Missing columns in ANEXOS_KA physical table:\n";
foreach ($campos as $c) {
    if (!in_array($c, $columns)) {
        echo "- $c\n";
    }
}
