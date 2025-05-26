<?php

namespace App\Services;

use App\Exports\PagoExportFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;

class ExportPagosService
{
    /**
     * Exporta los pagos como CSV según el tipo solicitado.
     *
     * @param string $tipo
     * @return \Illuminate\Http\Response
     */
    public function exportarCSV(string $tipo, int $sociedadId, ?string $desde = null, ?string $hasta = null)
    {
        $exportador = PagoExportFactory::make($tipo);

        $pagos = $exportador->getPagos($sociedadId, $desde, $hasta);

        $csv = $this->generarCSV($pagos);

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=pagos_' . $tipo . '.csv',
        ]);
    }


    /**
     * Genera una cadena CSV desde una colección de datos.
     *
     * @param Collection $data
     * @return string
     */
    protected function generarCSV(Collection $data): string
    {
        $handle = fopen('php://temp', 'r+');

        // Añadir BOM para que Excel lo abra con UTF-8
        fwrite($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if ($data->isNotEmpty()) {
            fputcsv($handle, array_keys($data->first()), ';');
            foreach ($data as $line) {
                fputcsv($handle, $line, ';');
            }
        }

        rewind($handle);
        return stream_get_contents($handle);
    }
}
