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
                'Referencia' => $this->limpiarReferencia($giro->referencia),
                'Tipo de pago' => $this->formatearTipoPago($giro->pago->tipo_pago ?? null),
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

    /**
     * Corrige las referencias antiguas que quedaron con las siglas duplicadas
     * (p.ej. "062026SJKSJK0000005" -> "062026SJK0000005"). Si la referencia no
     * tiene siglas repetidas se devuelve tal cual.
     */
    private function limpiarReferencia(?string $referencia): ?string
    {
        if (!$referencia) {
            return $referencia;
        }

        // <prefijo fecha 6 dígitos><siglas><siglas repetidas><número>
        // Las siglas pueden ser alfanuméricas (p.ej. "K1"), pero siempre empiezan
        // por letra, por eso el grupo se ancla con [A-Za-z] para no confundirlas
        // con los dígitos del prefijo de fecha ni del número de secuencia.
        return preg_replace('/^(\d{6})([A-Za-z][A-Za-z0-9]*?)\2(\d+)$/', '$1$2$3', $referencia);
    }

    /**
     * Convierte el tipo de pago almacenado (p.ej. "giro_bancario") en un texto
     * legible ("Giro bancario").
     */
    private function formatearTipoPago(?string $tipoPago): string
    {
        if (!$tipoPago) {
            return 'N/A';
        }

        return ucfirst(str_replace('_', ' ', $tipoPago));
    }
}
