<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResuelveMapeosMigracion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TransferSocios extends Command
{
    use ResuelveMapeosMigracion;

    protected $signature = 'transfer:socios {--chunk=500}
                            {--desde= : Solo socios con fecha_alta >= esta fecha (ej: 2026-06-03, fecha de la última migración)}
                            {--dry-run : No inserta nada, solo cuenta y lista lo que se crearía}
                            {--incluir-dni-dudosos : Crear también socios con DNI de formato no estándar (CIF de entidades, pasaportes, números sueltos)}';
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

        // 2.1 DNIs ya existentes en destino (idempotencia sin queries).
        // La clave va en mayúsculas y sin espacios en los extremos: un DNI "12345678a" de la antigua
        // y "12345678A" de la nueva son el mismo socio.
        $dnisExistentes = [];
        foreach (DB::connection('sqlsrv')->table('socios')->select('id', 'dni')->get() as $socioNuevo) {
            $clave = $this->normalizarDni($socioNuevo->dni);
            if ($clave !== '' && !isset($dnisExistentes[$clave])) {
                $dnisExistentes[$clave] = $socioNuevo->id;
            }
        }

        $this->info('✔ DNIs existentes cargados: ' . count($dnisExistentes));

        // 2.2 Sociedades: id antiguo → id nuevo, por código y, si no hay código en común, por nombre sin acentos
        $mapeoSociedades = $this->mapearSociedadesPorCodigoYNombre();

        $this->info('✔ Sociedades mapeadas antigua → nueva: ' . count($mapeoSociedades));

        // 2.3 Comerciales agrupados por sociedad DESTINO
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
        $dryRun = (bool) $this->option('dry-run');
        $incluirDudosos = (bool) $this->option('incluir-dni-dudosos');
        $creables = 0;
        $dudosos = [];
        $stats = ['creados' => 0, 'ya_existian' => 0, 'sin_dni' => 0, 'sin_comerciales' => 0, 'errores' => 0];

        $origen = DB::connection('mysql')
            ->table('socios')
            ->where('borrado', 0);

        if ($this->option('desde')) {
            $origen->where('fecha_alta', '>=', $this->option('desde') . ' 00:00:00');
            $this->info('✔ Solo socios con fecha_alta >= ' . $this->option('desde'));
        }

        $origen
            ->orderBy('id_socio')
            ->chunk($chunk, function ($rows) use (&$dnisExistentes, $mapeoSociedades, $comercialesPorSociedad, $nowSql, $dryRun, $incluirDudosos, &$creables, &$dudosos, &$stats) {

                foreach ($rows as $row) {

                    $dni = $this->normalizarDni($row->dni);
                    $this->line("Procesando socio ORIGEN id={$row->id_socio}");

                    try {
                        /**
                         * 3.1 VALIDACIÓN DE DNI
                         */
                        if ($dni === '') {
                            $stats['sin_dni']++;
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
                            $stats['ya_existian']++;
                            $this->warn("  ⚠ Ya existe socio (id={$dnisExistentes[$dni]}), se omite");
                            continue;
                        }

                        /**
                         * 3.2b DNI DE FORMATO NO ESTÁNDAR (CIF, pasaportes, números sueltos)
                         */
                        if (!$incluirDudosos && !preg_match('/^([0-9]{8}|[XYZ][0-9]{7})[A-Z]$/', $dni)) {
                            $dudosos[] = [$row->id_socio, $dni, $row->nombre_socio];
                            $this->warn('  ⚠ DNI de formato no estándar, se omite');
                            Log::channel('transfer_socios')->warning(
                                'SOCIO_DNI_DUDOSO',
                                ['id_socio_origen' => $row->id_socio]
                            );
                            continue;
                        }

                        if ($dryRun) {
                            $creables++;
                            $dnisExistentes[$dni] = 0;
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
                         * 3.4 INSERT SOCIO + 3.5 RELACIÓN SOCIO ↔ COMERCIALES
                         * En una sola transacción: si falla la relación no queda un socio huérfano
                         * que luego se salte por existir ya su DNI.
                         */
                        $newSocioId = DB::connection('sqlsrv')->transaction(function () use ($row, $dni, $nombre, $ap1, $ap2, $sexo, $nowSql, $mapeoSociedades, $comercialesPorSociedad, &$stats) {

                            $id = DB::connection('sqlsrv')
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

                            $idSocDestino = !empty($row->id_sociedad) ? ($mapeoSociedades[$row->id_sociedad] ?? null) : null;
                            $comerciales = $idSocDestino ? ($comercialesPorSociedad[$idSocDestino] ?? null) : null;

                            if ($comerciales && count($comerciales) > 0) {
                                foreach ($comerciales as $comercial) {
                                    DB::connection('sqlsrv')
                                        ->table('socios_comerciales')
                                        ->insert([
                                            'id_socio' => $id,
                                            'id_comercial' => $comercial->id,
                                            'created_at' => DB::raw("CONVERT(datetime, '{$nowSql}', 120)"),
                                            'updated_at' => DB::raw("CONVERT(datetime, '{$nowSql}', 120)"),
                                        ]);
                                }
                            } else {
                                $stats['sin_comerciales']++;
                                Log::channel('transfer_socios')->warning(
                                    'SOCIO_SIN_COMERCIALES',
                                    ['id_socio_origen' => $row->id_socio, 'id_sociedad_origen' => $row->id_sociedad]
                                );
                            }

                            return $id;
                        });

                        // Guardamos en memoria para evitar futuros duplicados
                        $dnisExistentes[$dni] = $newSocioId;
                        $stats['creados']++;

                        $this->info("  ✔ Socio creado ID={$newSocioId}");

                    } catch (\Throwable $e) {

                        $stats['errores']++;
                        $this->error("✘ Error socio ORIGEN id={$row->id_socio}");

                        Log::channel('transfer_socios')->error(
                            'ERROR_SOCIO_NO_INSERTADO',
                            [
                                'id_socio_origen' => $row->id_socio,
                                'exception' => $e->getMessage(),
                            ]
                        );
                    }
                }
            });

        if ($dryRun) {
            $this->info("DRY-RUN: se crearían {$creables} socios.");
        }
        if (!empty($dudosos)) {
            $this->warn(count($dudosos) . ' socios omitidos por DNI de formato no estándar (--incluir-dni-dudosos para crearlos):');
            $this->table(['id_socio', 'dni', 'nombre'], $dudosos);
        }

        $this->info('====================================================');
        $this->info(' MIGRACIÓN DE SOCIOS FINALIZADA');
        $this->table(['Concepto', 'Cantidad'], [
            ['Socios creados' . ($dryRun ? ' (simulación: ver arriba)' : ''), $dryRun ? 0 : $stats['creados']],
            ['Ya existían (se saltan)', $stats['ya_existian']],
            ['Sin DNI (omitidos)', $stats['sin_dni']],
            ['DNI no estándar (omitidos)', count($dudosos)],
            ['Creados SIN comerciales (sociedad sin mapear o sin comerciales)', $stats['sin_comerciales']],
            ['Errores', $stats['errores']],
        ]);
        $this->info('====================================================');

        return $stats['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /* ============================================================
     * FUNCIONES AUXILIARES
     * ============================================================ */

    private function normalizarDni($valor): string
    {
        return strtoupper(trim((string) $valor));
    }

    private function mapSexo($raw): ?string
    {
        if ($raw === null)
            return null;

        $s = Str::lower(trim((string) $raw));

        if (in_array($s, ['h', 'hombre', 'varon', 'varón', 'masculino', 'v'], true))
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
