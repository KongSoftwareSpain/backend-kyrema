<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

print_r(\Illuminate\Support\Facades\DB::table('campos')->where('tipo_producto_id', 10253)->pluck('nombre')->toArray());
