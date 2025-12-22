<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * ============================================================================
 * COMANDO: TransferSociedades
 * ============================================================================
 *
 * DESCRIPCIÓN:
 * Este comando migra las sociedades desde MySQL hacia SQL Server.
 * A diferencia de los socios, las sociedades tienen una estructura jerárquica
 * (Padre -> Hija) que debe ser preservada.
 *
 * ESTRATEGIA DE DOS FASES:
 * 1. FASE 1 (Inserción): Se crean todas las sociedades de forma individual.
 *    En este paso NO se establecen los padres, ya que el ID del padre podría
 *    no existir todavía en el destino.
 * 2. FASE 2 (Jerarquía): Una vez que todas las sociedades existen en destino,
 *    se recorren de nuevo para enlazar cada hija con su padre correspondiente
 *    usando el 'codigo_sociedad' como puente de unión.
 *
 * CLAVES TÉCNICAS:
 * - DEDUPLICACIÓN: Se usa 'codigo_sociedad' para saber si una sociedad ya existe.
 * - IDEMPOTENCIA: El script puede fallar y re-ejecutarse sin duplicar datos.
 * - TRUNCAMIENTO: Se asegura de que los strings largos no rompan la DB destino.
 *
 * MODO DE USO:
 * php artisan transfer:sociedades {--chunk=200}
 * ============================================================================
 */
class TransferSociedades extends Command
{
    /**
     * @var string Firma del comando.
     */
    protected $signature = 'transfer:sociedades {--chunk=200}';

    /**
     * @var string Descripción del comando.
     */
    protected $description = 'Migración IDempotente de sociedades Kyrema → KONG (por codigo_sociedad, con jerarquía)';

    /**
     * Lógica de ejecución.
     */
    public function handle()
    {
        $chunk = (int) $this->option('chunk');
        $now = Carbon::now();

        $this->info('====================================================');
        $this->info(' INICIO MIGRACIÓN DE SOCIEDADES (IDEMPOTENTE)');
        $this->info('====================================================');
        $this->info("Tamaño de bloque: {$chunk}");
        $this->newLine();

        /**
         * --------------------------------------------------------------------
         * PRE-CHECK DE CONEXIÓN
         * --------------------------------------------------------------------
         */
        try {
            DB::connection('mysql')->table('sociedades')->count();
            DB::connection('sqlsrv')->table('sociedad')->count();
            $this->info('✔ Conexiones verificadas (mysql.sociedades → sqlsrv.sociedad)');
        } catch (\Throwable $e) {
            $this->error('✘ Error crítico de conexión');
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        /**
         * --------------------------------------------------------------------
         * FASE 1: INSERCIÓN INDIVIDUAL (ATOPE)
         * --------------------------------------------------------------------
         * El objetivo aquí es simplemente "volcar" todas las sociedades
         * que falten en el destino, ignorando de momento quién es hijo de quién.
         */
        $this->info('====================================================');
        $this->info(' FASE 1 — Inserción de registros (sin jerarquía)');
        $this->info('====================================================');

        DB::connection('mysql')
            ->table('sociedades')
            ->orderBy('id_sociedad')
            ->chunk($chunk, function ($rows) use ($now) {

                $this->info('------------------------------------------------');
                $this->info('Procesando bloque de ' . $rows->count() . ' sociedades');
                $this->info('------------------------------------------------');

                foreach ($rows as $row) {
                    $idOrigen = $row->id_sociedad ?? null;
                    // El codigo_sociedad es nuestra clave única de negocio
                    $codigoSociedad = $this->truncate($row->codigo_sociedad ?? null, 10);

                    $this->line("Sociedad ORIGEN: [ID={$idOrigen}] [Cod={$codigoSociedad}]");

                    try {
                        /**
                         * 1.1 FILTRO DE SEGURIDAD
                         * Si una sociedad no tiene código en el origen, no podemos
                         * identificarla en el destino de forma segura. La descartamos.
                         */
                        if (empty($codigoSociedad)) {
                            $this->warn("  ⚠ Saltada: No tiene codigo_sociedad.");
                            Log::channel('transfer_sociedades')->warning('SOCIEDAD_SIN_CODIGO', ['origen_id' => $idOrigen]);
                            continue;
                        }

                        /**
                         * 1.2 DEDUPLICACIÓN
                         * ¿Existe ya alguien con este código en SQL Server?
                         */
                        $existingId = DB::connection('sqlsrv')
                            ->table('sociedad')
                            ->where('codigo_sociedad', $codigoSociedad)
                            ->value('id');

                        if ($existingId) {
                            $this->line("  ✔ Ya presente en destino (ID={$existingId}). No se inserta.");
                            continue;
                        }

                        /**
                         * 1.3 FORMATEO DE FECHAS
                         * MySQL suele guardar timestamps como INT o null. Normalizamos a Carbon.
                         */
                        $createdAt = (is_numeric($row->fecha_creacion ?? null) && (int) $row->fecha_creacion > 0)
                            ? Carbon::createFromTimestamp((int) $row->fecha_creacion)
                            : Carbon::create(1900, 1, 1);

                        /**
                         * 1.4 CREACIÓN EN DESTINO
                         * Mapeamos todos los campos y aplicamos truncamiento de seguridad
                         * para que no estalle por longitud de campo en SQL Server.
                         */
                        $idDestino = DB::connection('sqlsrv')
                            ->table('sociedad')
                            ->insertGetId([
                                'nombre' => $row->nombre ?? null,
                                'razon_social' => $row->nombre_sociedad_kyrema_naturaleza ?? null,
                                'direccion' => $row->direccion ?? null,
                                'poblacion' => $row->poblacion ?? null,
                                'codigo_postal' => $this->truncate($row->cod_postal ?? null, 10),
                                'pais' => $this->truncate($row->pais ?? null, 100),
                                'cif' => $this->truncate($row->cif ?? null, 20),
                                'telefono' => $this->truncate($row->telefono ?? null, 20),
                                'fax' => $this->truncate($row->fax ?? null, 20),
                                'movil' => $this->truncate($row->movil ?? null, 20),
                                'iban' => $this->truncate($row->iban ?? null, 34),
                                'banco' => $row->banco ?? null,
                                'sucursal' => $row->sucursal ?? null,
                                'dc' => $this->truncate($row->dc ?? null, 2),
                                'numero_cuenta' => $this->truncate($row->cuenta ?? null, 20),
                                'swift' => $this->truncate($row->swift ?? null, 11),
                                'codigo_sociedad' => $codigoSociedad,
                                'logo' => $row->logo_img ?? null,
                                'observaciones' => $row->observaciones ?? null,
                                'created_at' => DB::raw("CONVERT(datetime, '{$createdAt->format('Y-m-d H:i:s')}', 120)"),
                                'updated_at' => DB::raw("CONVERT(datetime, '{$createdAt->format('Y-m-d H:i:s')}', 120)"),
                            ], 'id');

                        $this->info("  ✔ Inserción OK. Nuevo ID Destino: {$idDestino}");

                    } catch (\Throwable $e) {
                        $this->error("✘ Fallo en sociedad ID_ORIGEN={$idOrigen}");
                        Log::channel('transfer_sociedades')->error('ERROR_INSERT_SOCIEDAD', [
                            'id_origen' => $idOrigen,
                            'msg' => $e->getMessage(),
                        ]);
                    }
                }
            });

        /**
         * --------------------------------------------------------------------
         * FASE 2: RESOLUCIÓN DE JERARQUÍAS (ATOPE)
         * --------------------------------------------------------------------
         * Ahora que sabemos que (casi) todas están en destino, recorremos
         * solo las que tienen padre en MySQL para actualizar el link en KONG.
         */
        $this->newLine();
        $this->info('====================================================');
        $this->info(' FASE 2 — Reconstrucción de jerarquía Padre/Hijo');
        $this->info('====================================================');

        try {
            // Buscamos solo los registros que declaran tener un padre
            $sociedadesConPadre = DB::connection('mysql')
                ->table('sociedades')
                ->whereNotNull('id_padre')
                ->select('id_sociedad', 'id_padre', 'codigo_sociedad')
                ->get();

            foreach ($sociedadesConPadre as $row) {
                $codigoHijo = $this->truncate($row->codigo_sociedad ?? null, 10);
                if (empty($codigoHijo))
                    continue;

                /**
                 * 2.1 LOCALIZAR CÓDIGO DEL PADRE
                 * Como en el destino los IDs autonuméricos cambian,
                 * necesitamos buscar el 'codigo_sociedad' del padre en MySQL.
                 */
                $codigoPadre = DB::connection('mysql')
                    ->table('sociedades')
                    ->where('id_sociedad', $row->id_padre)
                    ->value('codigo_sociedad');

                $codigoPadre = $this->truncate($codigoPadre ?? null, 10);
                if (empty($codigoPadre))
                    continue;

                /**
                 * 2.2 EMPAREJAR EN DESTINO
                 * Buscamos ambos códigos en SQL Server para obtener sus nuevos IDs.
                 */
                $idHijoDestino = DB::connection('sqlsrv')->table('sociedad')->where('codigo_sociedad', $codigoHijo)->value('id');
                $idPadreDestino = DB::connection('sqlsrv')->table('sociedad')->where('codigo_sociedad', $codigoPadre)->value('id');

                if ($idHijoDestino && $idPadreDestino) {
                    // Check de seguridad: ¿Ya está asignado ese padre?
                    $actualPadreId = DB::connection('sqlsrv')->table('sociedad')->where('id', $idHijoDestino)->value('sociedad_padre_id');

                    if ((string) $actualPadreId !== (string) $idPadreDestino) {
                        DB::connection('sqlsrv')
                            ->table('sociedad')
                            ->where('id', $idHijoDestino)
                            ->update([
                                'sociedad_padre_id' => $idPadreDestino,
                                'updated_at' => DB::raw("CONVERT(datetime, '" . Carbon::now()->format('Y-m-d H:i:s') . "', 120)"),
                            ]);

                        $this->line("✔ Enlazado: Hijo ({$codigoHijo}) -> Padre ({$codigoPadre})");
                    }
                }
            }

        } catch (\Throwable $e) {
            $this->error('✘ Error intentando reconstruir la jerarquía');
            Log::channel('transfer_sociedades')->error('ERROR_HIERARCHY', ['msg' => $e->getMessage()]);
        }

        $this->newLine();
        $this->info('====================================================');
        $this->info(' MIGRACIÓN DE SOCIEDADES FINALIZADA CORRECTAMENTE');
        $this->info('====================================================');

        return self::SUCCESS;
    }

    /**
     * Auxiliar para evitar que el script falle si un dato de origen
     * es más largo de lo que permite la columna de destino.
     *
     * @param mixed $value Valor a truncar.
     * @param int $max Longitud máxima permitida.
     * @return string|null
     */
    private function truncate($value, int $max): ?string
    {
        if ($value === null)
            return null;
        $v = trim((string) $value);
        if ($v === '')
            return null;
        return mb_substr($v, 0, $max);
    }
}
