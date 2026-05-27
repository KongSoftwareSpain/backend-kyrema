<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Product 10047 is subproducto=222, letras_identificacion=PRODUCTO_KVIP
// The backend calls getAnexosPorProducto($id_tipo_producto, 10047)
// From the frontend log: tipo_producto comes from the product's tipo_producto, which is the PARENT tipo_producto id

// Let's see: producto_k(10047).subproducto = 222 (KVIP id)
// The frontend likely passes the PARENT tipo_producto id (PRODUCTO_K = 202), not the subproducto id

// Let's check what tipos_producto with tipo_producto_asociado=202 exist
echo "Tipos anexo con tipo_producto_asociado=202 (PRODUCTO_K padre):\n";
print_r(\Illuminate\Support\Facades\DB::table('tipo_producto')->where('tipo_producto_asociado', 202)->get(['id','nombre','letras_identificacion'])->toArray());

// Also check with tipo_producto_asociado=222 (KVIP subproducto)
echo "\nTipos anexo con tipo_producto_asociado=222 (KVIP):\n";
print_r(\Illuminate\Support\Facades\DB::table('tipo_producto')->where('tipo_producto_asociado', 222)->get(['id','nombre','letras_identificacion'])->toArray());

// Check what the frontend is actually sending - find what tipo_producto.id is for PRODUCTO_K parent
echo "\ntipo_producto where letras_identificacion=PRODUCTO_K:\n";
print_r(\Illuminate\Support\Facades\DB::table('tipo_producto')->where('letras_identificacion', 'PRODUCTO_K')->get(['id','nombre','letras_identificacion','tipo_producto_asociado'])->toArray());
