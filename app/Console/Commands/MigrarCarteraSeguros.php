<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MigrarCarteraSeguros extends Command
{
    protected $signature = 'migrate:cartera-all {--dry-run : Ejecutar sin hacer cambios}';
    protected $description = 'Migra la cartera de seguros desde MySQL a SQL Server manejando esquemas inconsistentes';

    public function handle()
    {
        $sqlsrv = DB::connection('sqlsrv');
        $mysql = DB::connection('mysql');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn("--- MODO DRY-RUN ACTIVADO (No se harán cambios) ---");
        }

        $this->warn("--- MIGRACIÓN DE CARTERA DEFINITIVA ---");

        // 1. Limpieza de tablas generales en KONG
        if (!$dryRun) {
            $sqlsrv->table('socios_productos')->delete();
            // tipo_pago_producto_sociedad se poblará manualmente después
        }

        // 2. Mapas de KONG (DNI -> ID de socio en SQL Server)
        $sociosMap = $sqlsrv->table('socios')->pluck('id', 'dni')
            ->mapWithKeys(fn($id, $dni) => [trim($dni) => $id])->toArray();

        $tiposId = $sqlsrv->table('tipo_producto')->pluck('id', 'letras_identificacion')
            ->mapWithKeys(fn($id, $le) => [strtolower(trim($le)) => $id])->toArray();

        // 3. Configuración Específica de Mapeo con Columnas Personalizadas
        $config = $this->getTableConfigurations();

        $now = now()->format('Ymd H:i:s');

        foreach ($config as $tablaOrigen => $info) {
            $tablaDestino = $info['dest'];
            $colSocio = $info['col'];

            if (!$mysql->getSchemaBuilder()->hasTable($tablaOrigen)) {
                $this->error("Tabla $tablaOrigen no existe en MySQL, saltando...");
                continue;
            }

            $this->info("Procesando $tablaOrigen hacia $tablaDestino...");

            if (!$dryRun) {
                $sqlsrv->table($tablaDestino)->delete();
            }

            // Obtener registros según configuración específica
            $registros = $this->obtenerRegistros($mysql, $tablaOrigen, $info);
            $idProd = $tiposId[$tablaDestino] ?? null;

            if (!$idProd) {
                $this->error("Error: El tipo de producto '$tablaDestino' no existe en tipo_producto.");
                continue;
            }

            $count = 0;
            $errores = 0;

            foreach ($registros as $reg) {
                $idSocioKONG = $sociosMap[trim($reg->dni)] ?? null;

                if (!$idSocioKONG) {
                    $this->warn("  DNI no encontrado en KONG: {$reg->dni}");
                    continue;
                }

                try {
                    if (!$dryRun) {
                        DB::transaction(function () use ($sqlsrv, $idSocioKONG, $idProd, $tablaDestino, $reg, $now, $info) {
                            // A. Tabla de Relación General (ignorar si ya existe)
                            try {
                                $sqlsrv->table('socios_productos')->insert([
                                    'id_socio' => $idSocioKONG,
                                    'id_producto' => $idProd,
                                    'letras_identificacion' => $tablaDestino,
                                    'created_at' => $now,
                                    'updated_at' => $now
                                ]);
                            } catch (Throwable $e) {
                                // Ignorar duplicados
                            }

                            // B. Tabla Específica de Producto con datos mapeados
                            $datosProducto = $this->mapearDatosProducto($reg, $idSocioKONG, $now, $info);
                            $sqlsrv->table($tablaDestino)->insert($datosProducto);


                            // C. Tabla de Pagos de KONG (ignorar si falla por constraints)
                            try {
                                $sqlsrv->table('tipo_pago_producto_sociedad')->insert([
                                    'id_socio' => $idSocioKONG,
                                    'id_producto' => $idProd,
                                    'id_pago' => 1, // Tipo de pago por defecto
                                    'created_at' => $now,
                                    'updated_at' => $now
                                ]);
                            } catch (Throwable $e) {
                                // Ignorar duplicados o errores de constraint
                            }
                        });
                    }
                    $count++;
                } catch (Throwable $e) {
                    $errores++;
                    Log::error("Error migrando DNI {$reg->dni} de $tablaOrigen: " . $e->getMessage());
                    if ($this->output->isVerbose()) {
                        $this->error("  Error en DNI {$reg->dni}: " . $e->getMessage());
                    }
                }
            }

            $this->info("   -> Éxito: $count registros migrados" . ($errores > 0 ? ", $errores errores" : ""));
        }

        $this->info("--- PROCESO FINALIZADO ---");
    }

    /**
     * Configuración de mapeo para cada tabla
     */
    private function getTableConfigurations(): array
    {
        return [
            'seguros_combinados' => [
                'dest' => 'producto_k',
                'col' => 'id_socio',
                'join' => null, // Join directo con socios
                'select' => ['s.dni', 's.nombre_socio', 't.*'] // Columnas a seleccionar
            ],
            'seguro_cacerias' => [
                'dest' => 'producto_c',
                'col' => 'id_socio_asociado',
                'join' => null,
                'select' => ['s.dni', 's.nombre_socio', 't.*']
            ],
            'seguro_rehalas' => [
                'dest' => 'producto_rehal',
                'col' => 'id_socio',
                'join' => null,
                'select' => ['s.dni', 's.nombre_socio', 't.*']
            ],
            'seguro_servicios_juridicos' => [
                'dest' => 'producto_sjk',
                'col' => 'id_socio',
                'join' => null,
                'select' => ['s.dni', 's.nombre_socio', 't.*']
            ],
            'seguro_perros' => [
                'dest' => 'producto_smk',
                'col' => 'id_seguro',
                'join' => 'seguros_combinados', // Requiere join especial
                'select' => ['s.dni', 's.nombre_socio', 't.*']
            ]
        ];
    }

    /**
     * Obtiene registros de MySQL según la configuración
     */
    private function obtenerRegistros($mysql, string $tablaOrigen, array $info)
    {
        $query = $mysql->table($tablaOrigen . ' as t');

        // Manejo de joins especiales
        if ($info['join'] === 'seguros_combinados') {
            $query->join('seguros_combinados as sc', 't.id_seguro', '=', 'sc.id_seguro')
                ->join('socios as s', 'sc.id_socio', '=', 's.id_socio');
        } else {
            $colSocio = $info['col'];
            $query->join('socios as s', "t.$colSocio", '=', 's.id_socio');
        }

        // Seleccionar columnas específicas
        $select = $info['select'] ?? ['s.dni', 's.nombre_socio'];

        return $query->select($select)->distinct()->get();
    }

    /**
     * Mapea los datos del registro MySQL al formato de SQL Server
     * Aquí puedes personalizar el mapeo según las columnas disponibles en cada tabla
     */
    private function mapearDatosProducto($reg, int $idSocioKONG, string $now, array $info): array
    {
        // Datos base comunes a todas las tablas
        $datos = [
            'socio_id' => $idSocioKONG,
            'nombre_socio' => substr($reg->nombre_socio ?? '', 0, 255),
            'dni' => substr($reg->dni ?? '', 0, 20),
            'sociedad_id' => 1,
            'tipo_de_pago_id' => 1,
            'anulado' => 0,
            'created_at' => $now,
            'updated_at' => $now
        ];


        return $datos;
    }
}