<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Fields for PRODUCTO_K (202):\n";
$fields = \Illuminate\Support\Facades\DB::table('tipos_de_campos_productos')
    ->where('tipo_producto_id', 202)
    ->get();

foreach ($fields as $f) {
    echo "ID: {$f->id} | Name: {$f->nombre} | Code: {$f->nombre_codigo} | Visible: {$f->visible}\n";
}
