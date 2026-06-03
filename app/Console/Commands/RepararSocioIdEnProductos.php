<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RepararSocioIdEnProductos
 *
 * Tras una re-migración de socios, los productos que tenían socio_id = NULL
 * (porque el socio aún no existía en destino cuando se migró el seguro)
 * quedan huérfanos. Este comando los repara:
 *
 *   1. Itera las tablas de productos configuradas.
 *   2. Busca registros con socio_id NULL pero con DNI del tomador.
 *   3. Localiza el socio en SQL Server por ese DNI.
 *   4. Actualiza socio_id en el producto.
 *   5. Crea el registro en socios_productos si no existe.
 */
class RepararSocioIdEnProductos extends Command
{
    protected $signature = 'reparar:socio-id
                            {--test   : Modo test – no escribe en BD}
                            {--force  : No pide confirmación}
                            {--tabla= : Limitar a una sola tabla (ej: producto_k)}';

    protected $description = 'Repara socio_id NULL en tablas de productos enlazando por DNI del tomador';

    private $logChannel = 'repair_socios_comerciales';

    /**
     * Mapa: tabla_fisica => columna_dni_del_tomador
     *
     * La columna DNI es la que se usó al migrar para identificar al socio.
     * En la mayoría de tablas es 'dni'; ajusta si alguna difiere.
     */
    private array $tablas = [
        'producto_k'    => ['dni_col' => 'dni', 'letras_col' => 'subproducto'],
        'producto_c'    => ['dni_col' => 'dni', 'letras_col' => 'subproducto'],
        'producto_rehal'=> ['dni_col' => 'dni', 'letras_col' => 'subproducto'],
        'producto_sjk'  => ['dni_col' => 'dni', 'letras_col' => null],          // sin subproducto
        'producto_smk'  => ['dni_col' => 'dni', 'letras_col' => 'subproducto'],
    ];

    private array $stats = [
        'revisados'       => 0,
        'reparados'       => 0,
        'sin_dni'         => 0,
        'socio_no_hallado'=> 0,
        'sp_creados'      => 0,
        'errores'         => 0,
    ];

    public function handle(): int
    {
        $this->info('====================================================');
        $this->info(' INICIO REPARACIÓN socio_id en tablas de productos');
        $this->info('====================================================');

        if ($this->option('test')) {
            $this->warn('⚠️  MODO TEST – No se escribirá nada en la BD');
        }

        if (!$this->option('force') && !$this->option('test')) {
            if (!$this->confirm('¿Continuar con la reparación?')) {
                $this->warn('Cancelado');
                return self::FAILURE;
            }
        }

        // Precargar todos los socios de destino indexados por DNI normalizado
        $this->info('📥 Precargando socios de SQL Server...');
        $sociosPorDni = DB::connection('sqlsrv')
            ->table('socios')
            ->select('id', 'dni')
            ->whereNotNull('dni')
            ->get()
            ->keyBy(fn($s) => $this->normalizarDni($s->dni));

        $this->info("   ✔ {$sociosPorDni->count()} socios indexados por DNI");

        // Precargar mapa tipo_producto id → letras_identificacion
        $tiposProducto = DB::connection('sqlsrv')
            ->table('tipo_producto')
            ->select('id', 'letras_identificacion')
            ->get()
            ->pluck('letras_identificacion', 'id');

        $tablasFiltradas = $this->tablas;
        if ($this->option('tabla')) {
            $tablaFiltro = $this->option('tabla');
            if (!isset($tablasFiltradas[$tablaFiltro])) {
                $this->error("Tabla desconocida: {$tablaFiltro}");
                return self::FAILURE;
            }
            $tablasFiltradas = [$tablaFiltro => $tablasFiltradas[$tablaFiltro]];
        }

        foreach ($tablasFiltradas as $tabla => $config) {
            $this->procesarTabla($tabla, $config, $sociosPorDni, $tiposProducto);
        }

        $this->mostrarEstadisticas();

        return self::SUCCESS;
    }

    private function procesarTabla(
        string $tabla,
        array  $config,
        $sociosPorDni,
        $tiposProducto
    ): void {
        $this->newLine();
        $this->info("📦 Procesando {$tabla}...");

        $dniCol = $config['dni_col'];

        // Contar cuántos tienen socio_id NULL con DNI presente
        $total = DB::connection('sqlsrv')
            ->table($tabla)
            ->whereNull('socio_id')
            ->whereNotNull($dniCol)
            ->where($dniCol, '!=', '')
            ->count();

        if ($total === 0) {
            $this->info("   ✅ Sin registros con socio_id NULL en {$tabla}");
            return;
        }

        $this->warn("   ⚠️  {$total} registros con socio_id NULL");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        DB::connection('sqlsrv')
            ->table($tabla)
            ->whereNull('socio_id')
            ->whereNotNull($dniCol)
            ->where($dniCol, '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($tabla, $config, $sociosPorDni, $tiposProducto, $bar) {

                foreach ($rows as $row) {
                    $this->stats['revisados']++;

                    $dni = $this->normalizarDni($row->{$config['dni_col']});

                    if (!$dni) {
                        $this->stats['sin_dni']++;
                        $bar->advance();
                        continue;
                    }

                    $socio = $sociosPorDni->get($dni);

                    if (!$socio) {
                        $this->stats['socio_no_hallado']++;
                        Log::channel($this->logChannel)->warning('SOCIO_NO_HALLADO', [
                            'tabla' => $tabla,
                            'id'    => $row->id,
                            'dni'   => $dni,
                        ]);
                        $bar->advance();
                        continue;
                    }

                    try {
                        if (!$this->option('test')) {
                            // 1. Actualizar socio_id en el producto
                            DB::connection('sqlsrv')
                                ->table($tabla)
                                ->where('id', $row->id)
                                ->update(['socio_id' => $socio->id]);

                            // 2. Crear entrada en socios_productos si falta
                            $letras = $this->resolverLetras($tabla, $config, $row, $tiposProducto);
                            if ($letras) {
                                $yaExiste = DB::connection('sqlsrv')
                                    ->table('socios_productos')
                                    ->where('id_producto', $row->id)
                                    ->where('letras_identificacion', $letras)
                                    ->exists();

                                if (!$yaExiste) {
                                    DB::connection('sqlsrv')
                                        ->table('socios_productos')
                                        ->insert([
                                            'id_producto'           => $row->id,
                                            'id_socio'              => $socio->id,
                                            'letras_identificacion' => $letras,
                                            'created_at'            => DB::raw("CONVERT(datetime, '" . now()->format('Y-m-d H:i:s') . "', 120)"),
                                            'updated_at'            => DB::raw("CONVERT(datetime, '" . now()->format('Y-m-d H:i:s') . "', 120)"),
                                        ]);
                                    $this->stats['sp_creados']++;
                                }
                            }
                        }

                        $this->stats['reparados']++;
                        Log::channel($this->logChannel)->info('SOCIO_REPARADO', [
                            'tabla'    => $tabla,
                            'id'       => $row->id,
                            'socio_id' => $socio->id,
                            'dni'      => $dni,
                        ]);

                    } catch (\Throwable $e) {
                        $this->stats['errores']++;
                        Log::channel($this->logChannel)->error('ERROR_REPARACION', [
                            'tabla'     => $tabla,
                            'id'        => $row->id,
                            'exception' => $e->getMessage(),
                        ]);
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
    }

    /**
     * Determina las letras_identificacion del producto:
     *  - Si tiene columna subproducto → la resuelve via tipo_producto.
     *  - Si es producto_sjk → siempre 'PRODUCTO_SJK'.
     */
    private function resolverLetras(string $tabla, array $config, $row, $tiposProducto): ?string
    {
        if ($tabla === 'producto_sjk') {
            return 'PRODUCTO_SJK';
        }

        $letrasCol = $config['letras_col'];
        if (!$letrasCol || !isset($row->{$letrasCol})) {
            return null;
        }

        $subproductoId = $row->{$letrasCol};
        return $tiposProducto->get($subproductoId);
    }

    private function normalizarDni(?string $dni): ?string
    {
        if (!$dni) return null;
        $n = strtoupper(trim(str_replace([' ', '-'], '', $dni)));
        return $n === '' ? null : $n;
    }

    private function mostrarEstadisticas(): void
    {
        $this->newLine();
        $this->info('📈 Estadísticas finales:');
        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Revisados',              $this->stats['revisados']],
                ['✅ Reparados',            $this->stats['reparados']],
                ['➕ socios_productos creados', $this->stats['sp_creados']],
                ['⚠️  Sin DNI',             $this->stats['sin_dni']],
                ['⚠️  Socio no hallado',    $this->stats['socio_no_hallado']],
                ['❌ Errores',              $this->stats['errores']],
            ]
        );

        if ($this->stats['socio_no_hallado'] > 0) {
            $this->warn('Los socios no hallados necesitan re-ejecutar transfer:socios primero.');
        }

        if ($this->stats['errores'] > 0) {
            $this->error("Hay {$this->stats['errores']} errores. Revisa: storage/logs/repair_socios_comerciales.log");
        } else {
            $this->info('✅ Completado sin errores');
        }
    }
}
