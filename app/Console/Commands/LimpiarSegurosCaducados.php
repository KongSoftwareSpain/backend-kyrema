<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\TipoProducto;
use Carbon\Carbon;

class LimpiarSegurosCaducados extends Command
{
    protected $signature = 'insurances:limpiar-caducados {--date= : Fecha de referencia (Y-m-d), por defecto hoy}';

    protected $description = 'Archiva (marca caducado=true y registra en la tabla caducados) los productos cuya fecha_de_fin ya ha pasado.';

    public function handle()
    {
        $dateStr = $this->option('date');
        $hoy = $dateStr ? Carbon::parse($dateStr)->startOfDay() : Carbon::today();

        $this->info("Limpiando seguros caducados hasta la fecha: " . $hoy->format('Y-m-d'));

        $tiposProductos = TipoProducto::activos()
            ->whereNotNull('letras_identificacion')
            ->get();

        $totalArchivados = 0;

        foreach ($tiposProductos as $tipo) {
            $tableName = strtolower($tipo->letras_identificacion);

            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'fecha_de_fin') || !Schema::hasColumn($tableName, 'caducado')) {
                continue;
            }

            $productosCaducados = DB::table($tableName)
                ->whereDate('fecha_de_fin', '<', $hoy->format('Y-m-d'))
                ->where(function ($q) {
                    $q->whereNull('caducado')->orWhere('caducado', 0)->orWhere('caducado', false);
                })
                ->get();

            if ($productosCaducados->isEmpty()) {
                continue;
            }

            $this->info("{$tableName}: {$productosCaducados->count()} producto(s) a archivar.");

            foreach ($productosCaducados as $producto) {
                try {
                    DB::transaction(function () use ($tableName, $tipo, $producto, $hoy) {
                        DB::table($tableName)->where('id', $producto->id)->update(['caducado' => true]);

                        DB::table('caducados')->insert([
                            'fecha' => $hoy->format('Y-m-d'),
                            'sociedad_id' => $producto->sociedad_id ?? null,
                            'letrasIdentificacion' => $tipo->letras_identificacion,
                            'producto_id' => $producto->id,
                            'codigo_producto' => $producto->codigo_producto ?? null,
                            'fecha_de_fin' => $producto->fecha_de_fin ? Carbon::parse($producto->fecha_de_fin)->format('Y-m-d') : null,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                    });
                    $totalArchivados++;
                } catch (\Throwable $e) {
                    Log::error("Error archivando producto caducado {$producto->id} de {$tableName}: " . $e->getMessage());
                    $this->error("Fallo al archivar ID {$producto->id} en {$tableName}: " . $e->getMessage());
                }
            }
        }

        $this->info("Limpieza completada. Productos archivados: {$totalArchivados}.");
    }
}
