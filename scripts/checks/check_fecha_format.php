<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "SQL Server Fecha Inicio (10047):\n";
$new = \Illuminate\Support\Facades\DB::connection('sqlsrv')
    ->table('producto_k')
    ->where('id', 10047)
    ->value('fecha_de_inicio');
echo "[$new]\n";

echo "\nMySQL Fecha Inicio (RANDOM):\n";
$old = \Illuminate\Support\Facades\DB::connection('mysql')
    ->table('seguros_combinados')
    ->where('borrado', 0)
    ->take(3)
    ->pluck('fecha_inicio')->toArray();
print_r($old);
