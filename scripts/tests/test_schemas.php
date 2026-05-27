<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Old seguro_perros:\n";
print_r(\Illuminate\Support\Facades\DB::connection('mysql')->select("DESCRIBE seguro_perros"));

echo "\nOld seguro_acompaniantes:\n";
print_r(\Illuminate\Support\Facades\DB::connection('mysql')->select("DESCRIBE seguro_acompaniantes"));

echo "\nNew ANEXOS_Ap (Perros):\n";
print_r(\Illuminate\Support\Facades\Schema::getColumnListing('anexos_ap'));

echo "\nNew ANEXOS_KA (Acompañantes):\n";
print_r(\Illuminate\Support\Facades\Schema::getColumnListing('anexos_ka'));
