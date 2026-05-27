<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "En KYREMA SQL Server:\n";
echo 'Productos_K: ' . \Illuminate\Support\Facades\DB::connection('sqlsrv')->table('producto_k')->count() . "\n";
echo 'Seguros Combinados en local MySQL: ' . \Illuminate\Support\Facades\DB::connection('mysql')->table('seguros_combinados')->count() . "\n";
