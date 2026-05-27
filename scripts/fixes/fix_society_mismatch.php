<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$existing_tables = ['producto_k', 'producto_rehal'];

foreach ($existing_tables as $table) {
    echo "Processing table: {$table}...\n";
    
    // Optimized update using SQL join
    $updated = DB::statement("
        UPDATE t 
        SET t.sociedad_id = c.id_sociedad 
        FROM {$table} t 
        JOIN comercial c ON t.comercial_id = c.id 
        WHERE t.sociedad_id <> c.id_sociedad
    ");
    
    echo "Update executed for {$table}.\n";
}

echo "\nDone.\n";
