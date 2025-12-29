<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TransferSocios extends Command
{
    protected $signature = 'transfer:socios {--chunk=500}';
    protected $description = 'Migración IDempotente y optimizada de socios Kyrema → KONG (deduplicada por DNI)';

    public function handle()
    {
        $chunk = (int) $this->option('chunk');
        $nowSql = Carbon::now()->format('Y-m-d H:i:s');

        $this->info('====================================================');
        $this->info(' INICIO MIGRACIÓN DE SOCIOS (OPTIMIZADA)');
        $this->info('====================================================');

        /**
         * --------------------------------------------------------
         * 1. PRECHECK
         * --------------------------------------------------------
         */
        try {
            DB::connection('mysql')->table('socios')->count();
            DB::connection('sqlsrv')->table('socios')->count();
            DB::connection('sqlsrv')->table('sociedad')->count();
            DB::connection('sqlsrv')->table('comercial')->count();
            $this->info('✔ Conexiones y tablas OK');
        } catch (\Throwable $e) {
            $this->error('✘ Error de conexión o tablas inexistentes');
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        /**
         * --------------------------------------------------------
         * 2. PRECARGAS (CLAVE DEL RENDIMIENTO)
         * --------------------------------------------------------
         */

        // 2.1 DNIs ya existentes en destino (idempotencia sin queries)
        $dnisExistentes = DB::connection('sqlsrv')
            ->table('socios')
            ->pluck('id', 'dni')
            ->toArray();

        $this->info('✔ DNIs existentes cargados: ' . count($dnisExistentes));

        // 2.2 Sociedades ORIGEN: id_sociedad → codigo_sociedad
        $sociedadesOrigen = DB::connection('mysql')
            ->table('sociedades')
            ->pluck('codigo_sociedad', 'id_sociedad')
            ->toArray();

        $this->info('✔ Sociedades origen cargadas: ' . count($sociedadesOrigen));

        // 2.3 Sociedades DESTINO: codigo_sociedad → id
        $sociedadesDestino = DB::connection('sqlsrv')
            ->table('sociedad')
            ->pluck('id', 'codigo_sociedad')
            ->toArray();

        $this->info('✔ Sociedades destino cargadas: ' . count($sociedadesDestino));

        // 2.4 Comerciales agrupados por sociedad DESTINO
        $comercialesPorSociedad = DB::connection('sqlsrv')
            ->table('comercial')
            ->select('id', 'id_sociedad')
            ->get()
            ->groupBy('id_sociedad');

        $this->info(
            '✔ Comerciales precargados. Sociedades con comerciales: ' .
            $comercialesPorSociedad->count()
        );

        /**
         * --------------------------------------------------------
         * 3. MIGRACIÓN POR BLOQUES
         * --------------------------------------------------------
         */
        DB::connection('mysql')
            ->table('socios')
            ->orderBy('id_socio')
            ->chunk($chunk, function ($rows) use (&$dnisExistentes, $sociedadesOrigen, $sociedadesDestino, $comercialesPorSociedad, $nowSql) {

                foreach ($rows as $row) {

                    $dni = trim((string) $row->dni);
                    $this->line("Procesando socio ORIGEN id={$row->id_socio} | DNI={$dni}");

                    try {
                        /**
                         * 3.1 VALIDACIÓN DE DNI
                         */
                        if ($dni === '') {
                            $this->warn('  ⚠ Socio sin DNI, se omite');
                            Log::channel('transfer_socios')->warning(
                                'SOCIO_SIN_DNI',
                                ['id_socio_origen' => $row->id_socio]
                            );
                            continue;
                        }

                        /**
                         * 3.2 DEDUPLICACIÓN EN MEMORIA
                         */
                        if (isset($dnisExistentes[$dni])) {
                            $this->warn("  ⚠ Ya existe socio (id={$dnisExistentes[$dni]}), se omite");
                            continue;
                        }

                        /**
                         * 3.3 NORMALIZACIÓN
                         */
                        [$nombre, $ap1, $ap2] = $this->parseNombreCompleto(
                            (string) ($row->nombre_socio ?? '')
                        );

                        $sexo = $this->mapSexo($row->sexo);

                        /**
                         * 3.4 INSERT SOCIO
                         */
                        $newSocioId = DB::connection('sqlsrv')
                            ->table('socios')
                            ->insertGetId([
                                'dni' => $dni,
                                'nombre_socio' => $nombre,
                                'apellido_1' => $ap1,
                                'apellido_2' => $ap2,
                                'email' => $row->email ?: null,
                                'telefono' => $row->telefono ?: null,
                                'fecha_de_nacimiento' => $this->toDateOrNull($row->fecha_nacimiento),
                                'sexo' => $sexo,
                                'direccion' => $row->direccion ?: null,
                                'poblacion' => $row->poblacion ?: null,
                                'provincia' => $row->provincia ?: null,
                                'codigo_postal' => $row->codigo_postal ?: null,
                                'created_at' => DB::raw("CONVERT(datetime, '{$nowSql}', 120)"),
                                'updated_at' => DB::raw("CONVERT(datetime, '{$nowSql}', 120)"),
                            ], 'id');

                        // Guardamos en memoria para evitar futuros duplicados
                        $dnisExistentes[$dni] = $newSocioId;

                        $this->info("  ✔ Socio creado ID={$newSocioId}");

                        /**
                         * 3.5 RELACIÓN SOCIO ↔ COMERCIALES
                         */
                        if (!empty($row->id_sociedad)) {

                            $codigoSociedad = $sociedadesOrigen[$row->id_sociedad] ?? null;

                            if ($codigoSociedad && isset($sociedadesDestino[$codigoSociedad])) {

                                $idSocDestino = $sociedadesDestino[$codigoSociedad];

                                if (isset($comercialesPorSociedad[$idSocDestino])) {
                                    foreach ($comercialesPorSociedad[$idSocDestino] as $comercial) {
                                        DB::connection('sqlsrv')
                                            ->table('socios_comerciales')
                                            ->insert([
                                                'id_socio' => $newSocioId,
                                                'id_comercial' => $comercial->id,
                                                'created_at' => DB::raw("CONVERT(datetime, '{$nowSql}', 120)"),
                                                'updated_at' => DB::raw("CONVERT(datetime, '{$nowSql}', 120)"),
                                            ]);
                                    }
                                }
                            }
                        }

                    } catch (\Throwable $e) {

                        $this->error("✘ Error socio ORIGEN id={$row->id_socio}");

                        Log::channel('transfer_socios')->error(
                            'ERROR_SOCIO_NO_INSERTADO',
                            [
                                'id_socio_origen' => $row->id_socio,
                                'dni' => $dni,
                                'exception' => $e->getMessage(),
                            ]
                        );
                    }
                }
            });

        $this->info('====================================================');
        $this->info(' MIGRACIÓN DE SOCIOS FINALIZADA');
        $this->info('====================================================');

        return self::SUCCESS;
    }

    /* ============================================================
     * FUNCIONES AUXILIARES
     * ============================================================ */

    private function mapSexo($raw): ?string
    {
        if ($raw === null)
            return null;

        $s = Str::lower(trim((string) $raw));

        if (in_array($s, ['h', 'hombre', 'varon', 'masculino', 'v'], true))
            return 'M';
        if (in_array($s, ['f', 'mujer', 'femenino', 'fem', 'm'], true))
            return 'F';

        return null;
    }

    private function toDateOrNull($val): ?string
    {
        if (!$val || in_array($val, ['0000-00-00', '0000-12-31'], true)) {
            return '1900-01-01';
        }

        try {
            $d = Carbon::parse($val);
            return $d->year < 1753 ? '1900-01-01' : $d->format('Y-m-d');
        } catch (\Throwable $e) {
            return '1900-01-01';
        }
    }

    private function parseNombreCompleto(string $full): array
    {
        $full = trim(preg_replace('/\s+/', ' ', $full));
        if ($full === '')
            return [null, null, null];

        $tokens = explode(' ', $full);
        if (count($tokens) === 1)
            return [$tokens[0], null, null];

        $particles = ['de', 'del', 'la', 'las', 'los', 'san', 'santa', 'da', 'do', 'dos'];

        $takeSurname = function (array $parts) use ($particles) {
            $surname = [];
            while (!empty($parts)) {
                $w = array_pop($parts);
                array_unshift($surname, $w);
                while (!empty($parts)) {
                    $peek = Str::lower($parts[count($parts) - 1]);
                    if (in_array($peek, $particles, true)) {
                        array_unshift($surname, array_pop($parts));
                    } else
                        break;
                }
                break;
            }
            return [implode(' ', $surname), $parts];
        };

        [$ap2, $rest] = $takeSurname($tokens);
        [$ap1, $rest] = $takeSurname($rest);
        $nombre = implode(' ', $rest);

        return [$nombre ?: null, $ap1 ?: null, $ap2 ?: null];
    }
}
