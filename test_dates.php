<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$oldRecord = DB::table('producto_c')->find(33);
$newData = (array)$oldRecord;
unset($newData['id']);

$dateFields = ['fecha_de_emisión', 'fecha_de_nacimiento'];

foreach ($dateFields as $col) {
    if (!empty($newData[$col])) {
        // Formatear a formato SQL Server puro ISO-8601 (Y-m-d\TH:i:s.000)
        $newData[$col] = \Carbon\Carbon::parse($newData[$col])->format('Y-m-d\TH:i:s.000');
    }
}

$newData['fecha_de_inicio'] = \Carbon\Carbon::now()->format('Y-m-d\TH:i:s.000');
$newData['fecha_de_fin'] = \Carbon\Carbon::now()->addYear()->format('Y-m-d\TH:i:s.000');
$newData['created_at'] = \Carbon\Carbon::now()->format('Y-m-d\TH:i:s.000');
$newData['updated_at'] = \Carbon\Carbon::now()->format('Y-m-d\TH:i:s.000');
$newData['codigo_producto'] = 'TEST_003';

try {
    DB::table('producto_c')->insert($newData);
    echo "SUCCESS\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
