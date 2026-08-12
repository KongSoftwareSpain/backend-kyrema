<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar columna vinculado a todas las tablas de productos dinámicas
        $tiposProductos = DB::table('tipo_producto')->get();

        foreach ($tiposProductos as $tipo) {
            $tableName = strtolower($tipo->letras_identificacion);

            // Si es subproducto, usar la tabla del padre
            if ($tipo->padre_id) {
                $padre = DB::table('tipo_producto')->where('id', $tipo->padre_id)->first();
                if ($padre) {
                    $tableName = strtolower($padre->letras_identificacion);
                }
            }

            // Agregar columna si la tabla existe y la columna no existe
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'vinculado')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('vinculado')->nullable()->after('dni');
                });
            }
        }
    }

    public function down(): void
    {
        $tiposProductos = DB::table('tipo_producto')->get();

        foreach ($tiposProductos as $tipo) {
            $tableName = strtolower($tipo->letras_identificacion);

            if ($tipo->padre_id) {
                $padre = DB::table('tipo_producto')->where('id', $tipo->padre_id)->first();
                if ($padre) {
                    $tableName = strtolower($padre->letras_identificacion);
                }
            }

            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'vinculado')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('vinculado');
                });
            }
        }
    }
};
