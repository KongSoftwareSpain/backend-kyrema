<?php

namespace App\Exports;

use InvalidArgumentException;

class PagoExportFactory
{
    /**
     * Devuelve una instancia del exportador según el tipo de pago.
     *
     * @param string $tipo
     * @return PagoExportInterface
     */
    public static function make(string $tipo): PagoExportInterface
    {
        return match (strtolower($tipo)) {
            '5' => new TransferenciaPagoExport(),
            '10' => new GiroPagoExport(),
            // Puedes agregar más aquí fácilmente
            default => throw new InvalidArgumentException("Tipo de pago no soportado: $tipo"),
        };
    }
}
