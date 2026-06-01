<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tipo = DB::table('tipo_producto')->where('nombre', 'like', '%caza%')->first();
if (!$tipo) {
    echo "No caza product found\n";
    exit;
}

echo "Tipo producto: {$tipo->nombre} (ID: {$tipo->id}, Tabla: {$tipo->letras_identificacion})\n";

$firstRow = DB::table($tipo->letras_identificacion)->first();
if (!$firstRow) {
    echo "No rows found in table {$tipo->letras_identificacion}\n";
    exit;
}

print_r($firstRow);
