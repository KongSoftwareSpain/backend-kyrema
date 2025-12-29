<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RepairSociosComerciales extends Command
{
    protected $signature = 'repair:socios-comerciales {--chunk=500}';
    protected $description = 'Repara socios_comerciales con id_comercial=1 asignando el comercial correcto';

    public function handle()
    {
        $chunk = (int) $this->option('chunk');
        $nowSql = Carbon::now()->format('Y-m-d H:i:s');

        $this->info('====================================================');
        $this->info(' REPARACIÓN socios_comerciales (SOLO id_comercial=1)');
        $this->info('====================================================');

        Log::channel('repair_socios_comerciales')->info('--- INICIANDO COMANDO DE REPARACIÓN ---');

        /**
         * --------------------------------------------------------
         * PRECHECK
         * --------------------------------------------------------
         */
        try {
            DB::connection('mysql')->table('socios')->count();
            DB::connection('sqlsrv')->table('socios_comerciales')->count();
            DB::connection('sqlsrv')->table('comercial')->count();
            DB::connection('sqlsrv')->table('sociedad')->count();
            $this->info('✔ Conexiones OK');
        } catch (\Throwable $e) {
            $this->error('✘ Error de conexión');
            return self::FAILURE;
        }

        /**
         * --------------------------------------------------------
         * PRECARGAS (RENDIMIENTO)
         * --------------------------------------------------------
         */

        // MySQL: id_sociedad → codigo_sociedad
        $sociedadesOrigen = DB::connection('mysql')
            ->table('sociedades')
            ->pluck('codigo_sociedad', 'id_sociedad')
            ->toArray();

        // SQL Server: codigo_sociedad → id_sociedad
        $sociedadesDestino = DB::connection('sqlsrv')
            ->table('sociedad')
            ->pluck('id', 'codigo_sociedad')
            ->toArray();

        // Comerciales por sociedad destino
        $comercialesPorSociedad = DB::connection('sqlsrv')
            ->table('comercial')
            ->select('id', 'id_sociedad')
            ->get()
            ->groupBy('id_sociedad');

        $this->info('✔ Precargas completadas');

        /**
         * --------------------------------------------------------
         * TOTAL A PROCESAR + PROGRESS BAR
         * --------------------------------------------------------
         */
        $total = DB::connection('sqlsrv')
            ->table('socios_comerciales')
            ->where('id_comercial', 1)
            ->count();

        $this->info("Total registros a reparar: {$total}");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        /**
         * --------------------------------------------------------
         * PROCESO PRINCIPAL
         * --------------------------------------------------------
         */
        DB::connection('sqlsrv')
            ->table('socios_comerciales')
            ->where('id_comercial', 1)
            ->orderBy('id')
            ->chunk($chunk, function ($rows) use ($sociedadesOrigen, $sociedadesDestino, $comercialesPorSociedad, $nowSql, $bar) {

                foreach ($rows as $pivot) {

                    try {
                        /**
                         * 1. DNI DEL SOCIO (DESTINO → ORIGEN)
                         */
                        $dni = DB::connection('sqlsrv')
                            ->table('socios')
                            ->where('id', $pivot->id_socio)
                            ->value('dni');

                        if (!$dni) {
                            Log::channel('repair_socios_comerciales')->debug("Socio id={$pivot->id_socio} no encontrado o sin DNI en destino");
                            $bar->advance();
                            continue;
                        }

                        /**
                         * 2. SOCIEDAD ORIGEN
                         */
                        $idSociedadOrigen = DB::connection('mysql')
                            ->table('socios')
                            ->where('dni', $dni)
                            ->value('id_sociedad');

                        if (
                            !$idSociedadOrigen ||
                            !isset($sociedadesOrigen[$idSociedadOrigen])
                        ) {
                            Log::channel('repair_socios_comerciales')->debug("Socio DNI={$dni} no encontrado en origen o sin sociedad");
                            $bar->advance();
                            continue;
                        }

                        /**
                         * 3. SOCIEDAD DESTINO
                         */
                        $codigo = $sociedadesOrigen[$idSociedadOrigen];

                        if (!isset($sociedadesDestino[$codigo])) {
                            $bar->advance();
                            continue;
                        }

                        $idSocDestino = $sociedadesDestino[$codigo];

                        /**
                         * 4. COMERCIAL CORRECTO
                         */
                        if (
                            !isset($comercialesPorSociedad[$idSocDestino]) ||
                            $comercialesPorSociedad[$idSocDestino]->isEmpty()
                        ) {
                            // Se queda ADMIN
                            Log::channel('repair_socios_comerciales')->warning(
                                'FALLBACK_ADMIN',
                                [
                                    'pivot_id' => $pivot->id,
                                    'id_socio' => $pivot->id_socio,
                                    'dni' => $dni,
                                ]
                            );

                            $bar->advance();
                            continue;
                        }

                        $nuevoComercial = $comercialesPorSociedad[$idSocDestino]->first()->id;

                        /**
                         * 5. UPDATE PIVOT
                         */
                        DB::connection('sqlsrv')
                            ->table('socios_comerciales')
                            ->where('id', $pivot->id)
                            ->update([
                                'id_comercial' => $nuevoComercial,
                                'updated_at' => DB::raw("CONVERT(datetime, '{$nowSql}', 120)")
                            ]);

                        Log::channel('repair_socios_comerciales')->info(
                            'SOCIO_COMERCIAL_REPARADO',
                            [
                                'pivot_id' => $pivot->id,
                                'id_socio' => $pivot->id_socio,
                                'nuevo_comercial' => $nuevoComercial,
                            ]
                        );

                    } catch (\Throwable $e) {

                        Log::channel('repair_socios_comerciales')->error(
                            'ERROR_REPARANDO_PIVOT',
                            [
                                'pivot_id' => $pivot->id,
                                'exception' => $e->getMessage(),
                            ]
                        );
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();

        $this->info('====================================================');
        $this->info(' REPARACIÓN FINALIZADA');
        $this->info(' Logs: storage/logs/repair_socios_comerciales.log');
        $this->info('====================================================');

        return self::SUCCESS;
    }
}
