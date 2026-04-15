<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$tables = \Illuminate\Support\Facades\DB::select("SELECT name FROM sys.tables");
$tableNames = array_map(function($t) { return $t->name; }, $tables);

echo count($tableNames) . " tables found:\n";
foreach ($tableNames as $name) {
    if (strpos(strtolower($name), 'anex') !== false) {
        echo "MATCH: " . $name . "\n";
    }
}
