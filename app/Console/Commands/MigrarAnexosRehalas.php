<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Migra los perros de las rehalas (seguro_rehalas_perro) a ANEXOS_APRK (Kyrema) o ANEXOS_APRA (AER).
 *
 * Se ejecuta DESPUÉS de migrate:seguros-rehalas: cada anexo se engancha al producto_rehal ya creado,
 * localizado por codigo_producto = poliza_seguro de la rehala antigua. Si el producto no existe, el perro se omite.
 */
class MigrarAnexosRehalas extends Command
{
    protected $signature = 'migrate:anexos-rehalas
                            {--corte=2026-10-20 : Solo perros de rehalas vigentes a esta fecha (fecha_inicio + 365 días >= corte)}
                            {--dry-run : Muestra lo que se insertaría sin escribir nada}';

    protected $description = 'Migrar perros de rehalas (seguro_rehalas_perro) a ANEXOS_APRK / ANEXOS_APRA';

    private const SOCIEDAD_AER = 103;
    private const TIPO_PRODUCTO_APRK = 233;
    private const TIPO_PRODUCTO_APRA = 10262;

    private $logChannel = 'migracion_seguros';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $inicioMinimo = Carbon::parse($this->option('corte'))->subDays(365)->format('Y-m-d');

        $this->info('Migración de anexos de rehalas' . ($dryRun ? ' (DRY-RUN)' : ''));
        $this->info("Rehalas con fecha_inicio >= {$inicioMinimo}");

        $plantillas = [
            'aprk' => DB::connection('sqlsrv')->table('tipo_producto')->where('id', self::TIPO_PRODUCTO_APRK)->value('plantilla_path_1'),
            'apra' => DB::connection('sqlsrv')->table('tipo_producto')->where('id', self::TIPO_PRODUCTO_APRA)->value('plantilla_path_1'),
        ];

        $rehalas = DB::connection('mysql')
            ->table('seguro_rehalas')
            ->where('fecha_inicio', '>=', $inicioMinimo)
            ->get()
            ->keyBy('id_seguro');

        if ($rehalas->isEmpty()) {
            $this->warn('No hay rehalas vigentes.');
            return self::SUCCESS;
        }

        $perros = DB::connection('mysql')
            ->table('seguro_rehalas_perro')
            ->whereIn('id_seguro_rehalas', $rehalas->keys()->all())
            ->orderBy('id_perro')
            ->get();

        $this->info("Rehalas vigentes: {$rehalas->count()} | perros a procesar: {$perros->count()}");

        // Productos ya migrados, por codigo_producto
        $productos = DB::connection('sqlsrv')
            ->table('producto_rehal')
            ->whereIn('codigo_producto', $rehalas->pluck('poliza_seguro')->all())
            ->get(['id', 'codigo_producto', 'fecha_de_inicio', 'fecha_de_fin', 'anulado'])
            ->keyBy('codigo_producto');

        $stats = ['insertados' => 0, 'ya_existian' => 0, 'sin_producto' => 0, 'errores' => 0];
        $contadorPorProducto = [];
        $omitidos = [];

        foreach ($perros as $perro) {
            $rehala = $rehalas[$perro->id_seguro_rehalas];
            $producto = $productos[$rehala->poliza_seguro] ?? null;

            if (!$producto) {
                $stats['sin_producto']++;
                $omitidos[] = [$perro->id_perro, $rehala->poliza_seguro, 'producto_rehal no migrado'];
                continue;
            }

            $esAER = (int) $rehala->id_sociedad === self::SOCIEDAD_AER;
            $tabla = $esAER ? 'anexos_apra' : 'anexos_aprk';

            $chip = trim((string) $perro->microchip);
            $existe = DB::connection('sqlsrv')
                ->table($tabla)
                ->where('producto_id', $producto->id)
                ->where('nº_de_chip', $chip)
                ->where('raza_perro', $perro->raza)
                ->where('nombre_propietario', $perro->nombre_propietario)
                ->exists();

            $contadorPorProducto[$producto->id] = ($contadorPorProducto[$producto->id] ?? 0) + 1;

            if ($existe) {
                $stats['ya_existian']++;
                continue;
            }

            $incorporacion = $this->fecha($perro->fecha_incorporacion, true);
            $emision = $this->fecha($rehala->fecha_emision) ?? $this->fecha($producto->fecha_de_inicio);
            // El anexo hereda el fin de la rehala (tipo_duracion "heredada") y empieza el día de incorporación
            $inicio = $incorporacion ? $incorporacion . ' 00:00:00' : $this->fecha($producto->fecha_de_inicio);
            $fin = $this->fecha($producto->fecha_de_fin);
            $ahora = Carbon::now()->format('Y-m-d H:i:s');

            $fila = [
                'producto_id' => $producto->id,
                'precio_base' => 0,
                'extra_1' => 0,
                'extra_2' => 0,
                'extra_3' => 0,
                'precio_total' => 0,
                'plantilla_path_1' => $esAER ? $plantillas['apra'] : $plantillas['aprk'],
                'anulado' => (int) (bool) $producto->anulado,
                'raza_perro' => $perro->raza,
                'nº_de_chip' => $chip,
                'nombre_propietario' => $perro->nombre_propietario,
                'dni_propietario' => $perro->dni_propietario,
                'caducado' => 0,
                'pendiente_pago' => 0,
                // ANEXOS_APRK no admite NULL en blob_name; el PDF se genera después (igual que anexos_ka en combinados)
                'blob_name' => '',
                'created_at' => DB::raw("CONVERT(datetime, '{$ahora}', 120)"),
                'updated_at' => DB::raw("CONVERT(datetime, '{$ahora}', 120)"),
            ];

            if ($incorporacion) {
                $fila['fecha_incorporacion'] = $incorporacion;
            }
            if ($emision) {
                $fila['fecha_de_emisión'] = DB::raw("CONVERT(datetime, '{$emision}', 120)");
            }
            if ($inicio) {
                $fila['fecha_de_inicio'] = DB::raw("CONVERT(datetime, '{$inicio}', 120)");
            }
            if ($fin) {
                $fila['fecha_de_fin'] = DB::raw("CONVERT(datetime, '{$fin}', 120)");
            }

            if ($dryRun) {
                $stats['insertados']++;
                continue;
            }

            try {
                DB::connection('sqlsrv')->table($tabla)->insert($fila);
                $stats['insertados']++;
            } catch (\Throwable $e) {
                $stats['errores']++;
                Log::channel($this->logChannel)->error('Error anexo rehala', [
                    'id_perro' => $perro->id_perro,
                    'poliza' => $rehala->poliza_seguro,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // numero_anexos del producto = perros de la rehala antigua (los existentes + los nuevos)
        if (!$dryRun) {
            foreach ($contadorPorProducto as $productoId => $total) {
                DB::connection('sqlsrv')->table('producto_rehal')->where('id', $productoId)->update(['numero_anexos' => $total]);
            }
        }

        $this->table(['Concepto', 'Cantidad'], [
            ['Insertados' . ($dryRun ? ' (simulado)' : ''), $stats['insertados']],
            ['Ya existían', $stats['ya_existian']],
            ['Sin producto_rehal en la nueva', $stats['sin_producto']],
            ['Errores', $stats['errores']],
            ['Productos con numero_anexos actualizado', $dryRun ? 0 : count($contadorPorProducto)],
        ]);

        if (!empty($omitidos)) {
            $ruta = storage_path('logs/migracion_anexos_rehalas_omitidos.csv');
            $fh = fopen($ruta, 'w');
            fputcsv($fh, ['id_perro', 'poliza_rehala', 'motivo'], ';');
            foreach ($omitidos as $fila) {
                fputcsv($fh, $fila, ';');
            }
            fclose($fh);
            $this->warn("Perros omitidos: " . count($omitidos) . ". Detalle en {$ruta}");
        }

        return $stats['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Devuelve 'Y-m-d H:i:s' (o 'Y-m-d' si $soloFecha) o null si la fecha no es válida para SQL Server.
     */
    private function fecha($valor, bool $soloFecha = false): ?string
    {
        if (!$valor || (is_string($valor) && str_starts_with($valor, '0000'))) {
            return null;
        }

        try {
            $d = Carbon::parse($valor);
            if ($d->year < 1753 || $d->year > 9999) {
                return null;
            }

            return $d->format($soloFecha ? 'Y-m-d' : 'Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
