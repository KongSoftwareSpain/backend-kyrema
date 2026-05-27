<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Ejemplos de producto_k en SQL Server:\n";
$products = \Illuminate\Support\Facades\DB::connection('sqlsrv')
    ->table('producto_k')
    ->take(5)
    ->get(['id', 'codigo_producto', 'nombre_socio', 'dni']);

foreach ($products as $p) {
    echo "ID: {$p->id} | Póliza: {$p->codigo_producto} | Socio: {$p->nombre_socio} | DNI: {$p->dni}\n";
    
    // Buscar en MySQL por DNI
    if ($p->dni) {
        $old = \Illuminate\Support\Facades\DB::connection('mysql')
            ->table('seguros_combinados')
            ->join('socios', 'seguros_combinados.id_socio', '=', 'socios.id_socio')
            ->where('socios.dni', $p->dni)
            ->first(['seguros_combinados.id_seguro', 'seguros_combinados.poliza_seguro']);
            
        if ($old) {
            echo "  -> Encontrado en MySQL! ID_Seguro: {$old->id_seguro} | Poliza: {$old->poliza_seguro}\n";
        } else {
            echo "  -> NO encontrado en MySQL por DNI.\n";
        }
    }
}
