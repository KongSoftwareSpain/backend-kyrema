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

        if (str_contains($nombre, 'giro')) {
            return new GiroPagoExport();
        }

        if (str_contains($nombre, 'transferencia')) {
            return new TransferenciaPagoExport();
        }

        throw new InvalidArgumentException("Tipo de pago no soportado: {$tipoPago->nombre} (id $tipo)");
    }
}
