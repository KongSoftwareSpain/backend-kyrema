<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * ============================================================================
 * COMANDO: TransferSocios
 * ============================================================================
 *
 * DESCRIPCIÓN:
 * Este comando realiza el trasvase de socios desde la base de datos MySQL
 * (Kyrema Original) hacia la base de datos SQL Server (Kyrema KONG).
 *
 * ESPECIFICACIONES CRÍTICAS:
 * 1. IDEMPOTENCIA: El comando puede ejecutarse múltiples veces. Si detecta
 *    que un socio ya existe (por DNI), lo omite para evitar duplicados.
 * 2. NO DESTRUCTIVO: No borra ni modifica datos en la base de datos origen.
 * 3. VALIDACIÓN: Se requiere obligatoriamente que el socio tenga DNI.
 * 4. VÍNCULOS: Además de crear el socio, genera automáticamente la relación
 *    con sus comerciales correspondientes en la tabla pivot 'socios_comerciales'.
 * 5. NORMALIZACIÓN: Limpia nombres, divide apellidos y mapea el campo sexo.
 *
 * MODO DE USO:
 * php artisan transfer:socios {--chunk=500}
 * ============================================================================
 */
class TransferSocios extends Command
{
    /**
     * @var string La firma (comando) que se escribe en la terminal.
     */
    protected $signature = 'transfer:socios {--chunk=500}';

    /**
     * @var string Breve descripción que aparece en 'php artisan list'.
     */
    protected $description = 'Migración IDempotente de socios Kyrema → KONG (deduplicada por DNI)';

    /**
     * Ejecuta la lógica del comando.
     */
    public function handle()
    {
        // --------------------------------------------------------------------
        // CONFIGURACIÓN INICIAL
        // --------------------------------------------------------------------
        // Definimos el tamaño del bloque para no saturar la memoria RAM.
        $chunk = (int) $this->option('chunk');
        // Fecha actual para los campos created_at/updated_at.
        $nowSql = Carbon::now()->format('Y-m-d H:i:s');

        $this->info('====================================================');
        $this->info(' INICIO MIGRACIÓN DE SOCIOS (IDEMPOTENTE)');
        $this->info('====================================================');

        /**
         * --------------------------------------------------------------------
         * 1. PRE-CHECK DE CONEXIONES Y TABLAS
         * --------------------------------------------------------------------
         * Antes de empezar, verificamos que ambas bases de datos responden
         * y que las tablas necesarias existen. Si algo falla aquí, abortamos.
         */
        try {
            DB::connection('mysql')->table('socios')->count();
            DB::connection('sqlsrv')->table('socios')->count();
            DB::connection('sqlsrv')->table('sociedad')->count();
            DB::connection('sqlsrv')->table('comercial')->count();

            $this->info('✔ Conexiones y tablas verificadas');
        } catch (\Throwable $e) {
            $this->error('✘ Error de conexión o tablas faltantes');
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        /**
         * --------------------------------------------------------------------
         * 2. PRE-CARGA DE ESTRUCTURA COMERCIAL
         * --------------------------------------------------------------------
         * Para optimizar, cargamos todos los comerciales en memoria y los
         * agrupamos por 'id_sociedad'. Así evitamos miles de queries dentro
         * del bucle principal.
         */
        $comercialesPorSociedad = DB::connection('sqlsrv')
            ->table('comercial')
            ->select('id', 'id_sociedad')
            ->get()
            ->groupBy('id_sociedad');

        $this->info(
            '✔ Comerciales precargados. Sociedades con estructura: ' .
            $comercialesPorSociedad->count()
        );

        /**
         * --------------------------------------------------------------------
         * 3. BUCLE PRINCIPAL (CHUNKING)
         * --------------------------------------------------------------------
         * Leemos de MySQL en bloques (chunks) para ser eficientes con la memoria.
         */
        DB::connection('mysql')->table('socios')
            ->orderBy('id_socio')
            ->chunk($chunk, function ($rows) use ($nowSql, $comercialesPorSociedad) {

                foreach ($rows as $row) {

                    $this->line("Procesando socio ORIGEN id={$row->id_socio} | DNI={$row->dni}");

                    try {
                        /**
                         * 3.1 VALIDACIÓN DE DNI
                         * El DNI es nuestra clave única de negocio. Si no hay DNI,
                         * no podemos garantizar la veracidad del registro ni su deduplicación.
                         */
                        $dni = trim((string) $row->dni);

                        if ($dni === '') {
                            $this->warn('  ⚠ Socio sin DNI, se omite (No podemos asegurar unicidad)');
                            Log::channel('transfer_socios')->warning('SOCIO_SIN_DNI', ['id_socio_origen' => $row->id_socio]);
                            continue;
                        }

                        /**
                         * 3.2 CHEQUEO DE DUPLICADOS (IDEMPOTENCIA)
                         * Antes de insertar, miramos en SQL Server si ese DNI ya vive allí.
                         */
                        $existingId = DB::connection('sqlsrv')
                            ->table('socios')
                            ->where('dni', $dni)
                            ->value('id');

                        if ($existingId) {
                            $this->warn("  ⚠ Ya existe socio (id={$existingId}), se omite");
                            Log::channel('transfer_socios')->info('SOCIO_YA_EXISTE', [
                                'dni' => $dni,
                                'id_socio_destino' => $existingId,
                            ]);
                            continue;
                        }

                        /**
                         * 3.3 LIMPIEZA Y TRANSFORMACIÓN DE DATOS (NORMALIZACIÓN)
                         * - El nombre completo se separa en Nombre, Apellido 1 y Apellido 2.
                         * - El sexo se estandariza a M/F.
                         * - Las fechas se validan para SQL Server (evitar errores de desbordamiento).
                         */
                        [$nombre, $ap1, $ap2] = $this->parseNombreCompleto((string) ($row->nombre_socio ?? ''));
                        $sexo = $this->mapSexo($row->sexo);

                        /**
                         * 3.4 INSERCIÓN DEL SOCIO EN DESTINO
                         * Insertamos el registro principal.
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
                                // Forzamos formato datetime compatible con SQL Server
                                'created_at' => DB::raw("CONVERT(datetime, '{$nowSql}', 120)"),
                                'updated_at' => DB::raw("CONVERT(datetime, '{$nowSql}', 120)"),
                            ], 'id');

                        $this->info("  ✔ Socio creado ID={$newSocioId}");

                        /**
                         * 3.5 ASIGNACIÓN DE COMERCIALES (PIVOT)
                         * En Kyrema, el socio pertenece a una 'sociedad'.
                         * En KONG, el socio debe vincularse con TODOS los comerciales
                         * asignados a esa misma sociedad.
                         */
                        if (!empty($row->id_sociedad)) {
                            // 1. Buscamos el código de la sociedad en MySQL (fuente de verdad)
                            $codigoSociedad = DB::connection('mysql')
                                ->table('sociedades')
                                ->where('id_sociedad', $row->id_sociedad)
                                ->value('codigo_sociedad');

                            if ($codigoSociedad) {
                                // 2. Localizamos la sociedad equivalente en SQL Server
                                $idSociedadDestino = DB::connection('sqlsrv')
                                    ->table('sociedad')
                                    ->where('codigo_sociedad', $codigoSociedad)
                                    ->value('id');

                                // 3. Si existe y tenemos comerciales para ella, creamos los vínculos
                                if ($idSociedadDestino && isset($comercialesPorSociedad[$idSociedadDestino])) {
                                    foreach ($comercialesPorSociedad[$idSociedadDestino] as $comercial) {
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
                        /**
                         * CONTROL DE ERRORES POR SOCIO
                         * Si falla un registro, lanzamos error por consola y log,
                         * pero el script CONTINÚA con el siguiente.
                         */
                        $this->error("✘ Error fatal insertando socio ORIGEN id={$row->id_socio}");
                        Log::channel('transfer_socios')->error('ERROR_SOCIO_NO_INSERTADO', [
                            'id_socio_origen' => $row->id_socio,
                            'dni' => $row->dni,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info('====================================================');
        $this->info(' MIGRACIÓN DE SOCIOS FINALIZADA CORRECTAMENTE');
        $this->info('====================================================');

        return self::SUCCESS;
    }

    /**
     * ============================================================
     * FUNCIONES AUXILIARES DE FORMATEO (ATOPE)
     * ============================================================
     */

    /**
     * Mapea el campo de sexo a los valores aceptables por KONG (M/F).
     * @param mixed $raw Valor bruto de la DB origen.
     * @return string|null 'M', 'F' o null.
     */
    private function mapSexo($raw): ?string
    {
        if ($raw === null)
            return null;

        $s = Str::lower(trim((string) $raw));
        // Mapeo exhaustivo de variaciones comunes
        if (in_array($s, ['h', 'hombre', 'varon', 'masculino', 'v'], true))
            return 'M';
        if (in_array($s, ['f', 'mujer', 'femenino', 'fem', 'm'], true))
            return 'F';

        return null;
    }

    /**
     * Valida y formatea fechas para evitar el error '1753' de SQL Server.
     * SQL Server no admite fechas por debajo de 1753-01-01 en campos datetime.
     * @param mixed $val Fecha de origen.
     * @return string Fecha en formato Y-m-d.
     */
    private function toDateOrNull($val): ?string
    {
        // Si no hay fecha o es el "cero" de MySQL, devolvemos un valor base seguro
        if (!$val || $val === '0000-00-00' || $val === '0000-12-31')
            return '1900-01-01';

        try {
            $d = Carbon::parse($val);
            // Comprobación de seguridad para SQL Server (rango mínimo)
            return $d->year < 1753 ? '1900-01-01' : $d->format('Y-m-d');
        } catch (\Throwable $e) {
            return '1900-01-01'; // Ante la duda, valor neutro
        }
    }

    /**
     * Algoritmo avanzado de parsing de nombres completos.
     * Intenta separar Nombre, Apellido 1 y Apellido 2 respetando
     * las partículas de apellidos españoles (de, del, la, etc.).
     *
     * @param string $full Nombre completo.
     * @return array [nombre, apellido1, apellido2]
     */
    private function parseNombreCompleto(string $full): array
    {
        // 1. Limpieza inicial de espacios múltiples
        $full = trim(preg_replace('/\s+/', ' ', $full));
        if ($full === '')
            return [null, null, null];

        $tokens = explode(' ', $full);
        // Caso simple: un solo token es el nombre
        if (count($tokens) === 1)
            return [$tokens[0], null, null];

        // Partículas que suelen pertenecer al apellido
        $particles = ['de', 'del', 'la', 'las', 'los', 'san', 'santa', 'da', 'do', 'dos'];

        /**
         * Lógica recursiva interna: toma elementos desde el final
         * y verifica si el anterior es una partícula para "pegarla".
         */
        $takeSurname = function (array $parts) use ($particles) {
            $surname = [];
            while (!empty($parts)) {
                $w = array_pop($parts);
                array_unshift($surname, $w);
                // Si el token anterior es una partícula, también lo cogemos para este apellido
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

        // Extraemos Apellido 2 (último)
        [$ap2, $rest] = $takeSurname($tokens);
        // Extraemos Apellido 1 (penúltimo)
        [$ap1, $rest] = $takeSurname($rest);
        // Lo que sobra es el nombre
        $nombre = implode(' ', $rest);

        return [$nombre ?: null, $ap1 ?: null, $ap2 ?: null];
    }
}



