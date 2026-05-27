<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

foreach (['ANEXOS_APORt','ANEXOS_Ap','ANEXOS_KA'] as $t) {
    try {
        $count = \Illuminate\Support\Facades\DB::table(strtolower($t))->where('producto_id', 38811)->count();
        echo $t . ': ' . $count . "\n";
    } catch (Exception $e) {
        echo $t . ': table does not exist or error\n';
    }
}
