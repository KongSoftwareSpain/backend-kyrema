<?php

namespace App\Exports;

use App\Models\Payments\Transferencia;
use Illuminate\Support\Collection;

class TransferenciaPagoExport implements PagoExportInterface
{
    public function getPagos(int $sociedadId, ?string $desde = null, ?string $hasta = null): Collection
    {
        $query = Transferencia::with('pago', 'cliente')
            ->whereHas('pago', function ($query) use ($sociedadId) {
                if ($sociedadId !== 0) {
                    $query->where('sociedad_id', $sociedadId);
                }
            });

        $transferencias = $query->get();

        // Filtrar por fecha_de_inicio del producto relacionado
        if ($desde || $hasta) {
            $transferencias = $transferencias->filter(function ($trans) use ($desde, $hasta) {
                $producto = $trans->pago ? $trans->pago->obtenerProductoRelacionado() : null;
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

        return $transferencias->map(function ($pago) {
            $producto = $pago->pago ? $pago->pago->obtenerProductoRelacionado() : null;
            return [
                'ID' => $pago->id,
                'Cliente' => $pago->cliente->nombre ?? 'N/A',
                'Fecha de inicio' => $producto ? \Carbon\Carbon::parse($producto->fecha_de_inicio)->format('Y-m-d') : 'N/A',
                'Importe' => number_format($pago->monto, 2, ',', '.'),
                'Estado' => ucfirst($pago->estado),
                'Método' => 'Transferencia',
            ];
        });
    }
}
