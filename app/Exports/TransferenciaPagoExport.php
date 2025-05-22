<?php

namespace App\Exports;

use App\Models\Transferencia;
use Illuminate\Support\Collection;

class TransferenciaPagoExport implements PagoExportInterface
{
    public function getPagos(int $sociedadId, ?string $desde = null, ?string $hasta = null): Collection
    {
        $query = Transferencia::with('cliente')
            ->where('sociedad_id', $sociedadId);

        if ($desde) {
            $query->whereDate('created_at', '>=', $desde);
        }

        if ($hasta) {
            $query->whereDate('created_at', '<=', $hasta);
        }

        return $query->get()->map(function ($pago) {
            return [
                'ID' => $pago->id,
                'Cliente' => $pago->cliente->nombre ?? 'N/A',
                'Fecha' => $pago->created_at->format('Y-m-d'),
                'Importe' => number_format($pago->monto, 2, ',', '.'),
                'Estado' => ucfirst($pago->estado),
                'Método' => 'Transferencia',
            ];
        });
    }

}
