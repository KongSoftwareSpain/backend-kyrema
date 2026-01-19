<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncTipoProductoSociedad extends Command
{
    /**
     * Comando para sincronizar SOLO la tabla pivot tipo_producto_sociedad
     * desde MySQL (Kyrema antigua) a SQL Server (KONG/Kyrema nueva).
     * 
     * Este comando asume que los productos (tipo_producto) ya existen en SQL Server.
     * Solo crea las relaciones faltantes en tipo_producto_sociedad.
     */
    protected $signature = 'sync:tipo-producto-sociedad
        {--dry-run=0 : Si 1, no inserta (solo simula)}
    ';

    protected $description = 'Sincroniza SOLO la tabla pivot tipo_producto_sociedad desde MySQL a SQL Server';

    public function handle(): int
    {
        $dryRun = (int) $this->option('dry-run') === 1;
        $log = Log::channel('single'); // Usar canal 'single' que escribe en laravel.log

        // Conexiones
        $mysql = DB::connection('mysql');
        $sqlsrv = DB::connection('sqlsrv');

        $this->info('=== Sync tipo_producto_sociedad: MySQL -> SQL Server ===');
        $this->info('dry-run=' . ($dryRun ? '1 (SIMULACIÓN)' : '0 (REAL)'));
        $this->newLine();

        // 1. Precargar mapeo de productos: letras_identificacion -> id (SQL Server)
        $this->info('📦 Paso 1: Precargando productos de SQL Server...');
        $tipoProductoMap = [];
        $productosPadre = []; // Mapeo de hijo -> padre
        $productos = $sqlsrv->table('tipo_producto')
            ->select('id', 'letras_identificacion', 'nombre', 'padre_id')
            ->whereNotNull('letras_identificacion')
            ->get();

        foreach ($productos as $p) {
            $key = strtoupper(trim(str_replace(' ', '', $p->letras_identificacion ?? '')));
            if ($key) {
                $tipoProductoMap[$key] = [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'padre_id' => $p->padre_id,
                ];

                // Si tiene padre, guardar la relación
                if ($p->padre_id) {
                    $productosPadre[$p->id] = $p->padre_id;
                }
            }
        }
        $this->info("   ✓ Productos cargados: " . count($tipoProductoMap));
        $this->info("   ✓ Productos con padre: " . count($productosPadre));
        $log->info("Productos cargados de SQL Server: " . count($tipoProductoMap));
        $this->newLine();

        // 2. Precargar mapeo de sociedades: codigo_sociedad -> id (SQL Server)
        $this->info('🏢 Paso 2: Precargando sociedades de SQL Server...');
        $sociedadMap = [];
        $sociedades = $sqlsrv->table('sociedad')
            ->select('id', 'codigo_sociedad', 'nombre')
            ->whereNotNull('codigo_sociedad')
            ->get();

        foreach ($sociedades as $s) {
            $key = strtoupper(trim($s->codigo_sociedad ?? ''));
            if ($key) {
                $sociedadMap[$key] = [
                    'id' => $s->id,
                    'nombre' => $s->nombre,
                ];
            }
        }
        $this->info("   ✓ Sociedades cargadas: " . count($sociedadMap));
        $log->info("Sociedades cargadas de SQL Server: " . count($sociedadMap));
        $this->newLine();

        // 3. Precargar pivots existentes en SQL Server
        $this->info('🔗 Paso 3: Precargando pivots existentes...');
        $existingPivots = [];
        $pivots = $sqlsrv->table('tipo_producto_sociedad')
            ->select('id_tipo_producto', 'id_sociedad')
            ->get();

        foreach ($pivots as $pivot) {
            $existingPivots[$pivot->id_tipo_producto . '_' . $pivot->id_sociedad] = true;
        }
        $this->info("   ✓ Pivots existentes: " . count($existingPivots));
        $log->info("Pivots existentes en SQL Server: " . count($existingPivots));
        $this->newLine();

        // 4. Leer relaciones de MySQL
        $this->info('📖 Paso 4: Leyendo relaciones de MySQL...');
        $relaciones = $mysql->table('sociedad_producto as sp')
            ->join('productos as p', 'p.id_producto', '=', 'sp.id_producto')
            ->join('sociedades as s', 's.id_sociedad', '=', 'sp.id_sociedad')
            ->select(
                'p.id_producto as mysql_producto_id',
                'p.codigo_producto',
                'p.nombre as nombre_producto',
                's.id_sociedad as mysql_sociedad_id',
                's.codigo_sociedad',
                's.nombre as nombre_sociedad',
                'sp.estado'
            )
            ->orderBy('p.codigo_producto')
            ->orderBy('s.codigo_sociedad')
            ->get();

        $this->info("   ✓ Relaciones encontradas en MySQL: " . count($relaciones));
        $log->info("Relaciones encontradas en MySQL: " . count($relaciones));
        $this->newLine();

        // Estadísticas
        $stats = [
            'PROCESADAS' => 0,
            'PIVOT_CREADO' => 0,
            'PIVOT_YA_EXISTE' => 0,
            'PRODUCTO_NO_ENCONTRADO' => 0,
            'SOCIEDAD_NO_ENCONTRADA' => 0,
            'RELACION_INACTIVA' => 0,
        ];

        $this->info('🔄 Paso 5: Procesando relaciones...');
        $bar = $this->output->createProgressBar(count($relaciones));
        $bar->start();

        foreach ($relaciones as $rel) {
            $stats['PROCESADAS']++;

            // Solo procesar relaciones activas
            if ($rel->estado != 1) {
                $stats['RELACION_INACTIVA']++;
                $log->debug("Relación inactiva (estado={$rel->estado}): Producto '{$rel->codigo_producto}' ({$rel->nombre_producto}) - Sociedad '{$rel->codigo_sociedad}' ({$rel->nombre_sociedad})");
                $bar->advance();
                continue;
            }

            // Normalizar claves
            $productoKey = 'PRODUCTO_' . strtoupper(trim(str_replace(' ', '', $rel->codigo_producto ?? '')));
            $sociedadKey = strtoupper(trim($rel->codigo_sociedad ?? ''));

            // Log detallado de la relación que se está procesando
            $log->info("Procesando relación #{$stats['PROCESADAS']}: MySQL Producto ID={$rel->mysql_producto_id} '{$rel->codigo_producto}' -> MySQL Sociedad ID={$rel->mysql_sociedad_id} '{$rel->codigo_sociedad}'");

            // Buscar IDs en SQL Server
            if (!isset($tipoProductoMap[$productoKey])) {
                $stats['PRODUCTO_NO_ENCONTRADO']++;
                $msg = "❌ Producto NO encontrado en SQL Server: '{$rel->codigo_producto}' (normalizado: '{$productoKey}') - Nombre: '{$rel->nombre_producto}'";
                $this->newLine();
                $this->warn($msg);
                $log->warning($msg);
                $bar->advance();
                continue;
            }

            if (!isset($sociedadMap[$sociedadKey])) {
                $stats['SOCIEDAD_NO_ENCONTRADA']++;
                $msg = "❌ Sociedad NO encontrada en SQL Server: '{$rel->codigo_sociedad}' (normalizado: '{$sociedadKey}') - Nombre: '{$rel->nombre_sociedad}'";
                $this->newLine();
                $this->warn($msg);
                $log->warning($msg);
                $bar->advance();
                continue;
            }

            $tipoProductoId = $tipoProductoMap[$productoKey]['id'];
            $tipoProductoNombre = $tipoProductoMap[$productoKey]['nombre'];
            $sociedadId = $sociedadMap[$sociedadKey]['id'];
            $sociedadNombre = $sociedadMap[$sociedadKey]['nombre'];
            $pivotKey = $tipoProductoId . '_' . $sociedadId;

            // Log de mapeo exitoso
            $log->info("   ✓ Mapeo: Producto '{$rel->codigo_producto}' -> SQL Server ID={$tipoProductoId} '{$tipoProductoNombre}'");
            $log->info("   ✓ Mapeo: Sociedad '{$rel->codigo_sociedad}' -> SQL Server ID={$sociedadId} '{$sociedadNombre}'");

            // --- LÓGICA DE PADRE (Ejecutar SIEMPRE antes de verificar el hijo) ---
            $padreId = $tipoProductoMap[$productoKey]['padre_id'] ?? null;
            if ($padreId) {
                $padrePivotKey = $padreId . '_' . $sociedadId;

                if (!isset($existingPivots[$padrePivotKey])) {
                    $log->info("   👨‍👦 Producto tiene padre (ID={$padreId}), creando pivot para el padre también");

                    if (!$dryRun) {
                        try {
                            $sqlsrv->table('tipo_producto_sociedad')->insert([
                                'id_tipo_producto' => $padreId,
                                'id_sociedad' => $sociedadId,
                                'created_at' => DB::raw('GETDATE()'),
                                'updated_at' => DB::raw('GETDATE()'),
                            ]);
                            $log->info("   ✅ Pivot del padre creado exitosamente");
                            $stats['PIVOT_CREADO']++;
                            $existingPivots[$padrePivotKey] = true;
                        } catch (\Exception $e) {
                            $log->error("   ❌ Error al crear pivot del padre: " . $e->getMessage());
                            $this->newLine();
                            $this->error("Error al crear pivot del padre: " . $e->getMessage());
                        }
                    } else {
                        $log->info("   🔸 DRY-RUN: Pivot del padre no insertado (simulación)");
                        $stats['PIVOT_CREADO']++;
                        $existingPivots[$padrePivotKey] = true; // Marcar como existente para evitar duplicados en esta ejecución
                    }
                } else {
                    $log->debug("   ⚠ Pivot del padre YA EXISTE");
                }
            }
            // -------------------------------------------------------------------

            // Verificar si el HIJO ya existe
            if (isset($existingPivots[$pivotKey])) {
                $stats['PIVOT_YA_EXISTE']++;
                $log->debug("   ⚠ Pivot YA EXISTE: id_tipo_producto={$tipoProductoId}, id_sociedad={$sociedadId}");
                $bar->advance();
                continue;
            }

            // Crear pivot HIJO
            $log->info("   ➕ CREANDO pivot: id_tipo_producto={$tipoProductoId} ('{$tipoProductoNombre}'), id_sociedad={$sociedadId} ('{$sociedadNombre}')");

            if (!$dryRun) {
                try {
                    $sqlsrv->table('tipo_producto_sociedad')->insert([
                        'id_tipo_producto' => $tipoProductoId,
                        'id_sociedad' => $sociedadId,
                        'created_at' => DB::raw('GETDATE()'),
                        'updated_at' => DB::raw('GETDATE()'),
                    ]);
                    $log->info("   ✅ Pivot creado exitosamente");
                } catch (\Exception $e) {
                    $log->error("   ❌ Error al crear pivot: " . $e->getMessage());
                    $this->newLine();
                    $this->error("Error al crear pivot: " . $e->getMessage());
                }
            } else {
                $log->info("   🔸 DRY-RUN: No se insertó (simulación)");
            }

            $stats['PIVOT_CREADO']++;
            $existingPivots[$pivotKey] = true; // Evitar duplicados en esta ejecución

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Mostrar estadísticas
        $this->info('=== RESUMEN FINAL ===');
        foreach ($stats as $key => $value) {
            $icon = match ($key) {
                'PIVOT_CREADO' => '✅',
                'PIVOT_YA_EXISTE' => '⚠️',
                'PRODUCTO_NO_ENCONTRADO' => '❌',
                'SOCIEDAD_NO_ENCONTRADA' => '❌',
                'RELACION_INACTIVA' => '⏭️',
                default => '📊',
            };
            $this->info("$icon $key: $value");
            $log->info("ESTADÍSTICA: $key = $value");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('⚠️  DRY-RUN: No se insertó nada en la base de datos.');
        } else {
            $this->newLine();
            $this->info('✅ Sincronización completada.');
        }

        return self::SUCCESS;
    }
}
