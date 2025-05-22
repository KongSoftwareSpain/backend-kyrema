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
            'transferencia' => new TransferenciaPagoExport(),
            'giro' => new GiroPagoExport(),
            // Puedes agregar más aquí fácilmente
            default => throw new InvalidArgumentException("Tipo de pago no soportado: $tipo"),
        };
    }
}
