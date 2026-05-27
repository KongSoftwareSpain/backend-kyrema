<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Revisando TODOS los anexos en SQL Server...\n";

$tables = ['anexos_ka', 'anexos_ap', 'anexos_aport', 'anexos_aprk', 'anexos_peas', 'anexos_tado1'];

foreach ($tables as $table) {
    if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
        $count = \Illuminate\Support\Facades\DB::connection('sqlsrv')->table($table)->count();
        echo "Tabla $table: $count registros\n";
        if ($count > 0) {
            $first = \Illuminate\Support\Facades\DB::connection('sqlsrv')->table($table)->first();
            echo "  Ejemplo producto_id: " . $first->producto_id . "\n";
        }
    } else {
        echo "Tabla $table NO EXISTE.\n";
    }
}
