<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca de renovación en las tablas de producto.
     *
     * 'renovado' se pone a true en el producto ORIGINAL cuando se genera su
     * renovación, y 'renovado_por_id' guarda el id de la póliza hija. Hasta
     * ahora el vínculo original -> renovado solo viajaba en la respuesta HTTP
     * de renovarProductosEnPaquete y se perdía.
     *
     * Los productos renovados antes de esta migración quedan con renovado=false:
     * la recuperación del histórico se aborda por separado.
     */
    public function up(): void
    {
        foreach ($this->tablasDeProducto() as $tabla) {
            if (!Schema::hasColumn($tabla, 'renovado')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->boolean('renovado')->default(false);
                });
            }

            if (!Schema::hasColumn($tabla, 'renovado_por_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->unsignedBigInteger('renovado_por_id')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tablasDeProducto() as $tabla) {
            if (Schema::hasColumn($tabla, 'renovado_por_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropColumn('renovado_por_id');
                });
            }

            if (Schema::hasColumn($tabla, 'renovado')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropColumn('renovado');
                });
            }
        }
    }

    /**
     * Tablas físicas de producto padre, sin repetir.
     *
     * - Los subproductos no tienen tabla propia: reutilizan la del padre.
     * - Los anexos (tipo_producto_asociado) se excluyen: no se renuevan por sí
     *   solos, se clonan junto al producto padre.
     * - 'fecha_de_fin' se usa como prueba de que la tabla es de pólizas, igual
     *   que hace la migración de 'caducado'.
     */
    private function tablasDeProducto(): Collection
    {
        // 'tipo_producto_asociado' se usa en el código pero no la crea ninguna
        // migración (se añadió directamente en BD), así que no damos por hecho
        // que exista en un esquema recién montado.
        $tieneAsociado = Schema::hasColumn('tipo_producto', 'tipo_producto_asociado');

        return DB::table('tipo_producto')
            ->whereNotNull('letras_identificacion')
            ->get()
            ->reject(fn ($tipo) => $tieneAsociado && !empty($tipo->tipo_producto_asociado))
            ->map(function ($tipo) {
                if (!empty($tipo->padre_id)) {
                    $padre = DB::table('tipo_producto')->where('id', $tipo->padre_id)->first();
                    if ($padre && !empty($padre->letras_identificacion)) {
                        return strtolower($padre->letras_identificacion);
                    }
                }

                return strtolower($tipo->letras_identificacion);
            })
            ->unique()
            ->filter(fn ($tabla) => Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'fecha_de_fin'))
            ->values();
    }
};
