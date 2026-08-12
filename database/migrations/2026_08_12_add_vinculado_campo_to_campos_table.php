<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar campo "vinculado" a cada tipo_producto
        $tiposProductos = DB::table('tipo_producto')->get();

        foreach ($tiposProductos as $tipo) {
            // Verificar si el campo ya existe
            $campoExistente = DB::table('campos')
                ->where('tipo_producto_id', $tipo->id)
                ->where('nombre_codigo', 'vinculado')
                ->first();

            if (!$campoExistente) {
                DB::table('campos')->insert([
                    'nombre' => 'Vinculación',
                    'nombre_codigo' => 'vinculado',
                    'tipo_producto_id' => $tipo->id,
                    'tipo_dato' => 'text',
                    'visible' => '1',
                    'obligatorio' => '0',
                    'copia' => '0',
                    'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                    'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Eliminar campos "vinculado" de todos los tipos_producto
        DB::table('campos')
            ->where('nombre_codigo', 'vinculado')
            ->delete();
    }
};
