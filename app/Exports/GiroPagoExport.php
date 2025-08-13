<?php

namespace App\Exports;

use App\Models\Payments\GiroBancario;
use Illuminate\Support\Collection;

class GiroPagoExport implements PagoExportInterface
{
    public function getPagos(int $sociedadId, ?string $desde = null, ?string $hasta = null): Collection
    {
        $query = GiroBancario::with('pago')
            ->whereHas('pago', function ($q) use ($sociedadId, $desde, $hasta) {
                if ($sociedadId !== 0) {
                    $q->where('sociedad_id', $sociedadId);
                }

                if ($desde) {
                    $q->whereDate('fecha', '>=', $desde);
                }

                if ($hasta) {
                    $q->whereDate('fecha', '<=', $hasta);
                }
            });

        return $query->get()->map(function ($giro) {
            return [
                'Referencia' => $giro->referencia,
                'Tipo de pago' => $giro->pago->tipo_pago ?? 'N/A',
                'Monto' => number_format($giro->pago->monto ?? 0, 2, ',', '.'),
                'Fecha de creación' => optional($giro->pago->fecha)->format('Y-m-d'),

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
