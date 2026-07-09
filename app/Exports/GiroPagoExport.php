<?php

namespace App\Exports;

use App\Exports\Concerns\FormateaCamposPago;
use App\Models\Payments\GiroBancario;
use Illuminate\Support\Collection;

class GiroPagoExport implements PagoExportInterface
{
    use FormateaCamposPago;

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
                'Referencia' => $this->limpiarReferencia($giro->referencia),
                'Tipo de pago' => $this->formatearTipoPago($giro->pago->tipo_pago ?? null),
                'Monto' => number_format($giro->pago->monto ?? 0, 2, ',', '.'),
                'Fecha de pago' => $giro->pago && $giro->pago->fecha
                    ? \Carbon\Carbon::parse($giro->pago->fecha)->format('d/m/Y')
                    : 'N/A',

                'Nombre del cliente' => $giro->nombre_cliente,
                'DNI' => $giro->dni,
                'Fecha firma mandato' => optional($giro->fecha_firma_mandato)->format('d/m/Y'),
                'IBAN' => $giro->iban_cliente,
                'Auxiliar' => $giro->auxiliar,
                'Sociedad' => $giro->sociedad,
                'Residente' => $giro->residente,
                'Referencia mandato' => $giro->referencia_mandato,
                'Fecha cobro' => optional($giro->fecha_cobro)->format('d/m/Y'),
                'Referencia adeudo' => $giro->referencia_adeudo,
                'Tipo de adeudo' => $giro->tipo_adeudo,
                'Concepto' => $this->formatearFechasConcepto($giro->concepto),
            ];
        });
    }

}
