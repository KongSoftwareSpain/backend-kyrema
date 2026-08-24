<?php

namespace App\Exports;

use Illuminate\Support\Collection;

interface PagoExportInterface
{
    /**
     * Devuelve los pagos filtrados por sociedad y fecha.
     *
     * @param int $sociedadId
     * @param string|null $desde  // formato YYYY-MM-DD
     * @param string|null $hasta
     * @return Collection
     */
    public function getPagos(int $sociedadId, ?string $desde = null, ?string $hasta = null): Collection;

    /**
     * Columnas del informe, en orden. Permite que el CSV lleve cabeceras
     * incluso cuando la consulta no devuelve ninguna fila.
     *
     * @return array<int, string>
     */
    public function cabeceras(): array;
}
