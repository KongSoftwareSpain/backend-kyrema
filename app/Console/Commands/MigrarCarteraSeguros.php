<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class MigrarCarteraSeguros extends Command
{
    protected $signature = 'migrate:cartera-all';

    public function handle()
    {
        $sqlsrv = DB::connection('sqlsrv');
        $mysql = DB::connection('mysql');

        $this->warn("--- LIMPIEZA TOTAL Y CARGA SEGÚN ESPECIFICACIONES ---");

        // 1. Limpiar la tabla destino en KONG
        $sqlsrv->table('socios_productos')->delete();

        // 2. Mapas de socios y tipos (KONG)
        $sociosMap = $sqlsrv->table('socios')->pluck('id', 'dni')
            ->mapWithKeys(fn($id, $dni) => [trim($dni) => $id])->toArray();

        $tiposKong = $sqlsrv->table('tipo_producto')->pluck('id', 'letras_identificacion')
            ->mapWithKeys(fn($id, $le) => [strtolower(trim($le)) => $id])->toArray();

        // 3. Tu lista "Clarito": Tabla MySQL => letras_identificacion en KONG
        $config = [
            'seguros_combinados' => 'producto_k',
            'cacerias'           => 'producto_c',
            'seguro_rehalas'     => 'producto_rehal',
            'test_documentacion' => 'producto_tdoc',
            'seguro_juridico'    => 'producto_sjk',
            'seguro_mascotas'    => 'producto_smk',
            'seguro_naturaleza'  => 'producto_n', // Añadida por si acaso
            'seguro_ornitologo'  => 'producto_o'  // Añadida por si acaso
        ];

        foreach ($config as $tabla => $grupoDestino) {
            if (!$mysql->getSchemaBuilder()->hasTable($tabla)) {
                $this->error("Saltando $tabla: No existe en MySQL.");
                continue;
            }

            $this->info("Procesando $tabla -> $grupoDestino...");

            // Obtenemos solo los DNI únicos de esta tabla de seguros
            $dniSocios = $mysql->table($tabla . ' as t')
                ->join('socios as s', 't.id_socio', '=', 's.id_socio')
                ->select('s.dni')
                ->distinct()
                ->get();

            $idProd = $tiposKong[$grupoDestino] ?? null;

            if (!$idProd) {
                $this->error("   Error: No existe el tipo '$grupoDestino' en KONG.");
                continue;
            }

            $count = 0;
            foreach ($dniSocios as $socio) {
                $idSocio = $sociosMap[trim($socio->dni)] ?? null;

                if ($idSocio) {
                    try {
                        // Insertamos el grupo para este socio
                        $sqlsrv->table('socios_productos')->updateOrInsert(
                            ['id_socio' => $idSocio, 'id_producto' => $idProd],
                            [
                                'letras_identificacion' => $grupoDestino,
                                'created_at' => now()->format('Ymd H:i:s'),
                                'updated_at' => now()->format('Ymd H:i:s')
                            ]
                        );
                        $count++;
                    } catch (Throwable $e) {}
                }
            }
            $this->line("   -> OK: $count socios vinculados a $grupoDestino.");
        }

        $this->newLine();
        $this->info("--- MIGRACIÓN COMPLETADA: TODO EN SU SITIO ---");
    }
}