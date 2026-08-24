<?php

namespace App\Exports;

use App\Models\TipoPago;
use InvalidArgumentException;

class PagoExportFactory
{
    /**
     * Tipos de pago cuyos datos viven en una tabla propia, por tipos_pago.codigo.
     *
     * Se resuelve por código y no por el nombre visible: antes se hacía
     * str_contains($nombre, 'giro'), que se rompe en cuanto alguien renombra la
     * fila desde el panel.
     *
     * Solo el giro bancario está aquí. Es el único cuyo cobro se registra de
     * verdad en `pagos` + `giros_bancarios`: lo escribe RemesaController al
     * generar el mandato. El resto de formas de pago no crean registro en
     * `pagos` y se resuelven contra las tablas de póliza (ver
     * PagoPorPolizaExport).
     */
    private const EXPORTADORES_CON_TABLA_PROPIA = [
        'giro_bancario' => GiroPagoExport::class,
    ];

    /**
     * Devuelve una instancia del exportador según el tipo de pago.
     *
     * @param string $tipo  id del tipo de pago (tipos_pago.id)
     * @return PagoExportInterface
     */
    public static function make(string $tipo): PagoExportInterface
    {
        $tipoPago = TipoPago::find($tipo);

        if (!$tipoPago) {
            throw new InvalidArgumentException("Tipo de pago no soportado: $tipo");
        }

        $codigo = strtolower(trim($tipoPago->codigo));

        if (isset(self::EXPORTADORES_CON_TABLA_PROPIA[$codigo])) {
            $clase = self::EXPORTADORES_CON_TABLA_PROPIA[$codigo];

            return new $clase();
        }

        // El informe se construye desde las tablas de producto filtrando por el
        // nombre visible del tipo, que es lo que guarda la columna tipo_de_pago
        // de la póliza ('Domiciliación', 'Efectivo', 'Transferencia'...).
        return new PagoPorPolizaExport(trim($tipoPago->nombre));
    }
}
