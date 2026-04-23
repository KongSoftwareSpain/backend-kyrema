<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tipoAnexo = DB::table('tipo_producto')->where('id', 236)->first();
$campos = DB::table('campos')
    ->where('tipo_producto_id', $tipoAnexo->id)
    ->whereNotNull('columna')
    ->whereNotNull('fila')
    ->whereNotIn('grupo', ['datos_anexo', 'datos_precio'])
    ->get();
$camposAnexo = DB::table('campos')
    ->where('tipo_producto_id', $tipoAnexo->id)
    ->whereNotNull('columna')
    ->whereNotNull('fila')
    ->whereIn('grupo', ['datos_anexo', 'datos_precio'])
    ->get();

echo "CAMPOS count: " . $campos->count() . "\n";
foreach($campos as $c) echo "- " . $c->nombre_codigo . " (" . $c->grupo . ")\n";
echo "CAMPOS ANEXO count: " . $camposAnexo->count() . "\n";
foreach($camposAnexo as $c) echo "- " . $c->nombre_codigo . " (" . $c->grupo . ")\n";
