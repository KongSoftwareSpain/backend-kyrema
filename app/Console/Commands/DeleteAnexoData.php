<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DeleteAnexoData extends Command
{
    protected $signature = 'delete:anexo-data {anexoId}';
    protected $description = 'Deletes anexos data from various tables';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        try{
            $anexoId = $this->argument('anexoId');

            // Obtener letrasIdentificacion y plantilla_path antes de eliminar la tabla tipo_producto
            $product = DB::table('tipos_anexos')->where('id', $anexoId)->first();
            $letrasIdentificacion = $product->letras_identificacion ?? null;
            $plantillaPath = $product->plantilla_path ?? null;

            // Obtener los campos con opciones (opciones != null)
            // $camposConOpciones = DB::table('campos_anexos')->where('tipo_anexo', $anexoId)->whereNotNull('opciones')->get();


            // if($camposConOpciones != null && count($camposConOpciones) > 0){
            //     // Recorrer los campos con opciones y elminiar las tablas con el nombre de las opciones
            //     foreach ($camposConOpciones as $campo) {
            //         if (Schema::hasTable($campo->opciones)) {
            //             Schema::dropIfExists($campo->opciones);
            //         }
            //     }
            // }
            // //Comprobar si tiene tipos hijos:
            // $tiposHijos = DB::table('tipo_producto')->where('padre_id', $anexoId)->get();

            // if($tiposHijos != null && count($tiposHijos) > 0){
            //     foreach ($tiposHijos as $tipoHijo) {
            //         $this->call('delete:product-data', ['anexoId' => $tipoHijo->id]);
            //     }
            // }

            // Delete from tipos_anexos
            DB::table('tipos_anexos')->where('id', $anexoId)->delete();

            // Delete from tarifas_producto
            DB::table('tarifas_anexos')->where('id_tipo_anexo', $anexoId)->delete();

            // Drop the table if it exists
            if ($letrasIdentificacion && Schema::hasTable($letrasIdentificacion)) {
                Schema::dropIfExists($letrasIdentificacion);
            }

            // Delete from campos
            DB::table('campos_anexos')->where('tipo_anexo', $anexoId)->delete();

            // Eliminar la plantilla si existe
            if ($plantillaPath && Storage::disk('public')->exists($plantillaPath)) {
                Storage::disk('public')->delete($plantillaPath);
            }


            // $anexos = DB::table('tipos_anexos')->where('id_tipo_producto', $anexoId)->get();

            // if($anexos != null && count($anexos) > 0){
            //     foreach ($anexos as $anexo) {
            //         // Eliminar los anexos de la tabla anexos
            //         DB::table('tipos_anexos')->where('id', $anexo->id)->delete();

            //         // Eliminar la tabla de anexos si existe
            //         if (Schema::hasTable($anexo->letras_identificacion)) {
            //             Schema::dropIfExists($anexo->letras_identificacion);
            //         }

            //         DB::table('campos_anexos')->where('tipo_anexo', $anexo->id)->delete();

            //         $plantillaPathAnexo = $anexo->plantilla_path ?? null;

            //         // Eliminar la plantilla si existe
            //         if ($plantillaPathAnexo && Storage::disk('public')->exists($plantillaPathAnexo)) {
            //             Storage::disk('public')->delete($plantillaPathAnexo);
            //         }
            //     }
            // }

            

            $this->info("Data for anexo ID $anexoId has been deleted.");

        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }

    }
}

