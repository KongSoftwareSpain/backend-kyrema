<?php

namespace App\Exports;

use App\Exports\Concerns\FormateaCamposPago;
use App\Models\Payments\Pago;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Exportador para los tipos de pago sin tabla propia (transferencia, efectivo,
 * tarjeta, domiciliación...). Lee directamente de la tabla pagos filtrando por
 * el código del tipo de pago.
 */
class PagoGenericoExport implements PagoExportInterface
{
    use FormateaCamposPago;

    public function __construct(
        private string $codigo,
        private string $nombre,
    ) {
    }

    public function cabeceras(): array
    {
        return ['Referencia', 'Tipo de pago', 'Monto', 'Fecha de pago', 'Estado', 'Sociedad'];
    }

    public function getPagos(int $sociedadId, ?string $desde = null, ?string $hasta = null): Collection
    {
        $pagos = Pago::with('sociedad')
            ->where('tipo_pago', $this->codigo)
            ->when($sociedadId !== 0, fn ($query) => $query->where('sociedad_id', $sociedadId))
            ->when($desde, fn ($query) => $query->whereDate('fecha', '>=', $desde))
            ->when($hasta, fn ($query) => $query->whereDate('fecha', '<=', $hasta))
            ->get();

        return $pagos->map(function ($pago) {
            return [
                'Referencia' => $this->limpiarReferencia($pago->referencia),
                'Tipo de pago' => $this->nombre,
                'Monto' => number_format($pago->monto ?? 0, 2, ',', '.'),
                'Fecha de pago' => $pago->fecha
                    ? Carbon::parse($pago->fecha)->format('d/m/Y')
                    : 'N/A',
                'Estado' => ucfirst($pago->estado ?? ''),
                'Sociedad' => $pago->sociedad->nombre ?? '',
            ];
        });
    }
}
