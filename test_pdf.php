<?php

use Illuminate\Http\Request;
use App\Http\Controllers\ExportController;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = new ExportController();
$request = Request::create('/api/descargar-plantilla-anexo/236', 'GET', ['id' => 15374]);
$response = $controller->exportAnexoExcelToPdf(236, $request);

file_put_contents('debug_pdf_data.json', $response->getContent());
echo "Done\n";
