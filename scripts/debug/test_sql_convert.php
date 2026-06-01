<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tipo = DB::table('tipo_producto')->where('nombre', 'like', '%caza%')->first();
$tableName = $tipo->letras_identificacion;

$styles = [
    'default' => "TRY_CONVERT(datetime2, [fecha_de_emisión])",
    '126' => "TRY_CONVERT(datetime2, [fecha_de_emisión], 126)",
    '120' => "TRY_CONVERT(datetime2, [fecha_de_emisión], 120)",
    '121' => "TRY_CONVERT(datetime2, [fecha_de_emisión], 121)"
];

foreach ($styles as $name => $expr) {
    $val = DB::table($tableName)
        ->selectRaw("$expr as val")
        ->first();
    echo "$name: " . ($val->val ?? 'NULL') . "\n";
}
