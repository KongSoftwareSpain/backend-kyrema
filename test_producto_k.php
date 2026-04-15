<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

print_r((array)\Illuminate\Support\Facades\DB::table('producto_k')->where('id', 38811)->first());
