<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 'pendiente_pago' marca las filas creadas ANTES de confirmar un pago con
 * tarjeta (se crean así para que la generación del certificado no dependa
 * de que el navegador vuelva de kyrema.org/Redsys). RedsysInsiteController::
 * notify() la pone a false cuando el IPN confirma el cobro, o borra la fila
 * si el pago es denegado.
 *
 * A diferencia de la migración de 'renovado' (2026_08_21_...), aquí SÍ
 * incluimos las tablas de anexo (tipo_producto_asociado no nulo): también
 * se crean pendientes y también hay que activarlas/borrarlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->tablasProductoYAnexo() as $tabla) {
            if (!Schema::hasColumn($tabla, 'pendiente_pago')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->boolean('pendiente_pago')->default(false);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tablasProductoYAnexo() as $tabla) {
            if (Schema::hasColumn($tabla, 'pendiente_pago')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropColumn('pendiente_pago');
                });
            }
        }
    }

    /**
     * Tablas físicas de producto (padre, resolviendo subproductos) + tablas
     * de anexo, deduplicadas. Ver tablasDeProducto() en
     * 2026_08_21_000000_add_renovado_column_to_producto_tables.php: aquí no
     * excluimos tipo_producto_asociado porque los anexos también necesitan
     * la columna, y no exigimos 'fecha_de_fin' porque los anexos pueden no
     * tenerla.
     */
    private function tablasProductoYAnexo(): Collection
    {
        return DB::table('tipo_producto')
            ->whereNotNull('letras_identificacion')
            ->get()
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
            ->filter(fn ($tabla) => Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'id'))
            ->values();
    }
};
