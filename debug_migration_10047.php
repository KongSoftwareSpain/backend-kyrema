<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$poliza = '042026KVIP000007';
echo "Buscando póliza $poliza en MySQL...\n";

$oldSeguro = \Illuminate\Support\Facades\DB::connection('mysql')
    ->table('seguros_combinados')
    ->where('poliza_seguro', $poliza)
    ->first();

if (!$oldSeguro) {
    echo "No se encontró la póliza en MySQL.\n";
} else {
    echo "Póliza encontrada en MySQL. ID: " . $oldSeguro->id_seguro . "\n";
    
    $acompaniantes = \Illuminate\Support\Facades\DB::connection('mysql')
        ->table('seguro_acompaniantes')
        ->where('id_seguro_combinado', $oldSeguro->id_seguro)
        ->where('borrado', 0)
        ->count();
    
    $perros = \Illuminate\Support\Facades\DB::connection('mysql')
        ->table('seguro_perros')
        ->where('id_seguro', $oldSeguro->id_seguro)
        ->where('id_tipo_seguro_perros', 2)
        ->where('borrado', 0)
        ->count();
        
    echo "Acompañantes en MySQL: $acompaniantes\n";
    echo "Perros en MySQL: $perros\n";
}

$newProducto = \Illuminate\Support\Facades\DB::connection('sqlsrv')
    ->table('producto_k')
    ->where('codigo_producto', $poliza)
    ->first();

if (!$newProducto) {
    echo "No se encontró el producto en SQL Server.\n";
} else {
    echo "Producto encontrado en SQL Server. ID: " . $newProducto->id . "\n";
}
