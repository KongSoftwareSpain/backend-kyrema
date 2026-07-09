<?php

namespace App\Exports;

use App\Models\TipoPago;
use InvalidArgumentException;

class PagoExportFactory
{
    /**
     * Devuelve una instancia del exportador según el tipo de pago.
     *
     * Se resuelve por el nombre del tipo de pago (tabla tipos_pago), no por un id
     * fijo, porque los ids de tipos_pago varían entre entornos.
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

        $nombre = strtolower(trim($tipoPago->nombre));

        // El giro bancario tiene tabla propia (giros_bancarios) con datos de mandato
        if (str_contains($nombre, 'giro')) {
            return new GiroPagoExport();
        }

        // El resto de tipos (transferencia, efectivo, tarjeta, domiciliación...)
        // solo existen en la tabla pagos: exportador genérico filtrando por código
        return new PagoGenericoExport($tipoPago->codigo, $tipoPago->nombre);
    }
}
