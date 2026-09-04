<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;

/**
 * Resuelve las letras_identificacion de un tipo_producto (que puede ser un
 * subproducto) a la tabla física donde vive el producto: la del padre si
 * tiene padre_id, si no la suya propia. Misma resolución que ya hacía
 * ProductoController::crearProducto() inline; se extrae aquí porque
 * RedsysInsiteController::notify() también la necesita.
 */
class ProductoTableResolver
{
    public function tableName(string $letrasIdentificacion): ?string
    {
        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        if (!$tipoProducto) {
            return null;
        }

        if ($tipoProducto->padre_id != null) {
            $tipoProducto = DB::table('tipo_producto')
                ->where('id', $tipoProducto->padre_id)
                ->first();

            if (!$tipoProducto) {
                return null;
            }
        }

        return strtolower($tipoProducto->letras_identificacion);
    }

    /**
     * Letras de las tablas de anexo (tipo_producto_asociado) conectadas al
     * tipo_producto padre resuelto por tableName().
     */
    public function anexoTableNames(string $letrasIdentificacion): array
    {
        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        if (!$tipoProducto) {
            return [];
        }

        $tipoProductoId = $tipoProducto->padre_id ?? $tipoProducto->id;

        return DB::table('tipo_producto')
            ->where('tipo_producto_asociado', $tipoProductoId)
            ->pluck('letras_identificacion')
            ->map(fn ($letras) => strtolower($letras))
            ->all();
    }
}
