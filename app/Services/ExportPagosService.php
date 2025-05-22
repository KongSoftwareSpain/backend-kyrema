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

        if ($data->isNotEmpty()) {
            // Escribir encabezados
            fputcsv($handle, array_keys($data->first()));

            // Escribir filas
            foreach ($data as $row) {
                fputcsv($handle, $row);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
