<?php

namespace App\Exports;

use App\Models\Payments\GiroBancario;
use Illuminate\Support\Collection;

class GiroPagoExport implements PagoExportInterface
{
    public function getPagos(int $sociedadId, ?string $desde = null, ?string $hasta = null): Collection
    {
        $query = GiroBancario::with('pago')
            ->whereHas('pago', function ($query) use ($sociedadId) {
                if ($sociedadId !== 0) {
                    $query->where('sociedad_id', $sociedadId);
                }
            });

        $giros = $query->get();

        // Filtrar por fecha_de_inicio del producto relacionado
        if ($desde || $hasta) {
            $giros = $giros->filter(function ($giro) use ($desde, $hasta) {
                $producto = $giro->pago ? $giro->pago->obtenerProductoRelacionado() : null;
                if (!$producto || !isset($producto->fecha_de_inicio)) {
                    return false;
                }
                $fechaInicio = \Carbon\Carbon::parse($producto->fecha_de_inicio);

                if ($desde && $fechaInicio->lt(\Carbon\Carbon::parse($desde))) {
                    return false;
                }
                if ($hasta && $fechaInicio->gt(\Carbon\Carbon::parse($hasta))) {
                    return false;
                }
                return true;
            });
        }

        return $giros->map(function ($giro) {
            $producto = $giro->pago ? $giro->pago->obtenerProductoRelacionado() : null;
            return [
                'Referencia' => $giro->referencia,
                'Tipo de pago' => $giro->pago->tipo_pago ?? 'N/A',
                'Monto' => number_format($giro->pago->monto ?? 0, 2, ',', '.'),
                'Fecha de inicio' => $producto ? \Carbon\Carbon::parse($producto->fecha_de_inicio)->format('Y-m-d') : 'N/A',

                'Nombre del cliente' => $giro->nombre_cliente,
                'DNI' => $giro->dni,
                'Fecha firma mandato' => optional($giro->fecha_firma_mandato)->format('Y-m-d'),
                'IBAN' => $giro->iban_cliente,
                'Auxiliar' => $giro->auxiliar,
                'Sociedad' => $giro->sociedad,
                'Residente' => $giro->residente,
                'Referencia mandato' => $giro->referencia_mandato,
                'Fecha cobro' => optional($giro->fecha_cobro)->format('Y-m-d'),
                'Referencia adeudo' => $giro->referencia_adeudo,
                'Tipo de adeudo' => $giro->tipo_adeudo,
                'Concepto' => $giro->concepto,
            ];
        });
    }
}
