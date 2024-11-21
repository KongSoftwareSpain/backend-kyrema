<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DeleteProductData extends Command
{
    protected $signature = 'delete:product-data {productId}';
    protected $description = 'Deletes product data from various tables';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        try{
            $productId = $this->argument('productId');

            // Obtener letrasIdentificacion y plantilla_path antes de eliminar la tabla tipo_producto
            $product = DB::table('tipo_producto')->where('id', $productId)->first();
            $letrasIdentificacion = $product->letras_identificacion ?? null;
            $plantillasPaths = [
                $product->plantilla_path_1 ?? null, $product->plantilla_path_2 ?? null, $product->plantilla_path_3 ?? null, $product->plantilla_path_4 ?? null,
                $product->plantilla_path_5 ?? null, $product->plantilla_path_6 ?? null, $product->plantilla_path_7 ?? null, $product->plantilla_path_8 ?? null,
            ];

            // Obtener los campos con opciones (opciones != null)
            $camposConOpciones = DB::table('campos')->where('tipo_producto_id', $productId)->whereNotNull('opciones')->get();


            if($camposConOpciones != null && count($camposConOpciones) > 0){
                // Recorrer los campos con opciones y elminiar las tablas con el nombre de las opciones
                foreach ($camposConOpciones as $campo) {
                    if (Schema::hasTable($campo->opciones)) {
                        Schema::dropIfExists($campo->opciones);
                    }
                }
            }
            //Comprobar si tiene tipos hijos:
            $tiposHijos = DB::table('tipo_producto')->where('padre_id', $productId)->get();

            if($tiposHijos != null && count($tiposHijos) > 0){
                foreach ($tiposHijos as $tipoHijo) {
                    $this->call('delete:product-data', ['productId' => $tipoHijo->id]);
                }
            }

            // Delete from tipo_producto
            DB::table('tipo_producto')->where('id', $productId)->delete();

            // Delete from tipo_producto_sociedad
            DB::table('tipo_producto_sociedad')->where('id_tipo_producto', $productId)->delete();

            // Delete from tarifas_producto
            DB::table('tarifas_producto')->where('tipo_producto_id', $productId)->delete();

            // Drop the table if it exists
            if ($letrasIdentificacion && Schema::hasTable($letrasIdentificacion)) {
                Schema::dropIfExists($letrasIdentificacion);
            }

            // Delete from campos
            DB::table('campos')->where('tipo_producto_id', $productId)->delete();


            foreach ($plantillasPaths as $plantillaPath) {
                // Eliminar la plantilla si existe
                if ($plantillaPath && Storage::disk('public')->exists($plantillaPath)) {
                    Storage::disk('public')->delete($plantillaPath);
                }
            }

            $anexos = DB::table('tipos_anexos')->where('id_tipo_producto', $productId)->get();

            if($anexos != null && count($anexos) > 0){
                foreach ($anexos as $anexo) {
                    // Eliminar los anexos de la tabla anexos
                    DB::table('tipos_anexos')->where('id', $anexo->id)->delete();

                    // Eliminar la tabla de anexos si existe
                    if (Schema::hasTable($anexo->letras_identificacion)) {
                        Schema::dropIfExists($anexo->letras_identificacion);
                    }

                    DB::table('campos_anexos')->where('tipo_anexo', $anexo->id)->delete();

                    $plantillasPathsAnexo = [
                        $anexo->plantilla_path_1 ?? null, $anexo->plantilla_path_2 ?? null, $anexo->plantilla_path_3 ?? null, $anexo->plantilla_path_4 ?? null,
                        $anexo->plantilla_path_5 ?? null, $anexo->plantilla_path_6 ?? null, $anexo->plantilla_path_7 ?? null, $anexo->plantilla_path_8 ?? null,
                    ];

                    foreach ($plantillasPathsAnexo as $plantillaPathAnexo) {
                        // Eliminar la plantilla si existe
                        if ($plantillaPathAnexo && Storage::disk('public')->exists($plantillaPathAnexo)) {
                            Storage::disk('public')->delete($plantillaPathAnexo);
                        }
                    }
                }
            }

            

            $this->info("Data for product ID $productId has been deleted.");

        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }

    }
}

