<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LimpiarProductoK extends Command
{
    protected $signature = 'limpiar:producto-k 
                            {--test : Modo test - no elimina nada}
                            {--force : Forzar eliminación sin confirmación}';

    protected $description = 'Eliminar de producto_k los registros con finalizado=1';

    private $logChannel = 'migracion_seguros';

    public function handle()
    {
        $this->info('🧹 Iniciando limpieza de producto_k');
        $this->info('📝 Eliminando registros con finalizado=1 en origen');
        $this->newLine();

        Log::channel($this->logChannel)->info('=== INICIO LIMPIEZA PRODUCTO_K ===');

        // Obtener pólizas que NO deberían estar (finalizado = 1)
        $polizasAEliminar = DB::connection('mysql')
            ->table('seguros_combinados')
            ->where('borrado', 0)
            ->where('finalizado', 1)
            ->pluck('poliza_seguro')
            ->toArray();

        $totalAEliminar = count($polizasAEliminar);
        $this->info("📊 Pólizas con finalizado=1: {$totalAEliminar}");
        Log::channel($this->logChannel)->info("Pólizas a eliminar: {$totalAEliminar}");

        if ($totalAEliminar === 0) {
            $this->info('✅ No hay registros que eliminar');
            return 0;
        }

        // SQL Server tiene límite de 2100 parámetros, usar lotes de 1000
        $loteSize = 1000;
        $lotes = array_chunk($polizasAEliminar, $loteSize);
        
        // Contar cuántos existen en producto_k (por lotes)
        $this->info('Verificando existencia en producto_k...');
        $existentesEnProductoK = 0;
        
        foreach ($lotes as $lote) {
            $existentesEnProductoK += DB::connection('sqlsrv')
                ->table('producto_k')
                ->whereIn('codigo_producto', $lote)
                ->count();
        }

        $this->info("   Encontrados en producto_k: {$existentesEnProductoK}");
        Log::channel($this->logChannel)->info("Registros encontrados en producto_k: {$existentesEnProductoK}");

        if ($existentesEnProductoK === 0) {
            $this->info('✅ No hay registros que eliminar en producto_k');
            return 0;
        }

        // Mostrar estadísticas adicionales
        $this->newLine();
        $this->info("📊 Estadísticas adicionales:");
        
        $stats = DB::connection('mysql')
            ->table('seguros_combinados')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN finalizado = 1 AND cancelado = 1 THEN 1 ELSE 0 END) as finalizado_y_cancelado,
                SUM(CASE WHEN finalizado = 1 AND cancelado = 0 THEN 1 ELSE 0 END) as finalizado_no_cancelado
            ')
            ->where('borrado', 0)
            ->where('finalizado', 1)
            ->first();

        $this->table(
            ['Tipo', 'Cantidad'],
            [
                ['Finalizado=1 Y Cancelado=1', $stats->finalizado_y_cancelado],
                ['Finalizado=1 Y Cancelado=0', $stats->finalizado_no_cancelado],
                ['Total a eliminar', $stats->total],
            ]
        );

        // Mostrar ejemplos
        $this->newLine();
        $this->warn("Ejemplos de registros a eliminar (primeros 10):");
        
        $ejemplos = DB::connection('sqlsrv')
            ->table('producto_k')
            ->whereIn('codigo_producto', array_slice($polizasAEliminar, 0, 10))
            ->select('id', 'codigo_producto', 'nombre_socio', 'apellido_1', 'precio_total', 'anulado')
            ->get();

        $tablaEjemplos = [];
        foreach ($ejemplos as $ejemplo) {
            $tablaEjemplos[] = [
                $ejemplo->id,
                $ejemplo->codigo_producto,
                ($ejemplo->nombre_socio ?? '') . ' ' . ($ejemplo->apellido_1 ?? ''),
                $ejemplo->precio_total ?? 0,
                $ejemplo->anulado ? 'Sí' : 'No',
            ];
        }

        $this->table(['ID', 'Código', 'Nombre', 'Precio', 'Anulado'], $tablaEjemplos);
        $this->newLine();

        // Modo test
        if ($this->option('test')) {
            $this->warn('⚠️  MODO TEST - No se eliminarán datos');
            
            $this->info('Resumen de eliminación (simulado):');
            $this->info("   Total a eliminar: {$existentesEnProductoK} registros");
            $this->info("   Esto dejará aproximadamente 5,422 registros en producto_k");
            $this->info("   Se procesarán en " . count($lotes) . " lotes de {$loteSize} registros");
            
            return 0;
        }

        // Confirmar
        if (!$this->option('force')) {
            $this->newLine();
            $this->warn("⚠️  ATENCIÓN: Se eliminarán {$existentesEnProductoK} registros");
            $this->warn("   Quedarán aproximadamente 5,422 registros (finalizado=0)");
            $this->warn("   Se procesarán en " . count($lotes) . " lotes");
            $this->newLine();
            
            if (!$this->confirm("¿Confirmas eliminar {$existentesEnProductoK} registros de producto_k?")) {
                $this->warn('Limpieza cancelada');
                return 0;
            }
        }

        // Eliminar en lotes
        $this->newLine();
        $this->info('Eliminando registros en lotes de ' . $loteSize . '...');
        $bar = $this->output->createProgressBar(count($lotes));
        $bar->start();

        $eliminados = 0;
        $errores = 0;

        foreach ($lotes as $index => $lote) {
            try {
                $deleted = DB::connection('sqlsrv')
                    ->table('producto_k')
                    ->whereIn('codigo_producto', $lote)
                    ->delete();

                $eliminados += $deleted;
                
                Log::channel($this->logChannel)->debug("Lote " . ($index + 1) . "/" . count($lotes) . " eliminado: {$deleted} registros");
            } catch (\Exception $e) {
                $errores++;
                Log::channel($this->logChannel)->error("Error eliminando lote " . ($index + 1), [
                    'error' => $e->getMessage(),
                    'lote_size' => count($lote),
                ]);
            }
            
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Verificar resultado
        $totalFinal = DB::connection('sqlsrv')
            ->table('producto_k')
            ->count();

        // Resumen
        $this->info('📈 Resumen de limpieza:');
        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Registros antes', $existentesEnProductoK + $totalFinal],
                ['✅ Eliminados', $eliminados],
                ['❌ Errores', $errores],
                ['📊 Total final en producto_k', $totalFinal],
                ['✅ Esperado (finalizado=0)', '~5,422'],
                ['📦 Lotes procesados', count($lotes)],
            ]
        );

        if ($errores > 0) {
            $this->error("⚠️  Hubo {$errores} errores durante la eliminación");
            $this->warn("📝 Revisa los logs: storage/logs/migracion_seguros.log");
        } else {
            $this->info('✅ Limpieza completada exitosamente');
        }

        // Verificación final
        $this->newLine();
        $diferencia = abs($totalFinal - 5422);
        if ($diferencia < 100) {
            $this->info("✅ Verificación: Total final ({$totalFinal}) coincide con lo esperado (~5,422)");
        } else {
            $this->warn("⚠️  Verificación: Total final ({$totalFinal}) difiere del esperado (5,422) en {$diferencia} registros");
        }

        Log::channel($this->logChannel)->info('=== FIN LIMPIEZA PRODUCTO_K ===');
        Log::channel($this->logChannel)->info("Eliminados: {$eliminados}, Errores: {$errores}, Total final: {$totalFinal}");

        return 0;
    }
}