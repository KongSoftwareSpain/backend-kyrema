<?php

namespace App\Exports;

use App\Models\Payments\GiroBancario;
use Illuminate\Support\Collection;

class GiroPagoExport implements PagoExportInterface
{
    public function getPagos(int $sociedadId, ?string $desde = null, ?string $hasta = null): Collection
    {
        // Filtramos directamente por la fecha del pago (pagos.fecha), que siempre
        // está poblada, en lugar de por la fecha_de_inicio del producto relacionado
        // (que dependía de pagos.producto_id, una columna que nunca se rellena).
        $giros = GiroBancario::with('pago')
            ->whereHas('pago', function ($query) use ($sociedadId, $desde, $hasta) {
                if ($sociedadId !== 0) {
                    $query->where('sociedad_id', $sociedadId);
                }
                if ($desde) {
                    $query->whereDate('fecha', '>=', $desde);
                }
                if ($hasta) {
                    $query->whereDate('fecha', '<=', $hasta);
                }
            })
            ->get();

        return $giros->map(function ($giro) {
            return [
                'Referencia' => $giro->referencia,
                'Tipo de pago' => $giro->pago->tipo_pago ?? 'N/A',
                'Monto' => number_format($giro->pago->monto ?? 0, 2, ',', '.'),
                'Fecha de pago' => $giro->pago && $giro->pago->fecha
                    ? \Carbon\Carbon::parse($giro->pago->fecha)->format('Y-m-d')
                    : 'N/A',

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
