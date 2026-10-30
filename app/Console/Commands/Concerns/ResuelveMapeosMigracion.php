<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mapeos compartidos por los comandos de migración antigua (MySQL) → nueva (SQL Server).
 *
 * - Sociedades: se emparejan por codigo_sociedad y, si no hay código en común, por nombre sin acentos.
 *   (Agrupcat se renombró a NaturProtect en la nueva conservando el código 08105; "Benjamín Mañas" es "Benjamin Mañas".)
 * - Comercial: en la antigua `id_emisor` es el id de la SOCIEDAD emisora, no de `users`. El comercial de la nueva
 *   es el responsable de esa sociedad (el de menor id si hay varios), igual que en las migraciones ya hechas.
 */
trait ResuelveMapeosMigracion
{
    /** @var array<int, array{id:int, nombre:?string}>|null  sociedad nueva => comercial responsable */
    private $comercialesResponsablesPorSociedad = null;

    /**
     * @return array<int,int>  id_sociedad antigua => id sociedad nueva
     */
    protected function mapearSociedadesPorCodigoYNombre(): array
    {
        $antiguas = DB::connection('mysql')->table('sociedades')->get(['id_sociedad', 'nombre', 'codigo_sociedad']);
        $nuevas = DB::connection('sqlsrv')->table('sociedad')->orderBy('id')->get(['id', 'nombre', 'codigo_sociedad']);

        $porCodigo = [];
        $porNombre = [];

        foreach ($nuevas as $nueva) {
            $codigo = trim((string) $nueva->codigo_sociedad);
            if ($codigo !== '' && !isset($porCodigo[$codigo])) {
                $porCodigo[$codigo] = $nueva->id;
            }

            $nombre = $this->normalizarNombreSociedad($nueva->nombre);
            // Si el nombre se repite (sociedades de prueba con ids bajos) gana la migrada real (id >= 10000)
            if ($nombre !== '' && (!isset($porNombre[$nombre]) || $nueva->id >= 10000)) {
                $porNombre[$nombre] = $nueva->id;
            }
        }

        $mapeo = [];
        foreach ($antiguas as $antigua) {
            $codigo = trim(substr((string) $antigua->codigo_sociedad, 0, 10));
            $nombre = $this->normalizarNombreSociedad($antigua->nombre);

            if ($codigo !== '' && isset($porCodigo[$codigo])) {
                $mapeo[$antigua->id_sociedad] = $porCodigo[$codigo];
            } elseif ($nombre !== '' && isset($porNombre[$nombre])) {
                $mapeo[$antigua->id_sociedad] = $porNombre[$nombre];
            }
        }

        return $mapeo;
    }

    /**
     * `codigo_postal` de los productos es int en la nueva, pero en socios hay textos como 'AD200' o '28.010'.
     * Se dejan solo los dígitos y, si no quedan 4 o 5, se devuelve null.
     */
    protected function codigoPostalEntero($valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $digitos = preg_replace('/[^0-9]/', '', (string) $valor);

        return (strlen($digitos) >= 4 && strlen($digitos) <= 5) ? (int) $digitos : null;
    }

    protected function normalizarNombreSociedad(?string $nombre): string
    {
        return Str::ascii(mb_strtolower(trim((string) $nombre)));
    }

    /**
     * @return array{id:int, nombre:?string}|null
     */
    protected function comercialResponsableDeSociedad(int $sociedadNuevaId): ?array
    {
        if ($this->comercialesResponsablesPorSociedad === null) {
            $this->comercialesResponsablesPorSociedad = [];

            $filas = DB::connection('sqlsrv')
                ->table('comercial')
                ->where('responsable', 1)
                ->orderBy('id')
                ->get(['id', 'nombre', 'id_sociedad']);

            foreach ($filas as $fila) {
                if (!isset($this->comercialesResponsablesPorSociedad[$fila->id_sociedad])) {
                    $this->comercialesResponsablesPorSociedad[$fila->id_sociedad] = [
                        'id' => $fila->id,
                        'nombre' => $fila->nombre,
                    ];
                }
            }
        }

        return $this->comercialesResponsablesPorSociedad[$sociedadNuevaId] ?? null;
    }

    /**
     * Comercial de la nueva para un seguro antiguo: responsable de la sociedad emisora (`id_emisor`);
     * si no hay emisor (compras web), el de la sociedad del propio seguro.
     *
     * @param array<int,int> $mapeoSociedades
     * @return array{id:int, nombre:?string}|null
     */
    protected function resolverComercialPorSociedad(?int $idEmisor, ?int $idSociedadSeguro, array $mapeoSociedades): ?array
    {
        $sociedadAntigua = $idEmisor ?: $idSociedadSeguro;
        $sociedadNueva = $sociedadAntigua ? ($mapeoSociedades[$sociedadAntigua] ?? null) : null;

        return $sociedadNueva ? $this->comercialResponsableDeSociedad($sociedadNueva) : null;
    }
}
