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

        $csv = $this->generarCSV($pagos, $exportador->cabeceras());

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=pagos_' . $tipo . '.csv',
        ]);
    }


    /**
     * Genera una cadena CSV desde una colección de datos.
     *
     * Las cabeceras se escriben aunque no haya ni una fila: antes iban dentro
     * del isNotEmpty() y un resultado vacío producía un fichero de 3 bytes (sólo
     * el BOM), que Excel abre como una hoja en blanco sin dar ninguna pista de
     * si el filtro no devolvió nada o de si la exportación falló.
     *
     * @param Collection $data
     * @param array      $cabeceras  Columnas del exportador, para el caso vacío.
     * @return string
     */
    protected function generarCSV(Collection $data, array $cabeceras = []): string
    {
        $handle = fopen('php://temp', 'r+');

        // Añadir BOM para que Excel lo abra con UTF-8
        fwrite($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $cabeceras = $data->isNotEmpty() ? array_keys($data->first()) : $cabeceras;

        if ($cabeceras) {
            fputcsv($handle, $cabeceras, ';');
        }

        foreach ($data as $line) {
            fputcsv($handle, $line, ';');
        }

        rewind($handle);
        return stream_get_contents($handle);
    }
}
