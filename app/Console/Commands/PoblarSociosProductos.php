<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PoblarSociosProductos extends Command
{
    protected $signature = 'poblar:socios-productos
                            {--test : Modo test - no inserta en BD}
                            {--dry-run : Muestra ejemplos sin insertar}
                            {--force : Forzar sin confirmación}
                            {--rebuild : Limpiar registros existentes antes de insertar}';

    protected $description = 'Poblar tabla socios_productos con los productos migrados';

    private $logChannel = 'migracion_seguros';

    private $stats = [
        'producto_c'           => 0,
        'producto_k'           => 0,
        'producto_rehal'       => 0,
        'producto_sjk'         => 0,
        'producto_smk'         => 0,
        'saltados'             => 0,
        'errores'              => 0,
        'sin_socio'            => 0,
        'sin_subproducto'      => 0,
        'subproducto_invalido' => 0,
    ];

    private array $subproductosValidosC    = [10237, 10252, 223, 10248, 10238];
    private array $subproductosValidosK    = [203, 204, 222, 224, 239, 240, 241, 242, 10254, 10255, 10256, 10257, 10258, 10259];
    private array $subproductosValidosRehal = [232, 10249, 10250, 10251];
    private array $subproductosValidosSmk  = [10246, 10260];

    public function handle()
    {
        Log::channel($this->logChannel)->info('=== INICIO POBLAR SOCIOS_PRODUCTOS ===');

        $this->info('🚀 Iniciando población de socios_productos');
        $this->newLine();

        $totalActual = DB::connection('sqlsrv')->table('socios_productos')->count();
        $this->info("📊 Registros actuales en socios_productos: {$totalActual}");
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->modoDryRun();
            return 0;
        }

        if (!$this->option('force') && !$this->option('test')) {
            if (!$this->confirm('¿Deseas continuar?')) {
                $this->warn('Cancelado');
                return 0;
            }
        }

        if ($this->option('test')) {
            $this->warn('⚠️  MODO TEST - No se insertarán datos');
        }

        if ($this->option('rebuild') && !$this->option('test')) {
            $this->limpiarRegistrosExistentes();
        }

        $this->procesarProductoC();
        $this->procesarProductoK();
        $this->procesarProductoRehal();
        $this->procesarProductoSjk();
        $this->procesarProductoSmk();

        $this->mostrarEstadisticas();

        Log::channel($this->logChannel)->info('=== FIN POBLAR SOCIOS_PRODUCTOS ===');

        return 0;
    }

    private function fechaActual(): \Illuminate\Database\Query\Expression
    {
        return DB::raw("CONVERT(datetime, '" . now()->format('Y-m-d H:i:s') . "', 120)");
    }

    private function limpiarRegistrosExistentes(): void
    {
        $this->warn('🧹 Limpiando registros existentes de tablas migradas...');

        $letrasALimpiar = [
            'PRODUCTO_C', 'PRODUCTO_C3', 'PRODUCTO_C6E', 'PRODUCTO_C6P',
            'PRODUCTO_E', 'PRODUCTO_R',
            'PRODUCTO_K1', 'PRODUCTO_K3', 'PRODUCTO_KVIP', 'PRODUCTO_KR',
            'PRODUCTO_KVIPP', 'PRODUCTO_KF', 'PRODUCTO_KT', 'PRODUCTO_KP',
            'PRODUCTO_KAVIP', 'PRODUCTO_K1C', 'PRODUCTO_K3C', 'PRODUCTO_KVIPC',
            'PRODUCTO_KRC', 'PRODUCTO_KPC',
            'PRODUCTO_REKE', 'PRODUCTO_KEKP', 'PRODUCTO_REAE', 'PRODUCTO_REAP',
            'PRODUCTO_SJK',
            'PRODUCTO_RCM', 'PRODUCTO_RCMA',
        ];

        $eliminados = DB::connection('sqlsrv')
            ->table('socios_productos')
            ->whereIn('letras_identificacion', $letrasALimpiar)
            ->delete();

        $this->info("   Eliminados: {$eliminados} registros");
        Log::channel($this->logChannel)->info("Registros limpiados: {$eliminados}");
    }

    /**
     * PRODUCTO_C
     * Un registro en socios_productos por cada seguro concreto.
     * id_producto → producto_c.id (ID del seguro concreto)
     * id_socio    → producto_c.socio_id
     * letras      → letras_identificacion del subproducto en tipo_producto
     *
     * Subproductos válidos (padre_id = 191):
     *   10237 → PRODUCTO_C3
     *   10252 → PRODUCTO_C6E
     *   223   → PRODUCTO_C6P
     *   10248 → PRODUCTO_E
     *   10238 → PRODUCTO_R
     *
     * Ignorados (IDs antiguos no presentes en nueva BD):
     *   195, 192, 193
     */
    private function procesarProductoC(): void
    {
        $this->info('📦 Procesando producto_c...');

        $tiposProducto = DB::connection('sqlsrv')
            ->table('tipo_producto')
            ->whereIn('id', $this->subproductosValidosC)
            ->pluck('letras_identificacion', 'id');

        $sinSocio = DB::connection('sqlsrv')
            ->table('producto_c')
            ->whereNull('socio_id')
            ->count();

        $sinSubproducto = DB::connection('sqlsrv')
            ->table('producto_c')
            ->whereNull('subproducto')
            ->count();

        $invalidos = DB::connection('sqlsrv')
            ->table('producto_c')
            ->whereNotNull('subproducto')
            ->whereNotIn('subproducto', $this->subproductosValidosC)
            ->count();

        $this->warn("   └─ Sin socio (ignorados): {$sinSocio}");
        $this->warn("   └─ Sin subproducto (ignorados): {$sinSubproducto}");
        $this->warn("   └─ Subproducto inválido/antiguo (ignorados): {$invalidos}");

        $this->stats['sin_socio']            += $sinSocio;
        $this->stats['sin_subproducto']      += $sinSubproducto;
        $this->stats['subproducto_invalido'] += $invalidos;

        $productos = DB::connection('sqlsrv')
            ->table('producto_c')
            ->whereNotNull('socio_id')
            ->whereNotNull('subproducto')
            ->whereIn('subproducto', $this->subproductosValidosC)
            ->get();

        $bar = $this->output->createProgressBar($productos->count());
        $bar->start();

        foreach ($productos as $producto) {
            try {
                $letras = $tiposProducto[$producto->subproducto] ?? null;

                if (!$letras) {
                    $bar->advance();
                    continue;
                }

                // Verificar si ya existe este seguro concreto (por id del producto)
                if ($this->yaExiste($producto->id, $letras)) {
                    $this->stats['saltados']++;
                    $bar->advance();
                    continue;
                }

                if (!$this->option('test')) {
                    DB::connection('sqlsrv')
                        ->table('socios_productos')
                        ->insert([
                            'id_producto'           => $producto->id,  // ID del seguro concreto
                            'id_socio'              => $producto->socio_id,
                            'letras_identificacion' => $letras,
                            'created_at'            => $this->fechaActual(),
                            'updated_at'            => $this->fechaActual(),
                        ]);
                }

                $this->stats['producto_c']++;

            } catch (\Exception $e) {
                $this->stats['errores']++;
                Log::channel($this->logChannel)->error(
                    "Error en producto_c ID {$producto->id}: {$e->getMessage()}"
                );
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("   ✅ producto_c: {$this->stats['producto_c']} registros insertados");
        Log::channel($this->logChannel)->info("producto_c procesado: {$this->stats['producto_c']}");
    }

    /**
     * PRODUCTO_K
     * id_producto → producto_k.id (ID del seguro concreto)
     *
     * Subproductos válidos (padre_id = 202):
     *   203, 204, 222, 224, 239, 240, 241, 242,
     *   10254, 10255, 10256, 10257, 10258, 10259
     *
     * Ignorados:
     *   NULL → PRODUCTO_16, PRODUCTO_18, PRODUCTO_31
     *   227  → PRUEBA 5
     */
    private function procesarProductoK(): void
    {
        $this->info('📦 Procesando producto_k...');

        $tiposProducto = DB::connection('sqlsrv')
            ->table('tipo_producto')
            ->whereIn('id', $this->subproductosValidosK)
            ->pluck('letras_identificacion', 'id');

        $sinSocio = DB::connection('sqlsrv')
            ->table('producto_k')
            ->whereNull('socio_id')
            ->count();

        $sinSubproducto = DB::connection('sqlsrv')
            ->table('producto_k')
            ->whereNull('subproducto')
            ->count();

        $invalidos = DB::connection('sqlsrv')
            ->table('producto_k')
            ->whereNotNull('subproducto')
            ->whereNotIn('subproducto', $this->subproductosValidosK)
            ->count();

        $this->warn("   └─ Sin socio (ignorados): {$sinSocio}");
        $this->warn("   └─ Sin subproducto (ignorados): {$sinSubproducto}");
        $this->warn("   └─ Subproducto inválido/antiguo (ignorados): {$invalidos}");

        $this->stats['sin_socio']            += $sinSocio;
        $this->stats['sin_subproducto']      += $sinSubproducto;
        $this->stats['subproducto_invalido'] += $invalidos;

        $productos = DB::connection('sqlsrv')
            ->table('producto_k')
            ->whereNotNull('socio_id')
            ->whereNotNull('subproducto')
            ->whereIn('subproducto', $this->subproductosValidosK)
            ->get();

        $bar = $this->output->createProgressBar($productos->count());
        $bar->start();

        foreach ($productos as $producto) {
            try {
                $letras = $tiposProducto[$producto->subproducto] ?? null;

                if (!$letras) {
                    $bar->advance();
                    continue;
                }

                if ($this->yaExiste($producto->id, $letras)) {
                    $this->stats['saltados']++;
                    $bar->advance();
                    continue;
                }

                if (!$this->option('test')) {
                    DB::connection('sqlsrv')
                        ->table('socios_productos')
                        ->insert([
                            'id_producto'           => $producto->id,
                            'id_socio'              => $producto->socio_id,
                            'letras_identificacion' => $letras,
                            'created_at'            => $this->fechaActual(),
                            'updated_at'            => $this->fechaActual(),
                        ]);
                }

                $this->stats['producto_k']++;

            } catch (\Exception $e) {
                $this->stats['errores']++;
                Log::channel($this->logChannel)->error(
                    "Error en producto_k ID {$producto->id}: {$e->getMessage()}"
                );
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("   ✅ producto_k: {$this->stats['producto_k']} registros insertados");
        Log::channel($this->logChannel)->info("producto_k procesado: {$this->stats['producto_k']}");
    }

    /**
     * PRODUCTO_REHAL
     * id_producto → producto_rehal.id (ID del seguro concreto)
     *
     * Subproductos válidos:
     *   232   → PRODUCTO_REKE
     *   10249 → PRODUCTO_KEKP
     *   10250 → PRODUCTO_REAE
     *   10251 → PRODUCTO_REAP
     */
    private function procesarProductoRehal(): void
    {
        $this->info('📦 Procesando producto_rehal...');

        $tiposProducto = DB::connection('sqlsrv')
            ->table('tipo_producto')
            ->whereIn('id', $this->subproductosValidosRehal)
            ->pluck('letras_identificacion', 'id');

        $sinSocio = DB::connection('sqlsrv')
            ->table('producto_rehal')
            ->whereNull('socio_id')
            ->count();

        $sinSubproducto = DB::connection('sqlsrv')
            ->table('producto_rehal')
            ->whereNull('subproducto')
            ->count();

        $this->warn("   └─ Sin socio (ignorados): {$sinSocio}");
        $this->warn("   └─ Sin subproducto (ignorados): {$sinSubproducto}");

        $this->stats['sin_socio']       += $sinSocio;
        $this->stats['sin_subproducto'] += $sinSubproducto;

        $productos = DB::connection('sqlsrv')
            ->table('producto_rehal')
            ->whereNotNull('socio_id')
            ->whereNotNull('subproducto')
            ->whereIn('subproducto', $this->subproductosValidosRehal)
            ->get();

        $bar = $this->output->createProgressBar($productos->count());
        $bar->start();

        foreach ($productos as $producto) {
            try {
                $letras = $tiposProducto[$producto->subproducto] ?? null;

                if (!$letras) {
                    $bar->advance();
                    continue;
                }

                if ($this->yaExiste($producto->id, $letras)) {
                    $this->stats['saltados']++;
                    $bar->advance();
                    continue;
                }

                if (!$this->option('test')) {
                    DB::connection('sqlsrv')
                        ->table('socios_productos')
                        ->insert([
                            'id_producto'           => $producto->id,
                            'id_socio'              => $producto->socio_id,
                            'letras_identificacion' => $letras,
                            'created_at'            => $this->fechaActual(),
                            'updated_at'            => $this->fechaActual(),
                        ]);
                }

                $this->stats['producto_rehal']++;

            } catch (\Exception $e) {
                $this->stats['errores']++;
                Log::channel($this->logChannel)->error(
                    "Error en producto_rehal ID {$producto->id}: {$e->getMessage()}"
                );
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("   ✅ producto_rehal: {$this->stats['producto_rehal']} registros insertados");
        Log::channel($this->logChannel)->info("producto_rehal procesado: {$this->stats['producto_rehal']}");
    }

    /**
     * PRODUCTO_SJK
     * id_producto → producto_sjk.id (ID del seguro concreto)
     * letras      → siempre PRODUCTO_SJK (no tiene subproductos)
     */
    private function procesarProductoSjk(): void
    {
        $this->info('📦 Procesando producto_sjk...');

        $sinSocio = DB::connection('sqlsrv')
            ->table('producto_sjk')
            ->whereNull('socio_id')
            ->count();

        $this->warn("   └─ Sin socio (ignorados): {$sinSocio}");
        $this->stats['sin_socio'] += $sinSocio;

        $productos = DB::connection('sqlsrv')
            ->table('producto_sjk')
            ->whereNotNull('socio_id')
            ->get();

        $bar = $this->output->createProgressBar($productos->count());
        $bar->start();

        foreach ($productos as $producto) {
            try {
                if ($this->yaExiste($producto->id, 'PRODUCTO_SJK')) {
                    $this->stats['saltados']++;
                    $bar->advance();
                    continue;
                }

                if (!$this->option('test')) {
                    DB::connection('sqlsrv')
                        ->table('socios_productos')
                        ->insert([
                            'id_producto'           => $producto->id,
                            'id_socio'              => $producto->socio_id,
                            'letras_identificacion' => 'PRODUCTO_SJK',
                            'created_at'            => $this->fechaActual(),
                            'updated_at'            => $this->fechaActual(),
                        ]);
                }

                $this->stats['producto_sjk']++;

            } catch (\Exception $e) {
                $this->stats['errores']++;
                Log::channel($this->logChannel)->error(
                    "Error en producto_sjk ID {$producto->id}: {$e->getMessage()}"
                );
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("   ✅ producto_sjk: {$this->stats['producto_sjk']} registros insertados");
        Log::channel($this->logChannel)->info("producto_sjk procesado: {$this->stats['producto_sjk']}");
    }

    /**
     * PRODUCTO_SMK
     * id_producto → producto_smk.id (ID del seguro concreto)
     *
     * Subproductos válidos:
     *   10246 → PRODUCTO_RCM
     *   10260 → PRODUCTO_RCMA
     *
     * Ignorados:
     *   NULL → 5 registros sin subproducto
     */
    private function procesarProductoSmk(): void
    {
        $this->info('📦 Procesando producto_smk...');

        $tiposProducto = DB::connection('sqlsrv')
            ->table('tipo_producto')
            ->whereIn('id', $this->subproductosValidosSmk)
            ->pluck('letras_identificacion', 'id');

        $sinSocio = DB::connection('sqlsrv')
            ->table('producto_smk')
            ->whereNull('socio_id')
            ->count();

        $sinSubproducto = DB::connection('sqlsrv')
            ->table('producto_smk')
            ->whereNull('subproducto')
            ->count();

        $this->warn("   └─ Sin socio (ignorados): {$sinSocio}");
        $this->warn("   └─ Sin subproducto (ignorados): {$sinSubproducto}");

        $this->stats['sin_socio']       += $sinSocio;
        $this->stats['sin_subproducto'] += $sinSubproducto;

        $productos = DB::connection('sqlsrv')
            ->table('producto_smk')
            ->whereNotNull('socio_id')
            ->whereNotNull('subproducto')
            ->whereIn('subproducto', $this->subproductosValidosSmk)
            ->get();

        $bar = $this->output->createProgressBar($productos->count());
        $bar->start();

        foreach ($productos as $producto) {
            try {
                $letras = $tiposProducto[$producto->subproducto] ?? null;

                if (!$letras) {
                    $bar->advance();
                    continue;
                }

                if ($this->yaExiste($producto->id, $letras)) {
                    $this->stats['saltados']++;
                    $bar->advance();
                    continue;
                }

                if (!$this->option('test')) {
                    DB::connection('sqlsrv')
                        ->table('socios_productos')
                        ->insert([
                            'id_producto'           => $producto->id,
                            'id_socio'              => $producto->socio_id,
                            'letras_identificacion' => $letras,
                            'created_at'            => $this->fechaActual(),
                            'updated_at'            => $this->fechaActual(),
                        ]);
                }

                $this->stats['producto_smk']++;

            } catch (\Exception $e) {
                $this->stats['errores']++;
                Log::channel($this->logChannel)->error(
                    "Error en producto_smk ID {$producto->id}: {$e->getMessage()}"
                );
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("   ✅ producto_smk: {$this->stats['producto_smk']} registros insertados");
        Log::channel($this->logChannel)->info("producto_smk procesado: {$this->stats['producto_smk']}");
    }

    /**
     * Verificar si ya existe el registro por id_producto + letras_identificacion
     * (combinación única por seguro concreto)
     */
    private function yaExiste(int $idProducto, string $letras): bool
    {
        return DB::connection('sqlsrv')
            ->table('socios_productos')
            ->where('id_producto', $idProducto)
            ->where('letras_identificacion', $letras)
            ->exists();
    }

    private function modoDryRun(): void
    {
        $this->warn('🔍 MODO DRY-RUN - Mostrando ejemplos de cada tabla');
        $this->newLine();

        $todosLosIds = array_merge(
            $this->subproductosValidosC,
            $this->subproductosValidosK,
            $this->subproductosValidosRehal,
            $this->subproductosValidosSmk
        );

        $tiposProducto = DB::connection('sqlsrv')
            ->table('tipo_producto')
            ->whereIn('id', $todosLosIds)
            ->pluck('letras_identificacion', 'id');

        $tablas = [
            ['nombre' => 'PRODUCTO_C',     'tabla' => 'producto_c',     'validos' => $this->subproductosValidosC],
            ['nombre' => 'PRODUCTO_K',     'tabla' => 'producto_k',     'validos' => $this->subproductosValidosK],
            ['nombre' => 'PRODUCTO_REHAL', 'tabla' => 'producto_rehal', 'validos' => $this->subproductosValidosRehal],
            ['nombre' => 'PRODUCTO_SMK',   'tabla' => 'producto_smk',   'validos' => $this->subproductosValidosSmk],
        ];

        foreach ($tablas as $tabla) {
            $this->info("=== {$tabla['nombre']} (primeros 3) ===");

            $ejemplos = DB::connection('sqlsrv')
                ->table($tabla['tabla'])
                ->whereNotNull('socio_id')
                ->whereNotNull('subproducto')
                ->whereIn('subproducto', $tabla['validos'])
                ->limit(3)
                ->get();

            foreach ($ejemplos as $e) {
                $letras = $tiposProducto[$e->subproducto] ?? 'NO MAPEADO';
                $this->line("  id_producto: {$e->id} | id_socio: {$e->socio_id} | letras: {$letras}");
            }

            $this->newLine();
        }

        $this->info('=== PRODUCTO_SJK (primeros 3) ===');
        $ejemplosSjk = DB::connection('sqlsrv')
            ->table('producto_sjk')
            ->whereNotNull('socio_id')
            ->limit(3)
            ->get();

        foreach ($ejemplosSjk as $e) {
            $this->line("  id_producto: {$e->id} | id_socio: {$e->socio_id} | letras: PRODUCTO_SJK");
        }

        $this->newLine();
        $this->info('✅ Dry-run completado');
    }

    private function mostrarEstadisticas(): void
    {
        $totalInsertado = $this->stats['producto_c']
            + $this->stats['producto_k']
            + $this->stats['producto_rehal']
            + $this->stats['producto_sjk']
            + $this->stats['producto_smk'];

        $totalFinal = DB::connection('sqlsrv')->table('socios_productos')->count();

        $this->newLine();
        $this->info('📈 Estadísticas:');
        $this->table(
            ['Tabla', 'Insertados'],
            [
                ['producto_c',              $this->stats['producto_c']],
                ['producto_k',              $this->stats['producto_k']],
                ['producto_rehal',          $this->stats['producto_rehal']],
                ['producto_sjk',            $this->stats['producto_sjk']],
                ['producto_smk',            $this->stats['producto_smk']],
                ['─────────────────────',  '──────────'],
                ['✅ Total insertado',       $totalInsertado],
                ['⏭️  Saltados',              $this->stats['saltados']],
                ['❌ Errores',               $this->stats['errores']],
                ['⚠️  Sin socio',             $this->stats['sin_socio']],
                ['⚠️  Sin subproducto',       $this->stats['sin_subproducto']],
                ['⚠️  Subproducto inválido',  $this->stats['subproducto_invalido']],
                ['📊 Total en tabla',        $totalFinal],
            ]
        );

        if ($this->stats['errores'] > 0) {
            $this->error("⚠️  Hay {$this->stats['errores']} errores.");
            $this->warn("📝 Revisa: storage/logs/migracion_seguros.log");
        } else {
            $this->info('✅ Completado exitosamente');
        }

        Log::channel($this->logChannel)->info('Estadísticas finales:', $this->stats);
    }
}