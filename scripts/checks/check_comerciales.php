<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "\n--- Final Data Consistency Check ---\n";
$total_products = DB::table('producto_k')->count();
$total_mismatches = DB::table('producto_k')
    ->join('comercial', 'producto_k.comercial_id', '=', 'comercial.id')
    ->whereRaw('producto_k.sociedad_id <> comercial.id_sociedad')
    ->count();

$empty_soc_products = DB::table('producto_k')
    ->leftJoin('comercial', 'producto_k.sociedad_id', '=', 'comercial.id_sociedad')
    ->where('producto_k.sociedad_id', '>', 1)
    ->whereNull('comercial.id')
    ->count();

echo "Total Products Check: {$total_products}\n";
echo "Products with Mismatched Societies: {$total_mismatches}\n";
echo "Products in Empty Societies: {$empty_soc_products}\n";

if ($empty_soc_products > 0) {
    echo "\nSome products still in empty societies (probably have no comercial_id assigned).\n";
}
