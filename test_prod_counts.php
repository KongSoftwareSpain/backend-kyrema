<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "En KYREMA SQL Server:\n";
echo 'Acompañantes en anexos_ka: ' . \Illuminate\Support\Facades\DB::connection('sqlsrv')->table('anexos_ka')->count() . "\n";
echo 'Perros en anexos_ap: ' . \Illuminate\Support\Facades\DB::connection('sqlsrv')->table('anexos_ap')->count() . "\n";
