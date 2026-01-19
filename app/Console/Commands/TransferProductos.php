<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Comando para migrar el catálogo de productos desde la base de datos MySQL (Kyrema antigua)
 * a la base de datos SQL Server (KONG/Kyrema nueva).
 * 
 * La migración es:
 * 1. Idempotente: Se puede ejecutar varias veces sin duplicar datos (usa 'letras_identificacion' como clave).
 * 2. No destructiva: No borra datos en el destino, solo inserta lo que falta.
 */
class TransferProductos extends Command
{
    /**
     * Definición del comando y sus opciones:
     * --chunk: Cantidad de registros a procesar por lote para optimizar memoria.
     * --dry-run: Permite simular la migración sin realizar cambios reales en la base de datos.
     * --only-catalogo: Si se activa, solo migra los nombres de productos, omitiendo su relación con sociedades.
     */
    protected $signature = 'transfer:productos
        {--chunk=500 : Tamaño de chunk}
        {--dry-run=0 : Si 1, no inserta (solo simula)}
        {--only-catalogo=0 : Si 1, solo migra tipo_producto (no pivots por sociedad)}
    ';

    protected $description = 'Migra productos Kyrema (MySQL) a KONG (SQL Server) de forma idempotente y no destructiva.';

    /**
     * Ejecución principal del comando.
     */
    public function handle(): int
    {
        // 1. Inicialización de opciones y log
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (int) $this->option('dry-run') === 1;
        $onlyCatalogo = (int) $this->option('only-catalogo') === 1;

        $log = Log::channel('transfer_productos');

        // 2. Establecer conexiones a ambas bases de datos
        $mysql = DB::connection('mysql');
        $sqlsrv = DB::connection('sqlsrv');

        $this->info('Transfer Productos: Kyrema(MySQL) -> KONG(SQL Server)');
        $this->info('chunk=' . $chunkSize . ' dry-run=' . ($dryRun ? '1' : '0') . ' only-catalogo=' . ($onlyCatalogo ? '1' : '0'));

        /**
         * 3. PRECARGA DE DATOS PARA IDEMPOTENCIA
         * Cargamos en memoria los productos que YA existen en SQL Server.
         * Usamos 'letras_identificacion' como clave única para comparar.
         */
        $this->info('Precargando tipo_producto existentes (letras_identificacion -> id)...');
        $existingTipoProducto = $sqlsrv->table('tipo_producto')
            ->select(['id', 'letras_identificacion'])
            ->whereNotNull('letras_identificacion')
            ->get()
            ->reduce(function ($acc, $row) {
                $k = $this->normalizeKey($row->letras_identificacion);
                if ($k !== null)
                    $acc[$k] = (int) $row->id;
                return $acc;
            }, []);

        $this->info('Existentes cargados: ' . count($existingTipoProducto));

        /**
         * 4. PRECARGA DE SOCIEDADES
         * Como los IDs de las sociedades pueden variar entre MySQL y SQL Server,
         * mapeamos usando el 'codigo_sociedad' (ej: 'S001') que es un valor estable.
         */
        $sociedadByCodigo = $sqlsrv->table('sociedad')
            ->select(['id', 'codigo_sociedad'])
            ->whereNotNull('codigo_sociedad')
            ->get()
            ->reduce(function ($acc, $row) {
                $k = $this->normalizeKey($row->codigo_sociedad);
                if ($k !== null)
                    $acc[$k] = (int) $row->id;
                return $acc;
            }, []);

        // 5. Configuración de la barra de progreso y estadísticas
        $total = (int) $mysql->table('productos')->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $stats = [
            'PRODUCTO_CREADO' => 0,
            'PRODUCTO_YA_EXISTE' => 0,
            'PRODUCTO_SIN_CLAVE_UNICA' => 0,
            'ERROR_PRODUCTO_NO_INSERTADO' => 0,
            'WARN_LONGITUD_TRUNCADA' => 0,
            'PIVOT_CREADO' => 0,
            'PIVOT_YA_EXISTE' => 0,
            'PIVOT_SIN_SOCIEDAD_MAP' => 0,
        ];

        /**
         * 6. PROCESAMIENTO POR LOTES (CHUNKS)
         * Leemos de MySQL y procesamos uno a uno.
         */
        $mysql->table('productos')
            ->orderBy('id_producto')
            ->chunk($chunkSize, function ($rows) use ($mysql, $sqlsrv, $log, $dryRun, $onlyCatalogo, &$existingTipoProducto, $sociedadByCodigo, &$stats, $bar) {
                $sqlsrv->beginTransaction(); // Usamos transacciones por cada lote para asegurar integridad
    
                try {
                    foreach ($rows as $p) {
                        // El 'codigo_producto' de MySQL será nuestra 'letras_identificacion' en SQL Server
                        $businessKey = $this->normalizeKey($p->codigo_producto ?? null);

                        // Si el producto no tiene código, no podemos migrarlo de forma segura
                        if ($businessKey === null) {
                            $stats['PRODUCTO_SIN_CLAVE_UNICA']++;
                            $log->warning('PRODUCTO_SIN_CLAVE_UNICA', [
                                'origen_id_producto' => $p->id_producto ?? null,
                                'codigo_producto' => $p->codigo_producto ?? null,
                                'nombre' => $p->nombre ?? null,
                            ]);
                            $bar->advance();
                            continue;
                        }

                        /**
                         * 7. CASO: EL PRODUCTO YA EXISTE
                         * Si ya existe, saltamos la creación del producto pero
                         * intentamos sincronizar sus relaciones con sociedades.
                         */
                        if (isset($existingTipoProducto[$businessKey])) {
                            $stats['PRODUCTO_YA_EXISTE']++;
                            $log->info('PRODUCTO_YA_EXISTE', [
                                'business_key' => $businessKey,
                                'destino_tipo_producto_id' => $existingTipoProducto[$businessKey],
                                'origen_id_producto' => $p->id_producto ?? null,
                            ]);

                            if (!$onlyCatalogo) {
                                $this->syncTipoProductoSociedades(
                                    $mysql,
                                    $sqlsrv,
                                    $log,
                                    $dryRun,
                                    $existingTipoProducto[$businessKey],
                                    (int) $p->id_producto,
                                    $sociedadByCodigo,
                                    $stats
                                );
                            }

                            $bar->advance();
                            continue;
                        }

                        /**
                         * 8. PREPARACIÓN DE DATOS (PAYLOAD)
                         * Validamos longitudes para evitar errores de truncado en SQL Server.
                         */
                        $nombre = $this->safeString(
                            $p->nombre ?? '',
                            255,
                            $stats,
                            $log,
                            $businessKey,
                            'tipo_producto.nombre'
                        );

                        // Mapeo de estado: si está borrado en origen, se marca como inactivo (0)
                        $estado = $this->safeBit($p->borrado ?? 0) ? 0 : 1;

                        $payload = [
                            'nombre' => $nombre,
                            'letras_identificacion' => $businessKey,
                            'estado' => $estado,
                            'nombre_unificado' => 0, // Campo obligatorio en destino iniciado a 0
                            'created_at' => $this->safeSqlsrvDate($p->created_at ?? null),
                            'updated_at' => $this->safeSqlsrvDate($p->updated_at ?? null),
                        ];

                        /**
                         * 9. INSERCIÓN DEL PRODUCTO
                         */
                        try {
                            if ($dryRun) {
                                $stats['PRODUCTO_CREADO']++;
                                $log->info('PRODUCTO_CREADO', [
                                    'dry_run' => true,
                                    'business_key' => $businessKey,
                                    'payload' => $payload,
                                    'origen_id_producto' => $p->id_producto ?? null,
                                ]);
                                $existingTipoProducto[$businessKey] = -1;
                            } else {
                                // Insertamos y obtenemos el nuevo ID autogenerado en SQL Server
                                $newId = (int) $sqlsrv->table('tipo_producto')->insertGetId($payload);
                                $existingTipoProducto[$businessKey] = $newId;

                                $stats['PRODUCTO_CREADO']++;
                                $log->info('PRODUCTO_CREADO', [
                                    'business_key' => $businessKey,
                                    'destino_tipo_producto_id' => $newId,
                                    'origen_id_producto' => $p->id_producto ?? null,
                                ]);

                                // 10. Sincronizar relaciones con sociedades para el nuevo producto
                                if (!$onlyCatalogo) {
                                    $this->syncTipoProductoSociedades(
                                        $mysql,
                                        $sqlsrv,
                                        $log,
                                        $dryRun,
                                        $newId,
                                        (int) $p->id_producto,
                                        $sociedadByCodigo,
                                        $stats
                                    );
                                }
                            }
                        } catch (Throwable $e) {
                            $stats['ERROR_PRODUCTO_NO_INSERTADO']++;
                            $log->error('ERROR_PRODUCTO_NO_INSERTADO', [
                                'business_key' => $businessKey,
                                'origen_id_producto' => $p->id_producto ?? null,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        $bar->advance();
                    }

                    // Si es dry-run, siempre hacemos rollback para no guardar nada
                    if ($dryRun) {
                        $sqlsrv->rollBack();
                    } else {
                        $sqlsrv->commit();
                    }
                } catch (Throwable $e) {
                    $sqlsrv->rollBack();
                    $log->error('ERROR_CHUNK_PRODUCTOS', ['error' => $e->getMessage()]);

                    foreach ($rows as $_)
                        $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        // 11. Resumen final por consola
        $this->info('Fin.');
        foreach ($stats as $k => $v) {
            $this->line($k . ': ' . $v);
        }

        return self::SUCCESS;
    }

    /**
     * Sincroniza la tabla pivot 'tipo_producto_sociedad'.
     * Mapea qué sociedades tienen acceso a qué productos.
     */
    private function syncTipoProductoSociedades(
        $mysql,
        $sqlsrv,
        $log,
        bool $dryRun,
        int $tipoProductoIdDestino,
        int $idProductoOrigen,
        array $sociedadByCodigo,
        array &$stats
    ): void {
        /**
         * En MySQL la tabla es 'sociedad_producto'.
         * Hacemos un JOIN con 'sociedades' para obtener el 'codigo_sociedad' (clave de mapeo).
         */
        $rows = $mysql->table('sociedad_producto as sp')
            ->join('sociedades as s', 's.id_sociedad', '=', 'sp.id_sociedad')
            ->select(['sp.estado', 's.codigo_sociedad'])
            ->where('sp.id_producto', $idProductoOrigen)
            ->get();

        foreach ($rows as $r) {
            $codigoSociedad = $this->normalizeKey($r->codigo_sociedad ?? null);

            // Verificamos si la sociedad existe en el destino mediante su código
            if ($codigoSociedad === null || !isset($sociedadByCodigo[$codigoSociedad])) {
                $stats['PIVOT_SIN_SOCIEDAD_MAP']++;
                $log->warning('WARN_SOCIEDAD_NO_MAPEADA_PARA_PRODUCTO', [
                    'origen_id_producto' => $idProductoOrigen,
                    'codigo_sociedad' => $r->codigo_sociedad ?? null,
                ]);
                continue;
            }

            $sociedadIdDestino = $sociedadByCodigo[$codigoSociedad];

            /**
             * Lógica de transferencia: 
             * Solo migramos si en origen está activo (estado=1).
             * No borramos nada en destino.
             */
            $estado = (int) ($r->estado ?? 0);
            if ($estado !== 1) {
                continue;
            }

            // Evitar duplicados en la tabla pivot
            $exists = $sqlsrv->table('tipo_producto_sociedad')
                ->where('id_sociedad', $sociedadIdDestino)
                ->where('id_tipo_producto', $tipoProductoIdDestino)
                ->exists();

            if ($exists) {
                $stats['PIVOT_YA_EXISTE']++;
                return;
            }

            $payload = [
                'id_sociedad' => $sociedadIdDestino,
                'id_tipo_producto' => $tipoProductoIdDestino,
                'created_at' => $this->safeSqlsrvDate(null),
                'updated_at' => $this->safeSqlsrvDate(null),
            ];

            if ($dryRun) {
                $stats['PIVOT_CREADO']++;
                $log->info('PIVOT_CREADO', ['dry_run' => true] + $payload);
            } else {
                $sqlsrv->table('tipo_producto_sociedad')->insert($payload);
                $stats['PIVOT_CREADO']++;
                $log->info('PIVOT_CREADO', $payload);
            }
        }
    }

    /**
     * Normaliza una clave (código) para comparaciones consistentes.
     * Convierte a MAYÚSCULAS y elimina espacios.
     */
    private function normalizeKey(?string $v): ?string
    {
        if ($v === null)
            return null;
        $v = trim($v);
        if ($v === '')
            return null;

        $v = mb_strtoupper($v);
        $v = preg_replace('/\s+/', '', $v);

        return $v ?: null;
    }

    /**
     * Asegura que un string no supere el tamaño máximo de la columna.
     * Si es más largo, lo trunca y lanza una advertencia en el log.
     */
    private function safeString(?string $v, int $max, array &$stats, $log, string $businessKey, string $campo): ?string
    {
        if ($v === null)
            return null;

        $v = trim($v);
        if ($v === '')
            return null;

        if (mb_strlen($v) > $max) {
            $stats['WARN_LONGITUD_TRUNCADA']++;
            $log->warning('WARN_LONGITUD_TRUNCADA', [
                'business_key' => $businessKey,
                'campo' => $campo,
                'max' => $max,
                'len' => mb_strlen($v),
            ]);
            $v = mb_substr($v, 0, $max);
        }

        return $v;
    }

    /**
     * Ajusta fechas para que sean compatibles con SQL Server.
     * SQL Server no acepta fechas anteriores a 1753.
     */
    private function safeSqlsrvDate($value): string
    {
        try {
            if ($value === null) {
                return '1900-01-01 00:00:00';
            }
            $dt = Carbon::parse($value);

            // SQL Server datetime mínimo: 1753-01-01. Evitamos el error "out of range".
            if ($dt->year < 1753) {
                return '1900-01-01 00:00:00';
            }

            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return '1900-01-01 00:00:00';
        }
    }

    /**
     * Convierte cualquier valor a un BIT (0 o 1).
     */
    private function safeBit($v): int
    {
        return ((int) $v) ? 1 : 0;
    }
}

