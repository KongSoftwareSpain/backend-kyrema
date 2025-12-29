<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TransferProductos extends Command
{
    protected $signature = 'transfer:productos
        {--chunk=500 : Tamaño de chunk}
        {--dry-run=0 : Si 1, no inserta (solo simula)}
        {--only-catalogo=0 : Si 1, solo migra tipo_producto (no pivots por sociedad)}
    ';

    protected $description = 'Migra productos Kyrema (MySQL) a KONG (SQL Server) de forma idempotente y no destructiva.';

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (int) $this->option('dry-run') === 1;
        $onlyCatalogo = (int) $this->option('only-catalogo') === 1;

        $log = Log::channel('transfer_productos');

        $mysql = DB::connection('mysql');
        $sqlsrv = DB::connection('sqlsrv');

        $this->info('Transfer Productos: Kyrema(MySQL) -> KONG(SQL Server)');
        $this->info('chunk=' . $chunkSize . ' dry-run=' . ($dryRun ? '1' : '0') . ' only-catalogo=' . ($onlyCatalogo ? '1' : '0'));

        // Precarga: letras_identificacion -> id (tipo_producto)
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

        // Precarga sociedades destino: codigo_sociedad -> id
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

        // Conteo total
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

        $mysql->table('productos')
            ->orderBy('id_producto')
            ->chunk($chunkSize, function ($rows) use ($mysql, $sqlsrv, $log, $dryRun, $onlyCatalogo, &$existingTipoProducto, $sociedadByCodigo, &$stats, $bar) {
                $sqlsrv->beginTransaction();

                try {
                    foreach ($rows as $p) {
                        $businessKey = $this->normalizeKey($p->codigo_producto ?? null);

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

                        if (isset($existingTipoProducto[$businessKey])) {
                            $stats['PRODUCTO_YA_EXISTE']++;
                            $log->info('PRODUCTO_YA_EXISTE', [
                                'business_key' => $businessKey,
                                'destino_tipo_producto_id' => $existingTipoProducto[$businessKey],
                                'origen_id_producto' => $p->id_producto ?? null,
                            ]);
                            // Aun así podemos migrar pivots por sociedad si procede
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

                        // Payload tipo_producto (destino)
                        $nombre = $this->safeString(
                            $p->nombre ?? '',
                            255,
                            $stats,
                            $log,
                            $businessKey,
                            'tipo_producto.nombre'
                        );

                        // Política de estado: usa visible_tienda si quieres (1 = activo), si no, default activo.
                        $estado = $this->safeBit($p->borrado ?? 0) ? 0 : 1;

                        $payload = [
                            'nombre' => $nombre,
                            'letras_identificacion' => $businessKey,
                            'estado' => $estado,
                            'nombre_unificado' => 0, // NOT NULL en destino
                            'created_at' => $this->safeSqlsrvDate($p->created_at ?? null),
                            'updated_at' => $this->safeSqlsrvDate($p->updated_at ?? null),
                        ];

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
                                $newId = (int) $sqlsrv->table('tipo_producto')->insertGetId($payload);
                                $existingTipoProducto[$businessKey] = $newId;

                                $stats['PRODUCTO_CREADO']++;
                                $log->info('PRODUCTO_CREADO', [
                                    'business_key' => $businessKey,
                                    'destino_tipo_producto_id' => $newId,
                                    'origen_id_producto' => $p->id_producto ?? null,
                                ]);

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

        $this->info('Fin.');
        foreach ($stats as $k => $v) {
            $this->line($k . ': ' . $v);
        }

        return self::SUCCESS;
    }

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
        // sociedad_producto (MySQL) tiene id_sociedad y FK a productos.id_producto
        // En destino: tipo_producto_sociedad requiere id_sociedad (SQL) + id_tipo_producto (SQL)

        // Join a sociedades para obtener codigo_sociedad (clave estable para mapear)
        $rows = $mysql->table('sociedad_producto as sp')
            ->join('sociedades as s', 's.id_sociedad', '=', 'sp.id_sociedad')
            ->select(['sp.estado', 's.codigo_sociedad'])
            ->where('sp.id_producto', $idProductoOrigen)
            ->get();

        foreach ($rows as $r) {
            $codigoSociedad = $this->normalizeKey($r->codigo_sociedad ?? null);

            if ($codigoSociedad === null || !isset($sociedadByCodigo[$codigoSociedad])) {
                $stats['PIVOT_SIN_SOCIEDAD_MAP']++;
                $log->warning('WARN_SOCIEDAD_NO_MAPEADA_PARA_PRODUCTO', [
                    'origen_id_producto' => $idProductoOrigen,
                    'codigo_sociedad' => $r->codigo_sociedad ?? null,
                ]);
                continue;
            }

            $sociedadIdDestino = $sociedadByCodigo[$codigoSociedad];

            // Política: si estado=1, insertamos/habilitamos. Si estado=0, no borramos (no destructivo).
            $estado = (int) ($r->estado ?? 0);
            if ($estado !== 1) {
                continue;
            }

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

    private function normalizeKey(?string $v): ?string
    {
        if ($v === null)
            return null;
        $v = trim($v);
        if ($v === '')
            return null;

        // Normalización “ERP”: mayúsculas + sin espacios internos
        $v = mb_strtoupper($v);
        $v = preg_replace('/\s+/', '', $v);

        return $v ?: null;
    }

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

    private function safeSqlsrvDate($value): string
    {
        try {
            if ($value === null) {
                return '1900-01-01 00:00:00';
            }
            $dt = Carbon::parse($value);

            // SQL Server datetime mínimo: 1753-01-01
            if ($dt->year < 1753) {
                return '1900-01-01 00:00:00';
            }

            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return '1900-01-01 00:00:00';
        }
    }

    private function safeBit($v): int
    {
        return ((int) $v) ? 1 : 0;
    }
}
