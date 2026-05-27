<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$tables = ['anexos_ap', 'anexos_aport', 'anexos_aprk', 'anexos_ka', 'anexos_peas', 'anexos_tado1'];
foreach ($tables as $t) {
    echo $t . ': ' . \Illuminate\Support\Facades\DB::table($t)->where('producto_id', 38811)->count() . "\n";
}
