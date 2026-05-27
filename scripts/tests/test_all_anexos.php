<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

print_r(\Illuminate\Support\Facades\DB::table('tipo_producto')->whereNotNull('tipo_producto_asociado')->get(['letras_identificacion', 'tipo_producto_asociado'])->toArray());
