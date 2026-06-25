<?php

namespace App\Exports;

use App\Models\Payments\Transferencia;
use Illuminate\Support\Collection;

class TransferenciaPagoExport implements PagoExportInterface
{
    public function getPagos(int $sociedadId, ?string $desde = null, ?string $hasta = null): Collection
    {
        // Filtramos por la fecha del pago (pagos.fecha), que siempre está poblada,
        // en lugar de por la fecha_de_inicio del producto relacionado (que dependía
        // de pagos.producto_id, una columna que nunca se rellena).
        $transferencias = Transferencia::with('pago')
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

        return $transferencias->map(function ($trans) {
            return [
                'ID' => $trans->id,
                'Cliente' => $trans->nombre_cliente ?? 'N/A',
                'Fecha de pago' => $trans->pago && $trans->pago->fecha
                    ? \Carbon\Carbon::parse($trans->pago->fecha)->format('Y-m-d')
                    : 'N/A',
                'Importe' => number_format($trans->pago->monto ?? $trans->importe ?? 0, 2, ',', '.'),
                'Estado' => ucfirst($trans->pago->estado ?? ''),
                'Método' => 'Transferencia',
            ];
        });
    }
}
