<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Old seguro_perros:\n";
print_r(array_map(function($c) { return $c->Field; }, \Illuminate\Support\Facades\DB::connection('mysql')->select('DESCRIBE seguro_perros')));

echo "\nOld seguro_acompaniantes:\n";
print_r(array_map(function($c) { return $c->Field; }, \Illuminate\Support\Facades\DB::connection('mysql')->select('DESCRIBE seguro_acompaniantes')));
