<?php

namespace App\Exports;

use App\Models\GiroBancario;
use Illuminate\Support\Collection;

class GiroPagoExport implements PagoExportInterface
{
    public function getPagos(int $sociedadId, ?string $desde = null, ?string $hasta = null): Collection
    {
        $query = GiroBancario::with('cliente')
            ->where('sociedad_id', $sociedadId);

        if ($desde) {
            $query->whereDate('fecha_emision', '>=', $desde);
        }

        if ($hasta) {
            $query->whereDate('fecha_emision', '<=', $hasta);
        }

        return $query->get()->map(function ($pago) {
            return [
                'ID' => $pago->id,
                'Cliente' => $pago->cliente->nombre ?? 'N/A',
                'Fecha de emisión' => $pago->fecha_emision->format('Y-m-d'),
                'Importe' => number_format($pago->monto, 2, ',', '.'),
                'IBAN Cliente' => $pago->iban,
                'Estado' => ucfirst($pago->estado),
                'Método' => 'Giro bancario',
            ];
        });
    }
}
