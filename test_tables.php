<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$tables = \Illuminate\Support\Facades\DB::select('SELECT name FROM sys.tables');
foreach ($tables as $t) {
    if (strpos(strtolower($t->name), 'anexo') !== false) {
        echo $t->name . "\n";
    }
}
