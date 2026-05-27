<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

print_r(\Illuminate\Support\Facades\DB::table('tipo_producto')->where('tipo_producto_asociado', 204)->pluck('letras_identificacion')->toArray());
