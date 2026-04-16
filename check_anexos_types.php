<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Checking numero_anexos values in SQL Server...\n";

$results = \Illuminate\Support\Facades\DB::connection('sqlsrv')
    ->table('producto_k')
    ->select('id', 'numero_anexos')
    ->get();

$types = [];
foreach ($results as $r) {
    $val = $r->numero_anexos;
    $type = gettype($val);
    if (!isset($types[$type])) $types[$type] = 0;
    $types[$type]++;
    
    if ($val !== null && !is_numeric($val)) {
        echo "ID: {$r->id} | Value: [" . var_export($val, true) . "] | Type: $type (NON-NUMERIC!)\n";
    }
}

echo "Statistics:\n";
print_r($types);
