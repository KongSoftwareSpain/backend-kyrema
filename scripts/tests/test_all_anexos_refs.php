<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$rows = \Illuminate\Support\Facades\DB::table('producto_k')->where('numero_anexos', '>', 0)->pluck('id')->toArray();
echo count($rows) . " rows with numero_anexos > 0.\n";

foreach (['anexos_ap', 'anexos_aport', 'anexos_aprk', 'anexos_ka', 'anexos_peas', 'anexos_tado1'] as $t) {
    echo $t . ': ' . \Illuminate\Support\Facades\DB::table($t)->whereIn('producto_id', $rows)->count() . "\n";
}
