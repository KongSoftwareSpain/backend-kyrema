<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MigrarSegurosCombinadosK extends Command
{
    protected $signature = 'migrate:seguros-combinados 
                            {--limit= : Número máximo de registros a migrar}
                            {--offset=0 : Desde qué registro empezar}
                            {--test : Modo test - no inserta en BD}
                            {--dry-run : Muestra 5 ejemplos sin insertar}
                            {--force : Forzar migración sin confirmación}
                            {--rebuild-map : Reconstruir mapeo de comerciales, socios y productos}
                            {--default-comercial=1 : ID comercial por defecto para no mapeados}
                            {--sociedad-id= : Filtrar solo por este id_sociedad en MySQL (ej: 90 para ArabaCaza)}';

    protected $description = 'Migrar registros de seguros_combinados a producto_k (solo borrado=0 Y finalizado=0)';

    private $logChannel = 'migracion_seguros';

    private $stats = [
        'total' => 0,
        'migrados' => 0,
        'errores' => 0,
        'saltados' => 0,
        'sin_comercial' => 0,
        'sociedades_no_mapeadas' => 0,
        'socios_no_encontrados' => 0,
        'productos_no_mapeados' => 0,
    ];

    private $anomalias = [
        'sin_socio' => [],
        'sin_comercial' => [],
        'sin_sexo' => [],
        'sin_email' => [],
        'sin_telefono' => [],
        'sin_direccion' => [],
        'sin_fecha_nacimiento' => [],
        'sociedad_por_defecto' => [],
        'producto_no_mapeado' => [],
        'precio_cero' => [],
        'fechas_invalidas' => [],
        'sin_id_socio_original' => [],
    ];

    private $mapeoComerciales = [];
    private $mapeoSociedades = [];
    private $mapeoSociosPorId = [];
    private $mapeoProductos = [];

    public function handle()
    {
        // Limpiar logs
        $logPath = storage_path('logs/migracion_seguros.log');
        if (file_exists($logPath)) {
            file_put_contents($logPath, '');
        }

        $anomaliasPath = storage_path('logs/migracion_anomalias_combinados.log');
        if (file_exists($anomaliasPath)) {
            file_put_contents($anomaliasPath, '');
        }

        Log::channel($this->logChannel)->info('=== INICIO DE MIGRACIÓN SEGUROS COMBINADOS ===');
        Log::channel($this->logChannel)->info('Fecha: ' . now());
        Log::channel($this->logChannel)->info('Filtro: borrado=0 AND finalizado=0');

        $this->info('🚀 Iniciando migración de seguros_combinados a producto_k');
        $this->warn('⚠️  Solo se migrarán registros con: borrado=0 AND finalizado=0');
        $this->info('📝 Logs detallados en: storage/logs/migracion_seguros.log');
        $this->info('⚠️  Anomalías en: storage/logs/migracion_anomalias_combinados.log');
        $this->newLine();

        // Construir mapeos
        $this->construirMapeoComerciales();
        $this->construirMapeoSociedades();
        $this->construirMapeoSociosPorId();
        $this->construirMapeoProductos();

        // Configurar conexiones
        $oldConnection = 'mysql';
        $newConnection = 'sqlsrv';

        // Filtro opcional por sociedad
        $sociedadId = $this->option('sociedad-id') ? (int) $this->option('sociedad-id') : null;
        if ($sociedadId) {
            $this->warn("🔍 Filtrando solo seguros de id_sociedad={$sociedadId} en MySQL");
            Log::channel($this->logChannel)->info("Filtro de sociedad activo: id_sociedad={$sociedadId}");
        }

        // Contar registros totales CON EL FILTRO CORRECTO
        $queryBase = DB::connection($oldConnection)
            ->table('seguros_combinados')
            ->where('borrado', 0)
            ->where('finalizado', 0); // ← FILTRO

        if ($sociedadId) {
            $queryBase->where('id_sociedad', $sociedadId);
        }

        $totalRegistros = $queryBase->count();

        $this->stats['total'] = $totalRegistros;
        $this->info("📊 Total de registros a migrar (borrado=0 Y finalizado=0): {$totalRegistros}");
        Log::channel($this->logChannel)->info("Total de registros a migrar: {$totalRegistros}");

        // Mostrar estadísticas de exclusión
        $queryExcluidos = DB::connection($oldConnection)
            ->table('seguros_combinados')
            ->where('borrado', 0)
            ->where('finalizado', 1);
        if ($sociedadId) {
            $queryExcluidos->where('id_sociedad', $sociedadId);
        }
        $totalExcluidos = $queryExcluidos->count();

        $this->warn("   Registros EXCLUIDOS (finalizado=1): {$totalExcluidos}");
        Log::channel($this->logChannel)->info("Registros excluidos (finalizado=1): {$totalExcluidos}");
        $this->newLine();

        // Modo dry-run
        if ($this->option('dry-run')) {
            $this->modoDryRun($oldConnection);
            return 0;
        }

        // Confirmar
        if (!$this->option('force') && !$this->option('test')) {
            if (!$this->confirm('¿Deseas continuar con la migración?')) {
                $this->warn('Migración cancelada');
                return 0;
            }
        }

        if ($this->option('test')) {
            $this->warn('⚠️  MODO TEST - No se insertarán datos en la BD');
        }

        $this->newLine();
        $bar = $this->output->createProgressBar($totalRegistros);
        $bar->start();

        // Obtener registros CON EL FILTRO CORRECTO
        $query = DB::connection($oldConnection)
            ->table('seguros_combinados')
            ->where('borrado', 0)
            ->where('finalizado', 0) // ← FILTRO
            ->orderBy('id_seguro');

        if ($sociedadId) {
            $query->where('id_sociedad', $sociedadId);
        }

        if ($this->option('offset')) {
            $query->skip($this->option('offset'));
        }

        if ($this->option('limit')) {
            $query->take($this->option('limit'));
        }

        $seguros = $query->get();

        foreach ($seguros as $seguro) {
            try {
                // Verificar si ya existe
                if ($this->yaExiste($seguro->poliza_seguro, $newConnection)) {
                    $this->stats['saltados']++;
                    Log::channel($this->logChannel)->info("⏭️  Saltado (ya existe): ID {$seguro->id_seguro} - {$seguro->poliza_seguro}");
                    $bar->advance();
                    continue;
                }

                $datosNuevos = $this->transformarDatos($seguro);

                // Registrar anomalías
                $this->registrarAnomalias($seguro, $datosNuevos);

                // Log detallado antes de insertar
                Log::channel($this->logChannel)->debug("Procesando seguro ID {$seguro->id_seguro}", [
                    'poliza' => $seguro->poliza_seguro,
                    'id_socio_antiguo' => $seguro->id_socio,
                    'socio_id_nuevo' => $datosNuevos['socio_id'],
                    'producto_antiguo' => $seguro->id_producto,
                    'subproducto_nuevo' => $datosNuevos['subproducto'],
                    'subproducto_codigo' => $datosNuevos['subproducto_codigo'],
                    'precio_total' => $datosNuevos['precio_total'],
                    'finalizado' => $seguro->finalizado ?? 'N/A',
                ]);

                if (!$this->option('test')) {
                    DB::connection($newConnection)
                        ->table('producto_k')
                        ->insert($datosNuevos);
                }

                $this->stats['migrados']++;
                Log::channel($this->logChannel)->info("✅ Migrado: ID {$seguro->id_seguro} - {$seguro->poliza_seguro}");
            } catch (\Exception $e) {
                $this->stats['errores']++;

                Log::channel($this->logChannel)->error("❌ ERROR en ID {$seguro->id_seguro}", [
                    'poliza' => $seguro->poliza_seguro,
                    'error' => $e->getMessage(),
                    'linea' => $e->getLine(),
                    'archivo' => basename($e->getFile()),
                    'id_socio' => $seguro->id_socio,
                    'id_producto' => $seguro->id_producto,
                    'trace' => $e->getTraceAsString(),
                ]);

                Log::error("Error migrando ID {$seguro->id_seguro}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->mostrarEstadisticas();
        $this->generarReporteAnomalias();

        return 0;
    }

    /**
     * Construir mapeo de productos (id_producto antiguo → subproducto nuevo)
     * Solo mapea productos K (PRODUCTO_K*)
     */
    private function construirMapeoProductos(): void
    {
        $cacheKey = 'mapeo_productos_combinados_v5';

        if (!$this->option('rebuild-map') && Cache::has($cacheKey)) {
            $this->mapeoProductos = Cache::get($cacheKey);
            $this->info("✅ Mapeo de productos cargado desde caché (" . count($this->mapeoProductos) . " productos)");
            Log::channel($this->logChannel)->info("Mapeo de productos cargado desde caché: " . count($this->mapeoProductos) . " productos");
            return;
        }

        $this->info('🔍 Construyendo mapeo de productos K...');
        Log::channel($this->logChannel)->info('Iniciando construcción de mapeo de productos K');

        try {
            // Obtener productos antiguos de la tabla 'productos'
            $productosAntiguos = DB::connection('mysql')
                ->table('productos')
                ->get();

            Log::channel($this->logChannel)->info("Productos antiguos encontrados: " . $productosAntiguos->count());

            // Obtener SOLO subproductos K de tipo_producto (excluir el padre id=202)
            $subproductosK = DB::connection('sqlsrv')
                ->table('tipo_producto')
                ->where('letras_identificacion', 'LIKE', 'PRODUCTO_K%')
                ->where('id', '!=', 202) // Excluir el producto padre
                ->get();

            $this->info("   Subproductos K encontrados: " . $subproductosK->count());
            Log::channel($this->logChannel)->info("Subproductos K en nueva BD: " . $subproductosK->count());

            // Log de productos K disponibles
            Log::channel($this->logChannel)->info("Productos K disponibles:");
            foreach ($subproductosK as $k) {
                Log::channel($this->logChannel)->debug("  - ID:{$k->id} | Nombre: '{$k->nombre}' | Letras: {$k->letras_identificacion}");
            }

            // Crear índice por nombre (que es el código en la nueva BD)
            $indiceNombres = [];

            foreach ($subproductosK as $nuevo) {
                $nombreNormalizado = strtoupper(trim($nuevo->nombre ?? ''));

                if ($nombreNormalizado) {
                    $indiceNombres[$nombreNormalizado] = $nuevo;
                }
            }

            $this->info("   Índice creado con " . count($indiceNombres) . " productos K");
            Log::channel($this->logChannel)->info("Índice de nombres creado: " . count($indiceNombres) . " entradas");

            $mapeados = 0;
            $noMapeados = [];

            foreach ($productosAntiguos as $antiguo) {
                $encontrado = false;
                $nuevo = null;

                // Obtener código del producto antiguo (ej: "K1", "K3", "KVIP")
                $codigoAntiguo = strtoupper(trim($antiguo->codigo_producto ?? ''));

                // Mapeos especiales conocidos
                $mapeoEspecial = [
                    'KVIPP' => 'KVIP+',  // KVIPP → KVIP+
                ];

                $codigoNormalizado = $mapeoEspecial[$codigoAntiguo] ?? $codigoAntiguo;

                // Buscar en el índice por código normalizado
                if ($codigoNormalizado && isset($indiceNombres[$codigoNormalizado])) {
                    $nuevo = $indiceNombres[$codigoNormalizado];
                    $encontrado = true;
                }

                // Si encontró coincidencia, guardar mapeo
                if ($encontrado && $nuevo) {
                    $this->mapeoProductos[$antiguo->id_producto] = [
                        'id' => $nuevo->id,
                        'nombre' => $nuevo->nombre,
                        'codigo' => $nuevo->nombre,
                        'letras_identificacion' => $nuevo->letras_identificacion,
                    ];
                    $mapeados++;

                    Log::channel($this->logChannel)->debug("✓ Producto mapeado: ID {$antiguo->id_producto} '{$codigoAntiguo}' → ID {$nuevo->id} '{$nuevo->nombre}'");
                } else {
                    $noMapeados[] = [
                        'id' => $antiguo->id_producto,
                        'nombre' => $antiguo->nombre ?? '',
                        'codigo' => $codigoAntiguo,
                    ];

                    Log::channel($this->logChannel)->debug("✗ Producto NO mapeado: ID {$antiguo->id_producto} '{$codigoAntiguo}' (no es producto K o no existe)");
                }
            }

            Cache::put($cacheKey, $this->mapeoProductos, now()->addHours(24));

            $this->info("✅ Productos K mapeados: {$mapeados} / {$productosAntiguos->count()}");
            Log::channel($this->logChannel)->info("Mapeo de productos completado: {$mapeados} mapeados de {$productosAntiguos->count()} totales");

            $totalNoMapeados = count($noMapeados);
            if ($totalNoMapeados > 0) {
                $this->warn("⚠️  {$totalNoMapeados} productos NO mapeados (probablemente no son K)");
                Log::channel($this->logChannel)->warning("{$totalNoMapeados} productos no mapeados");

                // Log detallado de productos no mapeados
                Log::channel($this->logChannel)->info("Lista de productos NO mapeados:");
                foreach ($noMapeados as $producto) {
                    Log::channel($this->logChannel)->debug("  - ID: {$producto['id']} | Código: {$producto['codigo']} | Nombre: {$producto['nombre']}");
                }

                // Mostrar en consola los primeros 15
                $this->newLine();
                $this->warn("Productos NO mapeados (primeros 15):");
                $ejemplos = array_slice($noMapeados, 0, 15);

                $tablaNoMapeados = [];
                foreach ($ejemplos as $producto) {
                    $tablaNoMapeados[] = [
                        $producto['id'],
                        $producto['codigo'],
                        substr($producto['nombre'], 0, 40),
                    ];
                }

                $this->table(['ID', 'Código', 'Nombre'], $tablaNoMapeados);

                if ($totalNoMapeados > 15) {
                    $resto = $totalNoMapeados - 15;
                    $this->line("  ... y {$resto} más (ver logs)");
                }
            }

            if ($mapeados > 0) {
                $this->newLine();
                $this->info("📋 Primeros 10 mapeos exitosos:");

                $contador = 0;
                foreach ($this->mapeoProductos as $idAntiguo => $datos) {
                    if ($contador >= 10) break;

                    $productoAntiguo = $productosAntiguos->firstWhere('id_producto', $idAntiguo);
                    $codigoAntiguo = $productoAntiguo->codigo_producto ?? 'N/A';

                    $this->line("  {$codigoAntiguo} (ID:{$idAntiguo}) → {$datos['nombre']} (ID:{$datos['id']})");
                    $contador++;
                }
            }
        } catch (\Exception $e) {
            $this->error("Error mapeando productos: {$e->getMessage()}");
            Log::channel($this->logChannel)->error("Error en mapeo de productos", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Construir mapeo de socios por ID (seguros_combinados usa id_socio directamente)
     */
    private function construirMapeoSociosPorId(): void
    {
        $cacheKey = 'mapeo_socios_id_combinados_v1';

        if (!$this->option('rebuild-map') && Cache::has($cacheKey)) {
            $this->mapeoSociosPorId = Cache::get($cacheKey);
            $this->info("✅ Mapeo de socios cargado desde caché (" . count($this->mapeoSociosPorId) . " socios)");
            Log::channel($this->logChannel)->info("Mapeo de socios cargado desde caché: " . count($this->mapeoSociosPorId) . " socios");
            return;
        }

        $this->info('🔍 Construyendo mapeo de socios por DNI...');
        Log::channel($this->logChannel)->info('Iniciando construcción de mapeo de socios por DNI');

        try {
            // Obtener socios antiguos
            $sociosAntiguos = DB::connection('mysql')
                ->table('socios')
                ->get()
                ->keyBy('id_socio');

            Log::channel($this->logChannel)->info("Socios antiguos: " . $sociosAntiguos->count());

            // Obtener socios nuevos
            $sociosNuevos = DB::connection('sqlsrv')
                ->table('socios')
                ->whereNotNull('dni')
                ->get()
                ->keyBy(function ($socio) {
                    return $this->normalizarDNI($socio->dni);
                });

            Log::channel($this->logChannel)->info("Socios nuevos con DNI: " . $sociosNuevos->count());

            $mapeados = 0;

            foreach ($sociosAntiguos as $antiguo) {
                $dniNormalizado = $this->normalizarDNI($antiguo->dni ?? null);

                if ($dniNormalizado && isset($sociosNuevos[$dniNormalizado])) {
                    $this->mapeoSociosPorId[$antiguo->id_socio] = $sociosNuevos[$dniNormalizado]->id;
                    $mapeados++;

                    Log::channel($this->logChannel)->debug("Socio mapeado: ID antiguo {$antiguo->id_socio} (DNI: {$dniNormalizado}) → ID nuevo {$sociosNuevos[$dniNormalizado]->id}");
                } else {
                    Log::channel($this->logChannel)->debug("Socio NO mapeado: ID {$antiguo->id_socio} (DNI: " . ($dniNormalizado ?? 'NULL') . ")");
                }
            }

            Cache::put($cacheKey, $this->mapeoSociosPorId, now()->addHours(24));
            $this->info("✅ Socios mapeados: {$mapeados} / {$sociosAntiguos->count()}");
            Log::channel($this->logChannel)->info("Mapeo de socios completado: {$mapeados} de {$sociosAntiguos->count()}");

            $porcentaje = $sociosAntiguos->count() > 0
                ? round(($mapeados / $sociosAntiguos->count()) * 100, 1)
                : 0;
            $this->info("   Cobertura: {$porcentaje}%");
            Log::channel($this->logChannel)->info("Cobertura de mapeo de socios: {$porcentaje}%");
        } catch (\Exception $e) {
            $this->error("Error construyendo mapeo de socios: {$e->getMessage()}");
            Log::channel($this->logChannel)->error("Error en mapeo de socios", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function construirMapeoComerciales(): void
    {
        $cacheKey = 'mapeo_comerciales_migracion_v3';

        if (!$this->option('rebuild-map') && Cache::has($cacheKey)) {
            $this->mapeoComerciales = Cache::get($cacheKey);
            $this->info("✅ Mapeo de comerciales cargado desde caché (" . count($this->mapeoComerciales) . " comerciales)");
            Log::channel($this->logChannel)->info("Mapeo de comerciales cargado desde caché: " . count($this->mapeoComerciales) . " comerciales");
            return;
        }

        $this->info('🔍 Construyendo mapeo de comerciales...');
        Log::channel($this->logChannel)->info('Iniciando construcción de mapeo de comerciales');

        $usersAntiguos = DB::connection('mysql')->table('users')->get();
        $comercialesNuevos = DB::connection('sqlsrv')->table('comercial')->get();

        Log::channel($this->logChannel)->info("Users antiguos: " . $usersAntiguos->count());
        Log::channel($this->logChannel)->info("Comerciales nuevos: " . $comercialesNuevos->count());

        $mapeados = 0;

        foreach ($usersAntiguos as $antiguo) {
            foreach ($comercialesNuevos as $nuevo) {
                if (
                    $antiguo->email && $nuevo->email &&
                    strtolower(trim($antiguo->email)) === strtolower(trim($nuevo->email))
                ) {

                    $this->mapeoComerciales[$antiguo->id_user] = [
                        'nuevo_id' => $nuevo->id,
                        'nombre' => $nuevo->nombre,
                    ];
                    $mapeados++;

                    Log::channel($this->logChannel)->debug("Comercial mapeado: ID {$antiguo->id_user} ({$antiguo->email}) → ID {$nuevo->id} ({$nuevo->nombre})");
                    break;
                }
            }
        }

        Cache::put($cacheKey, $this->mapeoComerciales, now()->addHours(24));
        $this->info("✅ Comerciales mapeados: {$mapeados}");
        Log::channel($this->logChannel)->info("Mapeo de comerciales completado: {$mapeados} mapeados");
    }

    private function construirMapeoSociedades(): void
    {
        $this->info('🔍 Construyendo mapeo de sociedades...');
        Log::channel($this->logChannel)->info('Iniciando construcción de mapeo de sociedades');

        try {
            $sociedadesAntiguas = DB::connection('mysql')->table('sociedades')->get();
            $sociedadesNuevas = DB::connection('sqlsrv')->table('sociedad')->get();

            Log::channel($this->logChannel)->info("Sociedades antiguas: " . $sociedadesAntiguas->count());
            Log::channel($this->logChannel)->info("Sociedades nuevas: " . $sociedadesNuevas->count());

            $mapeados = 0;

            foreach ($sociedadesAntiguas as $antigua) {
                foreach ($sociedadesNuevas as $nueva) {
                    $nombreAntiguo = strtolower(trim($antigua->nombre ?? ''));
                    $nombreNuevo = strtolower(trim($nueva->nombre ?? ''));

                    if ($nombreAntiguo && $nombreAntiguo === $nombreNuevo) {
                        $this->mapeoSociedades[$antigua->id_sociedad] = $nueva->id;
                        $mapeados++;

                        Log::channel($this->logChannel)->debug("Sociedad mapeada: ID {$antigua->id_sociedad} '{$antigua->nombre}' → ID {$nueva->id}");
                        break;
                    }
                }
            }

            $this->info("✅ Sociedades mapeadas: {$mapeados}");
            Log::channel($this->logChannel)->info("Mapeo de sociedades completado: {$mapeados} mapeadas");
        } catch (\Exception $e) {
            $this->error("Error mapeando sociedades: {$e->getMessage()}");
            Log::channel($this->logChannel)->error("Error en mapeo de sociedades: {$e->getMessage()}");
        }
    }

    private function transformarDatos($seguro): array
    {
        $comercialId = $this->obtenerComercialNuevoId($seguro->id_emisor);
        $sociedadId = $this->obtenerSociedadNuevaId($seguro->id_sociedad);
        $socioId = $this->obtenerSocioNuevoId($seguro->id_socio);

        // Obtener datos del socio
        $datosSocio = $this->obtenerDatosSocio($socioId);

        // Obtener subproducto
        $subproductoInfo = $this->obtenerSubproducto($seguro->id_producto);

        // Validar fechas
        $fechaEmision = $this->validarFecha($seguro->fecha_emision);
        $fechaInicio = $this->validarFecha($seguro->fecha_inicio);
        $fechaFin = $this->validarFecha($seguro->expiration_date);

        // Fecha de nacimiento del socio
        $fechaNacimiento = null;
        if ($datosSocio && isset($datosSocio->fecha_de_nacimiento)) {
            $fechaNacimiento = $this->validarFechaNacimiento($datosSocio->fecha_de_nacimiento);
        }

        // Log de validación de fechas
        Log::channel($this->logChannel)->debug("Verificando fechas para ID {$seguro->id_seguro}", [
            'fecha_emision_raw' => $seguro->fecha_emision,
            'fecha_emision_validada' => $fechaEmision,
            'fecha_inicio_raw' => $seguro->fecha_inicio,
            'fecha_inicio_validada' => $fechaInicio,
            'fecha_fin_raw' => $seguro->expiration_date,
            'fecha_fin_validada' => $fechaFin,
            'fecha_nacimiento_validada' => $fechaNacimiento,
        ]);

        // Calcular precio (puede venir de otra tabla relacionada)
        $precioBase = $this->obtenerPrecioSeguro($seguro);
        $precioTotal = $precioBase;

        // Calcular número de anexos sumando acompañantes y perros en la vieja BD
        $numAcompaniantes = DB::connection('mysql')
            ->table('seguro_acompaniantes')
            ->where('id_seguro_combinado', $seguro->id_seguro)
            ->where('borrado', 0)
            ->count();

        $numPerros = DB::connection('mysql')
            ->table('seguro_perros')
            ->where('id_seguro', $seguro->id_seguro)
            ->where('id_tipo_seguro_perros', 2)
            ->where('borrado', 0)
            ->count();

        $numeroAnexos = $numAcompaniantes + $numPerros;

        $datos = [
            // IDs y control
            'sociedad_id' => $sociedadId,
            'tipo_de_pago_id' => $this->mapearTipoPagoId($seguro->id_tipopago),
            'tipo_de_pago' => $this->mapearTipoPago($seguro->id_tipopago),
            'comercial_id' => $comercialId,
            'comercial_creador_id' => $comercialId,
            'comercial' => $this->obtenerNombreComercial($seguro->id_emisor),
            'mediante_pagina_web' => $seguro->id_origen_emision === 1,
            'socio_id' => $socioId,
            'anulado' => (bool) $seguro->cancelado,
            'pago_id' => null,

            // Certificado/Póliza
            'codigo_producto' => $seguro->poliza_seguro,

            // Precios
            'precio_base' => $precioBase,
            'extra_1' => 0,
            'extra_2' => 0,
            'extra_3' => 0,
            'precio_total' => $precioTotal,
            'precio_final' => (string) $precioTotal,

            // Subproducto
            'subproducto' => $subproductoInfo['id'],
            'subproducto_codigo' => $subproductoInfo['codigo'],

            // Datos del socio (desde tabla socios)
            'nombre_socio' => $datosSocio->nombre_socio ?? null,
            'apellido_1' => $datosSocio->apellido_1 ?? null,
            'apellido_2' => $datosSocio->apellido_2 ?? null,
            'dni' => $datosSocio->dni ?? null,
            'email' => $datosSocio->email ?? '',
            'telefono' => $datosSocio->telefono ?? null,
            'sexo' => $datosSocio->sexo ?? '',
            'dirección' => $datosSocio->direccion ?? null,
            'codigo_postal' => $this->limpiarCodigoPostalInt($datosSocio->codigo_postal ?? null),
            'población' => $datosSocio->poblacion ?? null,
            'provincia' => $datosSocio->provincia ?? null,

            // Otros
            'duracion' => $this->calcularDuracion($fechaInicio, $fechaFin),
            'numero_anexos' => $numeroAnexos,
            'blob_name' => null,

            // Plantillas
            'logo_sociedad_path' => "logos/logo_{$seguro->id_sociedad}.png",
            'plantilla_path_1' => 'plantillas/default.jpg',
            'plantilla_path_2' => null,
            'plantilla_path_3' => null,
            'plantilla_path_4' => null,
            'plantilla_path_5' => null,
            'plantilla_path_6' => null,
            'plantilla_path_7' => null,
            'plantilla_path_8' => null,

            // Horas
            'hora_de_emisión' => $fechaEmision ? date('H:i', strtotime($fechaEmision)) : null,
            'hora_de_inicio' => $fechaInicio ? date('H:i', strtotime($fechaInicio)) : null,
            'hora_de_fin' => $fechaFin ? date('H:i', strtotime($fechaFin)) : null,
        ];

        // Añadir fechas con CONVERT
        if ($fechaEmision) {
            $datos['fecha_de_emisión'] = DB::raw("CONVERT(datetime, '{$fechaEmision}', 120)");
        }

        if ($fechaInicio) {
            $datos['fecha_de_inicio'] = DB::raw("CONVERT(datetime, '{$fechaInicio}', 120)");
        }

        if ($fechaFin) {
            $datos['fecha_de_fin'] = DB::raw("CONVERT(datetime, '{$fechaFin}', 120)");
        }

        if ($fechaNacimiento) {
            $datos['fecha_de_nacimiento'] = DB::raw("CONVERT(datetime, '{$fechaNacimiento}', 120)");
        }

        $datos['created_at'] = $fechaEmision ? DB::raw("CONVERT(datetime, '{$fechaEmision}', 120)") : DB::raw("GETDATE()");
        $datos['updated_at'] = $fechaEmision ? DB::raw("CONVERT(datetime, '{$fechaEmision}', 120)") : DB::raw("GETDATE()");

        return $datos;
    }

    private function obtenerPrecioSeguro($seguro): float
    {
        try {
            $precio = DB::connection('mysql')
                ->table('seguros_detalles')
                ->where('id_seguro', $seguro->id_seguro)
                ->value('precio');

            $precioFinal = (float) ($precio ?? 0);

            Log::channel($this->logChannel)->debug("Precio obtenido para ID {$seguro->id_seguro}: {$precioFinal}");

            return $precioFinal;
        } catch (\Exception $e) {
            Log::channel($this->logChannel)->warning("Error obteniendo precio para ID {$seguro->id_seguro}: {$e->getMessage()}");
            return 0;
        }
    }

    private function obtenerSubproducto($idProductoAntiguo): array
    {
        if (isset($this->mapeoProductos[$idProductoAntiguo])) {
            return [
                'id' => $this->mapeoProductos[$idProductoAntiguo]['id'],
                'codigo' => $this->mapeoProductos[$idProductoAntiguo]['codigo'],
            ];
        }

        $this->stats['productos_no_mapeados']++;

        Log::channel($this->logChannel)->warning("Producto no mapeado: ID antiguo {$idProductoAntiguo}");

        return [
            'id' => null,
            'codigo' => "PRODUCTO_{$idProductoAntiguo}",
        ];
    }

    private function obtenerSocioNuevoId(?int $idSocioAntiguo): ?int
    {
        if (!$idSocioAntiguo) {
            $this->stats['socios_no_encontrados']++;
            return null;
        }

        if (isset($this->mapeoSociosPorId[$idSocioAntiguo])) {
            return $this->mapeoSociosPorId[$idSocioAntiguo];
        }

        $this->stats['socios_no_encontrados']++;
        Log::channel($this->logChannel)->debug("Socio no encontrado: ID antiguo {$idSocioAntiguo}");
        return null;
    }

    private function obtenerComercialNuevoId(?int $idUserAntiguo): int
    {
        if (!$idUserAntiguo) {
            return (int) $this->option('default-comercial');
        }

        if (isset($this->mapeoComerciales[$idUserAntiguo])) {
            return $this->mapeoComerciales[$idUserAntiguo]['nuevo_id'];
        }

        $this->stats['sin_comercial']++;
        Log::channel($this->logChannel)->debug("Comercial no encontrado: ID antiguo {$idUserAntiguo}, usando por defecto");
        return (int) $this->option('default-comercial');
    }

    private function obtenerSociedadNuevaId(?int $idSociedadAntigua): int
    {
        if (!$idSociedadAntigua) {
            return 1;
        }

        if (isset($this->mapeoSociedades[$idSociedadAntigua])) {
            return $this->mapeoSociedades[$idSociedadAntigua];
        }

        $this->stats['sociedades_no_mapeadas']++;
        Log::channel($this->logChannel)->debug("Sociedad no mapeada: ID antigua {$idSociedadAntigua}, usando por defecto");
        return 1;
    }

    private function obtenerNombreComercial(?int $idUserAntiguo): string
    {
        if (!$idUserAntiguo) {
            return 'Sistema';
        }

        if (isset($this->mapeoComerciales[$idUserAntiguo])) {
            return $this->mapeoComerciales[$idUserAntiguo]['nombre'] ?? 'Sistema';
        }

        return 'Sistema';
    }

    private function obtenerDatosSocio(?int $socioId): ?object
    {
        if (!$socioId) {
            return null;
        }

        static $cache = [];

        if (isset($cache[$socioId])) {
            return $cache[$socioId];
        }

        try {
            $socio = DB::connection('sqlsrv')
                ->table('socios')
                ->where('id', $socioId)
                ->first();

            $cache[$socioId] = $socio;
            return $socio;
        } catch (\Exception $e) {
            Log::channel($this->logChannel)->warning("Error obteniendo datos de socio ID {$socioId}: {$e->getMessage()}");
            $cache[$socioId] = null;
            return null;
        }
    }

    private function registrarAnomalias($seguroOriginal, $datosTransformados): void
    {
        $id = $seguroOriginal->id_seguro;
        $poliza = $seguroOriginal->poliza_seguro;
        $nombreCompleto = trim(($datosTransformados['nombre_socio'] ?? '') . ' ' .
            ($datosTransformados['apellido_1'] ?? '') . ' ' .
            ($datosTransformados['apellido_2'] ?? ''));

        // SIN ID_SOCIO EN ORIGEN
        if (empty($seguroOriginal->id_socio)) {
            $this->anomalias['sin_id_socio_original'][] = [
                'id' => $id,
                'poliza' => $poliza,
            ];
        }

        // SIN SOCIO MAPEADO
        if (empty($datosTransformados['socio_id'])) {
            $this->anomalias['sin_socio'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto ?: 'SIN NOMBRE',
                'id_socio_antiguo' => $seguroOriginal->id_socio ?? 'NULL',
            ];
        }

        // SIN COMERCIAL
        if (
            $datosTransformados['comercial_id'] == $this->option('default-comercial') &&
            $datosTransformados['comercial'] === 'Sistema'
        ) {
            $this->anomalias['sin_comercial'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto,
                'emisor_original' => $seguroOriginal->id_emisor ?? 'NULL',
            ];
        }

        // SIN SEXO
        if (empty($datosTransformados['sexo']) || trim($datosTransformados['sexo']) === '') {
            $this->anomalias['sin_sexo'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto,
            ];
        }

        // SIN EMAIL
        if (empty($datosTransformados['email']) || trim($datosTransformados['email']) === '') {
            $this->anomalias['sin_email'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto,
            ];
        }

        // SIN TELÉFONO
        if (empty($datosTransformados['telefono'])) {
            $this->anomalias['sin_telefono'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto,
            ];
        }

        // SIN DIRECCIÓN
        if (empty($datosTransformados['dirección'])) {
            $this->anomalias['sin_direccion'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto,
            ];
        }

        // SIN FECHA DE NACIMIENTO
        if (empty($datosTransformados['fecha_de_nacimiento'])) {
            $this->anomalias['sin_fecha_nacimiento'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto,
            ];
        }

        // SOCIEDAD POR DEFECTO
        if ($datosTransformados['sociedad_id'] == 1 && $seguroOriginal->id_sociedad != 1) {
            $this->anomalias['sociedad_por_defecto'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto,
                'sociedad_original' => $seguroOriginal->id_sociedad,
            ];
        }

        // PRODUCTO NO MAPEADO
        if (empty($datosTransformados['subproducto'])) {
            $this->anomalias['producto_no_mapeado'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto,
                'producto_original' => $seguroOriginal->id_producto,
            ];
        }

        // PRECIO CERO
        if (($datosTransformados['precio_total'] ?? 0) == 0) {
            $this->anomalias['precio_cero'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto,
            ];
        }

        // FECHAS INVÁLIDAS
        if (
            empty($datosTransformados['fecha_de_inicio']) ||
            empty($datosTransformados['fecha_de_fin'])
        ) {
            $this->anomalias['fechas_invalidas'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'fecha_inicio_original' => $seguroOriginal->fecha_inicio,
                'fecha_fin_original' => $seguroOriginal->expiration_date,
            ];
        }
    }

    private function generarReporteAnomalias(): void
    {
        $logPath = storage_path('logs/migracion_anomalias_combinados.log');
        $contenido = [];

        $contenido[] = "================================================================================";
        $contenido[] = "   REPORTE DE ANOMALÍAS - MIGRACIÓN SEGUROS COMBINADOS (producto_k)";
        $contenido[] = "   Filtro aplicado: borrado=0 AND finalizado=0";
        $contenido[] = "   Fecha: " . now()->format('Y-m-d H:i:s');
        $contenido[] = "   Total migrados: {$this->stats['migrados']}";
        $contenido[] = "================================================================================";
        $contenido[] = "";

        $contenido[] = "RESUMEN DE ANOMALÍAS:";
        $contenido[] = str_repeat("-", 80);
        foreach ($this->anomalias as $tipo => $items) {
            $count = count($items);
            $porcentaje = $this->stats['migrados'] > 0
                ? number_format(($count / $this->stats['migrados']) * 100, 2)
                : 0;
            $contenido[] = sprintf(
                "%-35s: %5d registros (%s%%)",
                strtoupper(str_replace('_', ' ', $tipo)),
                $count,
                $porcentaje
            );
        }
        $contenido[] = "";
        $contenido[] = "";

        foreach ($this->anomalias as $tipo => $items) {
            if (empty($items)) {
                continue;
            }

            $count = count($items);
            $contenido[] = str_repeat("=", 80);
            $contenido[] = strtoupper(str_replace('_', ' ', $tipo)) . " ({$count} registros)";
            $contenido[] = str_repeat("=", 80);
            $contenido[] = "";

            foreach ($items as $item) {
                $contenido[] = "ID: {$item['id']} | Póliza: {$item['poliza']}";
                if (isset($item['nombre'])) {
                    $contenido[] = "Nombre: {$item['nombre']}";
                }

                unset($item['id'], $item['poliza'], $item['nombre']);
                foreach ($item as $key => $value) {
                    $contenido[] = "  - " . ucfirst($key) . ": " . ($value ?? 'NULL');
                }

                $contenido[] = "";
            }

            $contenido[] = "";
        }

        file_put_contents($logPath, implode("\n", $contenido));

        // Log en canal de migración también
        Log::channel($this->logChannel)->info("Reporte de anomalías generado en: {$logPath}");

        $this->newLine();
        $this->info("📋 RESUMEN DE ANOMALÍAS:");
        $this->newLine();

        $tablaAnomalias = [];
        foreach ($this->anomalias as $tipo => $items) {
            $count = count($items);
            if ($count > 0) {
                $porcentaje = $this->stats['migrados'] > 0
                    ? number_format(($count / $this->stats['migrados']) * 100, 1)
                    : 0;
                $tablaAnomalias[] = [
                    strtoupper(str_replace('_', ' ', $tipo)),
                    $count,
                    "{$porcentaje}%"
                ];
            }
        }

        if (!empty($tablaAnomalias)) {
            $this->table(['Tipo de Anomalía', 'Cantidad', '% del Total'], $tablaAnomalias);
            $this->newLine();
            $this->warn("📄 Ver detalles completos en: storage/logs/migracion_anomalias_combinados.log");
        } else {
            $this->info("✅ No se detectaron anomalías");
        }
    }

    private function modoDryRun(string $connection): void
    {
        $this->warn('🔍 MODO DRY-RUN - Mostrando 5 ejemplos');
        $this->warn('   Filtro: borrado=0 AND finalizado=0');
        $this->newLine();

        Log::channel($this->logChannel)->info('Ejecutando modo DRY-RUN con filtro: borrado=0 AND finalizado=0');

        $ejemplos = DB::connection($connection)
            ->table('seguros_combinados')
            ->where('borrado', 0)
            ->where('finalizado', 0) // ← FILTRO
            ->limit(5)
            ->get();

        foreach ($ejemplos as $index => $seguro) {
            $this->info("Ejemplo " . ($index + 1) . ":");
            $this->line("ID: {$seguro->id_seguro}");
            $this->line("Póliza: {$seguro->poliza_seguro}");
            $this->line("ID Socio: {$seguro->id_socio}");
            $this->line("Finalizado: " . ($seguro->finalizado ?? 'N/A'));

            $transformado = $this->transformarDatos($seguro);

            $camposClave = [
                'codigo_producto',
                'sociedad_id',
                'socio_id',
                'comercial_id',
                'subproducto',
                'subproducto_codigo',
                'precio_total',
            ];

            $tabla = [];
            foreach ($camposClave as $campo) {
                if (isset($transformado[$campo])) {
                    $tabla[] = [$campo, $this->formatearValor($transformado[$campo])];
                }
            }

            $this->table(['Campo', 'Valor'], $tabla);
            $this->newLine();

            Log::channel($this->logChannel)->debug("Ejemplo DRY-RUN {$index}", $transformado);
        }

        $this->info('✅ Dry-run completado');
        Log::channel($this->logChannel)->info('DRY-RUN completado');
    }

    private function formatearValor($valor): string
    {
        if (is_null($valor)) return 'NULL';
        if (is_bool($valor)) return $valor ? 'true' : 'false';
        if (is_array($valor)) return json_encode($valor);
        if (is_object($valor) && get_class($valor) === 'Illuminate\Database\Query\Expression') {
            return '[DB::raw]';
        }
        return (string) $valor;
    }

    private function mostrarEstadisticas(): void
    {
        $this->info('📈 Estadísticas de migración:');
        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Total registros (filtrados)', $this->stats['total']],
                ['✅ Migrados', $this->stats['migrados']],
                ['⏭️  Saltados (ya existían)', $this->stats['saltados']],
                ['❌ Errores', $this->stats['errores']],
                ['👥 Comerciales mapeados', count($this->mapeoComerciales)],
                ['🏢 Sociedades mapeadas', count($this->mapeoSociedades)],
                ['👤 Socios mapeados', count($this->mapeoSociosPorId)],
                ['📦 Productos mapeados', count($this->mapeoProductos)],
                ['⚠️  Sin comercial', $this->stats['sin_comercial']],
                ['⚠️  Sociedades no mapeadas', $this->stats['sociedades_no_mapeadas']],
                ['⚠️  Socios no encontrados', $this->stats['socios_no_encontrados']],
                ['⚠️  Productos no mapeados', $this->stats['productos_no_mapeados']],
            ]
        );

        if ($this->stats['errores'] > 0) {
            $this->error("⚠️  Hay {$this->stats['errores']} errores.");
            $this->warn("📝 Revisa los logs detallados en: storage/logs/migracion_seguros.log");
        }

        if ($this->option('test')) {
            $this->info('✅ Modo test completado - No se insertaron datos');
        } else {
            $this->info('✅ Migración completada exitosamente');
            $this->info('   Solo se migraron registros con: borrado=0 AND finalizado=0');
        }

        Log::channel($this->logChannel)->info('=== FIN DE MIGRACIÓN SEGUROS COMBINADOS ===');
        Log::channel($this->logChannel)->info('Estadísticas finales:', $this->stats);
    }

    // === MÉTODOS AUXILIARES ===

    private function mapearTipoPagoId(?int $idTipoPago): int
    {
        return match ($idTipoPago) {
            1 => 6,  // No completado
            2 => 5,  // Transferencia
            3 => 8,  // Efectivo
            4 => 10, // Giro bancario
            5 => 9,  // Tarjeta
            default => 6,
        };
    }

    private function mapearTipoPago(?int $idTipoPago): string
    {
        return match ($idTipoPago) {
            1 => 'No completado',
            2 => 'Transferencia',
            3 => 'Efectivo',
            4 => 'Giro bancario',
            5 => 'Tarjeta',
            default => 'No completado',
        };
    }

    private function yaExiste(string $codigoProducto, string $connection): bool
    {
        return DB::connection($connection)
            ->table('producto_k')
            ->where('codigo_producto', $codigoProducto)
            ->exists();
    }

    private function normalizarDNI(?string $dni): ?string
    {
        if (!$dni) return null;
        $normalizado = strtoupper(trim(str_replace([' ', '-'], '', $dni)));
        return $normalizado === '' ? null : $normalizado;
    }

    private function validarFecha($fecha): ?string
    {
        if (!$fecha) return null;

        try {
            $dt = new \DateTime($fecha);
            if ($dt->format('Y') < 1753 || $dt->format('Y') > 9999) {
                return null;
            }
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function validarFechaNacimiento($fecha): ?string
    {
        if (!$fecha) return null;

        try {
            $dt = new \DateTime($fecha);
            if ($dt->format('Y') < 1753 || $dt->format('Y') > 9999) {
                return null;
            }
            return $dt->format('Y-m-d') . ' 00:00:00';
        } catch (\Exception $e) {
            return null;
        }
    }

    private function calcularDuracion($inicio, $fin): string
    {
        if (!$inicio || !$fin) return '0';

        try {
            $fechaInicio = new \DateTime($inicio);
            $fechaFin = new \DateTime($fin);
            return (string) $fechaInicio->diff($fechaFin)->days;
        } catch (\Exception $e) {
            return '0';
        }
    }

    /**
     * Devuelve el código postal como entero extrayendo solo los dígitos.
     * Si el resultado no es un número de 4 o 5 dígitos, devuelve null.
     * Necesario porque en la BD de socios algunos códigos postales tienen
     * puntos, letras o texto libre (ej: '01015.', 'ARABA').
     */
    private function limpiarCodigoPostalInt($valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        // Quitar cualquier carácter no numérico
        $soloDigitos = preg_replace('/[^0-9]/', '', (string) $valor);

        if ($soloDigitos === '' || strlen($soloDigitos) < 4 || strlen($soloDigitos) > 5) {
            return null;
        }

        return (int) $soloDigitos;
    }
}
