<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MigrarSegurosNaturaleza extends Command
{
    protected $signature = 'migrate:seguros-naturaleza 
                            {--limit= : Número máximo de registros a migrar}
                            {--offset=0 : Desde qué registro empezar}
                            {--test : Modo test - no inserta en BD}
                            {--dry-run : Muestra 5 ejemplos sin insertar}
                            {--force : Forzar migración sin confirmación}
                            {--rebuild-map : Reconstruir mapeo de comerciales y socios}
                            {--default-comercial=1 : ID comercial por defecto para no mapeados}
                            {--estrategia-precio=equitativa : Estrategia de precio: equitativa|fija|proporcional}';

    protected $description = 'Migrar registros de seguro_naturaleza a producto_smk y seguro_naturaleza_perros a anexos_peas';

    private $logChannel = 'migracion_seguros';

    private $stats = [
        'total_seguros' => 0,
        'seguros_migrados' => 0,
        'seguros_saltados' => 0,
        'seguros_errores' => 0,
        'total_perros' => 0,
        'perros_migrados' => 0,
        'perros_borrados' => 0,
        'sin_comercial' => 0,
        'sociedades_no_mapeadas' => 0,
        'socios_no_encontrados' => 0,
        'tipo_seguro_no_mapeado' => 0,
    ];

    private $anomalias = [
        'sin_socio' => [],
        'sin_perros' => [],
        'sin_comercial' => [],
        'tipo_seguro_no_mapeado' => [],
        'precio_cero' => [],
        'fechas_invalidas' => [],
    ];

    private $mapeoComerciales = [];
    private $mapeoSociedades = [];
    private $mapeoSociosPorId = [];

    // Mapeo de tipos de seguro naturaleza → tipo_producto
    private $mapeoTiposSeguro = [
        1 => 10260, // RC + Accidentes para perros y propietarios → RC + Accidentes perros
        2 => 10260, // RC + Accidentes para perros → RC + Accidentes perros
        3 => 10246, // RC → RC Mascotas
        4 => 10246, // Perros Ganaderos → RC Mascotas
    ];

    public function handle()
    {
        // Limpiar logs
        $logPath = storage_path('logs/migracion_seguros.log');
        if (file_exists($logPath)) {
            file_put_contents($logPath, '');
        }

        $anomaliasPath = storage_path('logs/migracion_anomalias_naturaleza.log');
        if (file_exists($anomaliasPath)) {
            file_put_contents($anomaliasPath, '');
        }

        Log::channel($this->logChannel)->info('=== INICIO DE MIGRACIÓN SEGUROS NATURALEZA ===');
        Log::channel($this->logChannel)->info('Fecha: ' . now());
        Log::channel($this->logChannel)->info('Estrategia de precio: ' . $this->option('estrategia-precio'));

        $this->info('🚀 Iniciando migración de seguro_naturaleza a producto_smk');
        $this->info('📝 Logs detallados en: storage/logs/migracion_seguros.log');
        $this->info('⚠️  Anomalías en: storage/logs/migracion_anomalias_naturaleza.log');
        $this->warn('💰 Estrategia de precio: ' . $this->option('estrategia-precio'));
        $this->newLine();

        // Construir mapeos
        $this->construirMapeoComerciales();
        $this->construirMapeoSociedades();
        $this->construirMapeoSociosPorId();

        // Configurar conexiones
        $oldConnection = 'mysql';
        $newConnection = 'sqlsrv';

        // Contar registros totales
        $totalSeguros = DB::connection($oldConnection)
            ->table('seguro_naturaleza')
            ->where('borrado', 0)
            ->count();

        $totalPerros = DB::connection($oldConnection)
            ->table('seguro_naturaleza_perros')
            ->where('borrado', 0)
            ->count();

        $this->stats['total_seguros'] = $totalSeguros;
        $this->stats['total_perros'] = $totalPerros;
        
        $this->info("📊 Total de seguros a migrar: {$totalSeguros}");
        $this->info("📊 Total de perros a migrar: {$totalPerros}");
        Log::channel($this->logChannel)->info("Total seguros: {$totalSeguros}, Total perros: {$totalPerros}");
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
        $bar = $this->output->createProgressBar($totalSeguros);
        $bar->start();

        // Obtener registros
        $query = DB::connection($oldConnection)
            ->table('seguro_naturaleza')
            ->where('borrado', 0)
            ->orderBy('id_seguro');

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
                    $this->stats['seguros_saltados']++;
                    Log::channel($this->logChannel)->info("⏭️  Saltado (ya existe): ID {$seguro->id_seguro} - {$seguro->poliza_seguro}");
                    $bar->advance();
                    continue;
                }

                // Obtener perros del seguro (solo NO borrados)
                $perros = DB::connection($oldConnection)
                    ->table('seguro_naturaleza_perros')
                    ->where('id_seguro_naturaleza', $seguro->id_seguro)
                    ->where('borrado', 0)
                    ->get();

                $numPerros = $perros->count();

                // Log si no tiene perros
                if ($numPerros === 0) {
                    $this->anomalias['sin_perros'][] = [
                        'id' => $seguro->id_seguro,
                        'poliza' => $seguro->poliza_seguro,
                    ];
                    Log::channel($this->logChannel)->warning("⚠️  Seguro sin perros: ID {$seguro->id_seguro}");
                }

                // Transformar datos del seguro principal
                $datosProducto = $this->transformarDatosProducto($seguro, $numPerros);

                // Registrar anomalías del producto
                $this->registrarAnomaliasProducto($seguro, $datosProducto);

                // Insertar producto
                $productoId = null;
                if (!$this->option('test')) {
                    $productoId = DB::connection($newConnection)
                        ->table('producto_smk')
                        ->insertGetId($datosProducto);
                } else {
                    $productoId = 999999; // ID ficticio para test
                }

                $this->stats['seguros_migrados']++;
                Log::channel($this->logChannel)->info("✅ Seguro migrado: ID {$seguro->id_seguro} → producto_smk ID {$productoId}");

                // Migrar perros asociados
                foreach ($perros as $perro) {
                    try {
                        $datosPerro = $this->transformarDatosPerro($perro, $seguro, $productoId, $numPerros);

                        if (!$this->option('test')) {
                            DB::connection($newConnection)
                                ->table('anexos_peas')
                                ->insert($datosPerro);
                        }

                        $this->stats['perros_migrados']++;
                        Log::channel($this->logChannel)->debug("  ✅ Perro migrado: {$perro->nombre} (microchip: {$perro->microchip})");

                    } catch (\Exception $e) {
                        Log::channel($this->logChannel)->error("  ❌ Error migrando perro ID {$perro->id_seguro_naturaleza_perros}", [
                            'error' => $e->getMessage(),
                            'perro' => $perro->nombre,
                        ]);
                    }
                }

            } catch (\Exception $e) {
                $this->stats['seguros_errores']++;

                Log::channel($this->logChannel)->error("❌ ERROR en seguro ID {$seguro->id_seguro}", [
                    'poliza' => $seguro->poliza_seguro,
                    'error' => $e->getMessage(),
                    'linea' => $e->getLine(),
                    'archivo' => basename($e->getFile()),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->mostrarEstadisticas();
        $this->generarReporteAnomalias();

        return 0;
    }

    private function construirMapeoSociosPorId(): void
    {
        $cacheKey = 'mapeo_socios_id_naturaleza_v1';

        if (!$this->option('rebuild-map') && Cache::has($cacheKey)) {
            $this->mapeoSociosPorId = Cache::get($cacheKey);
            $this->info("✅ Mapeo de socios cargado desde caché (" . count($this->mapeoSociosPorId) . " socios)");
            return;
        }

        $this->info('🔍 Construyendo mapeo de socios por DNI...');

        try {
            $sociosAntiguos = DB::connection('mysql')
                ->table('socios')
                ->get()
                ->keyBy('id_socio');

            $sociosNuevos = DB::connection('sqlsrv')
                ->table('socios')
                ->whereNotNull('dni')
                ->get()
                ->keyBy(function($socio) {
                    return $this->normalizarDNI($socio->dni);
                });

            $mapeados = 0;

            foreach ($sociosAntiguos as $antiguo) {
                $dniNormalizado = $this->normalizarDNI($antiguo->dni ?? null);
                
                if ($dniNormalizado && isset($sociosNuevos[$dniNormalizado])) {
                    $this->mapeoSociosPorId[$antiguo->id_socio] = $sociosNuevos[$dniNormalizado]->id;
                    $mapeados++;
                }
            }

            Cache::put($cacheKey, $this->mapeoSociosPorId, now()->addHours(24));
            $this->info("✅ Socios mapeados: {$mapeados}");

        } catch (\Exception $e) {
            $this->error("Error construyendo mapeo de socios: {$e->getMessage()}");
        }
    }

    private function construirMapeoComerciales(): void
    {
        $cacheKey = 'mapeo_comerciales_migracion_v3';

        if (!$this->option('rebuild-map') && Cache::has($cacheKey)) {
            $this->mapeoComerciales = Cache::get($cacheKey);
            $this->info("✅ Mapeo de comerciales cargado desde caché (" . count($this->mapeoComerciales) . " comerciales)");
            return;
        }

        $this->info('🔍 Construyendo mapeo de comerciales...');

        $usersAntiguos = DB::connection('mysql')->table('users')->get();
        $comercialesNuevos = DB::connection('sqlsrv')->table('comercial')->get();

        $mapeados = 0;

        foreach ($usersAntiguos as $antiguo) {
            foreach ($comercialesNuevos as $nuevo) {
                if ($antiguo->email && $nuevo->email &&
                    strtolower(trim($antiguo->email)) === strtolower(trim($nuevo->email))) {
                    
                    $this->mapeoComerciales[$antiguo->id_user] = [
                        'nuevo_id' => $nuevo->id,
                        'nombre' => $nuevo->nombre,
                    ];
                    $mapeados++;
                    break;
                }
            }
        }

        Cache::put($cacheKey, $this->mapeoComerciales, now()->addHours(24));
        $this->info("✅ Comerciales mapeados: {$mapeados}");
    }

    private function construirMapeoSociedades(): void
    {
        $this->info('🔍 Construyendo mapeo de sociedades...');

        try {
            $sociedadesAntiguas = DB::connection('mysql')->table('sociedades')->get();
            $sociedadesNuevas = DB::connection('sqlsrv')->table('sociedad')->get();

            $mapeados = 0;

            foreach ($sociedadesAntiguas as $antigua) {
                foreach ($sociedadesNuevas as $nueva) {
                    $nombreAntiguo = strtolower(trim($antigua->nombre ?? ''));
                    $nombreNuevo = strtolower(trim($nueva->nombre ?? ''));

                    if ($nombreAntiguo && $nombreAntiguo === $nombreNuevo) {
                        $this->mapeoSociedades[$antigua->id_sociedad] = $nueva->id;
                        $mapeados++;
                        break;
                    }
                }
            }

            $this->info("✅ Sociedades mapeadas: {$mapeados}");
        } catch (\Exception $e) {
            $this->error("Error mapeando sociedades: {$e->getMessage()}");
        }
    }

    /**
     * Transformar datos del seguro principal a producto_smk
     */
    private function transformarDatosProducto($seguro, int $numPerros): array
    {
        $sociedadId = $this->obtenerSociedadNuevaId($seguro->id_sociedad);
        $socioId = $this->obtenerSocioNuevoId($seguro->id_socio);
        $comercialId = (int) $this->option('default-comercial'); // NO mapea desde id_dominio_emision

        // Obtener datos del socio
        $datosSocio = $this->obtenerDatosSocio($socioId);

        // Obtener subproducto
        $subproducto = $this->obtenerSubproducto($seguro->id_tipo_seguro_naturaleza);

        // Validar fechas
        $fechaEmision = $this->validarFecha($seguro->fecha_emision);
        $fechaInicio = $this->validarFecha($seguro->fecha_inicio);
        $fechaFin = $this->validarFecha($seguro->expiration_date);
        
        // Fecha de nacimiento del socio
        $fechaNacimiento = null;
        if ($datosSocio && isset($datosSocio->fecha_de_nacimiento)) {
            $fechaNacimiento = $this->validarFechaNacimiento($datosSocio->fecha_de_nacimiento);
        }

        // Precio del producto (es el precio total del seguro)
        $precioTotal = (float) ($seguro->precio_seguro ?? 0);

        $datos = [
            // IDs y control
            'sociedad_id' => $sociedadId,
            'tipo_de_pago_id' => $this->mapearTipoPagoId($seguro->id_tipopago),
            'tipo_de_pago' => $this->mapearTipoPago($seguro->id_tipopago),
            'comercial_id' => $comercialId,
            'comercial_creador_id' => $comercialId,
            'comercial' => $this->obtenerNombreComercial(null), // Siempre "Sistema"
            'mediante_pagina_web' => $seguro->id_origen_emision === 1,
            'socio_id' => $socioId,
            'anulado' => false,
            'pago_id' => null,

            // Certificado/Póliza
            'codigo_producto' => $seguro->poliza_seguro,

            // Precios (del producto completo)
            'precio_base' => $precioTotal,
            'extra_1' => 0,
            'extra_2' => 0,
            'extra_3' => 0,
            'precio_total' => $precioTotal,
            'precio_final' => (string) $precioTotal,

            // Subproducto
            'subproducto' => $subproducto['id'],
            'subproducto_codigo' => $subproducto['codigo'],

            // Datos del socio
            'nombre_socio' => $datosSocio->nombre_socio ?? null,
            'apellido_1' => $datosSocio->apellido_1 ?? null,
            'apellido_2' => $datosSocio->apellido_2 ?? null,
            'dni' => $datosSocio->dni ?? null,
            'email' => $datosSocio->email ?? '',
            'telefono' => $datosSocio->telefono ?? null,
            'sexo' => $datosSocio->sexo ?? '',
            'dirección' => $datosSocio->direccion ?? null,
            'codigo_postal' => $datosSocio->codigo_postal ?? null,
            'población' => $datosSocio->poblacion ?? null,
            'provincia' => $datosSocio->provincia ?? null,

            // Otros
            'duracion' => $this->calcularDuracion($fechaInicio, $fechaFin),
            'numero_anexos' => $numPerros, // Número de perros NO borrados
            'blob_name' => null,

            // Plantillas (del tipo de producto)
            'logo_sociedad_path' => "logos/logo_{$seguro->id_sociedad}.png",
            'plantilla_path_1' => $subproducto['plantilla_1'],
            'plantilla_path_2' => null,
            'plantilla_path_3' => null,
            'plantilla_path_4' => null,
            'plantilla_path_5' => null,
            'plantilla_path_6' => null,
            'plantilla_path_7' => null,
            'plantilla_path_8' => null,

            // Horas
            'hora_de_emisión' => $fechaEmision ? date('H:i:s', strtotime($fechaEmision)) : null,
            'hora_de_inicio' => $fechaInicio ? date('H:i:s', strtotime($fechaInicio)) : null,
            'hora_de_fin' => $fechaFin ? date('H:i:s', strtotime($fechaFin)) : null,
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

    /**
     * Transformar datos de un perro a anexos_peas
     * 
     * ESTRATEGIAS DE PRECIO:
     * 
     * 1. EQUITATIVA (default): Divide el precio total del seguro entre todos los perros
     *    Ejemplo: 180€ / 2 perros = 90€ por perro
     * 
     * 2. FIJA: Precio fijo según el tipo de seguro
     *    - RC + Accidentes: 36€ por perro
     *    - RC: 30€ por perro
     * 
     * 3. PROPORCIONAL: Divide el precio pero con un mínimo de 30€
     *    Ejemplo: max(180€ / 2, 30€) = 90€
     */
    private function transformarDatosPerro($perro, $seguro, int $productoId, int $totalPerros): array
    {
        $precioTotalSeguro = (float) ($seguro->precio_seguro ?? 0);
        
        // ESTRATEGIA DE PRECIO - Elige la que prefieras
        $estrategia = $this->option('estrategia-precio');
        
        switch ($estrategia) {
            case 'fija':
                // ESTRATEGIA 1: PRECIO FIJO según tipo de seguro
                $precioPorPerro = match ($seguro->id_tipo_seguro_naturaleza) {
                    1, 2 => 36.00, // RC + Accidentes
                    3, 4 => 30.00, // RC
                    default => 36.00,
                };
                break;
                
            case 'proporcional':
                // ESTRATEGIA 2: PROPORCIONAL con mínimo de 30€
                $precioDividido = $totalPerros > 0 ? $precioTotalSeguro / $totalPerros : 0;
                $precioPorPerro = max($precioDividido, 30.00);
                break;
                
            case 'equitativa':
            default:
                // ESTRATEGIA 3: EQUITATIVA (divide el total)
                $precioPorPerro = $totalPerros > 0 ? $precioTotalSeguro / $totalPerros : 0;
                break;
        }

        // Calcular precio_base y extra_1
        // Asumimos que extra_1 es aproximadamente el 15.5% del total (como en el ejemplo)
        $precioBase = round($precioPorPerro * 0.845, 2); // ~84.5% es precio_base
        $extra1 = round($precioPorPerro * 0.155, 2);     // ~15.5% es extra_1 (cuota asociación)
        $precioTotal = $precioBase + $extra1;

        // Validar fechas
        $fechaAlta = $this->validarFecha($perro->fecha_alta);
        $fechaNacimientoPerro = $this->validarFecha($perro->fecha_nacimiento);

        // Usar las mismas fechas que el seguro principal
        $fechaEmision = $this->validarFecha($seguro->fecha_emision);
        $fechaInicio = $this->validarFecha($seguro->fecha_inicio);
        $fechaFin = $this->validarFecha($seguro->expiration_date);

        $datos = [
            // Relación con el producto
            'producto_id' => $productoId,

            // Precios del anexo individual
            'precio_base' => $precioBase,
            'extra_1' => $extra1,
            'extra_2' => 0,
            'extra_3' => 0,
            'precio_total' => $precioTotal,

            // Plantillas (heredadas del tipo de producto)
            'plantilla_path_1' => 'plantillas/1_lrms_page-0001 - copia 2 - copia - copia - copia - copia - copia - copia - copia - copia.jpg',
            'plantilla_path_2' => null,
            'plantilla_path_3' => null,
            'plantilla_path_4' => null,
            'plantilla_path_5' => null,
            'plantilla_path_6' => null,
            'plantilla_path_7' => null,
            'plantilla_path_8' => null,

            // Control
            'duracion' => $this->calcularDuracion($fechaInicio, $fechaFin),
            'anulado' => false,

            // Datos del perro
            'raza_del_perro' => $perro->raza ?? null,
            'microchip' => $perro->microchip ?? null,
            'nombre_del_perro' => $perro->nombre ?? null,
            'nombre_propietario' => $perro->nombre_propietario ?? null,
            'dni_propietario' => $perro->dni_propietario ?? null,

            // Horas
            'hora_de_emisión' => $fechaEmision ? date('H:i:s', strtotime($fechaEmision)) : null,
            'hora_de_inicio' => $fechaInicio ? date('H:i:s', strtotime($fechaInicio)) : null,
            'hora_de_fin' => $fechaFin ? date('H:i:s', strtotime($fechaFin)) : null,

            'blob_name' => null,
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
        
        if ($fechaNacimientoPerro) {
            $datos['fecha_de_nacimiento_del_perro'] = DB::raw("CONVERT(datetime, '{$fechaNacimientoPerro}', 120)");
        }

        $datos['created_at'] = $fechaEmision ? DB::raw("CONVERT(datetime, '{$fechaEmision}', 120)") : DB::raw("GETDATE()");
        $datos['updated_at'] = $fechaEmision ? DB::raw("CONVERT(datetime, '{$fechaEmision}', 120)") : DB::raw("GETDATE()");

        return $datos;
    }

    /**
     * Obtener subproducto desde el tipo de seguro naturaleza
     */
    private function obtenerSubproducto(?int $idTipoSeguroNaturaleza): array
    {
        if (!$idTipoSeguroNaturaleza || !isset($this->mapeoTiposSeguro[$idTipoSeguroNaturaleza])) {
            $this->stats['tipo_seguro_no_mapeado']++;
            
            Log::channel($this->logChannel)->warning("Tipo seguro no mapeado: {$idTipoSeguroNaturaleza}");

            return [
                'id' => null,
                'codigo' => 'RC Mascotas', // Por defecto
                'plantilla_1' => 'plantillas/Certificado Kyrema Servivio Juridico pag 1 - copia 2.jpg',
            ];
        }

        $tipoProductoId = $this->mapeoTiposSeguro[$idTipoSeguroNaturaleza];

        // Obtener datos del tipo_producto
        try {
            $tipoProducto = DB::connection('sqlsrv')
                ->table('tipo_producto')
                ->where('id', $tipoProductoId)
                ->first();

            return [
                'id' => (string) $tipoProducto->id,
                'codigo' => $tipoProducto->nombre,
                'plantilla_1' => $tipoProducto->plantilla_path_1 ?? 'plantillas/Certificado Kyrema Servivio Juridico pag 1 - copia 2.jpg',
            ];
        } catch (\Exception $e) {
            Log::channel($this->logChannel)->error("Error obteniendo tipo_producto: {$e->getMessage()}");
            
            return [
                'id' => (string) $tipoProductoId,
                'codigo' => 'RC Mascotas',
                'plantilla_1' => 'plantillas/Certificado Kyrema Servivio Juridico pag 1 - copia 2.jpg',
            ];
        }
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
        return null;
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
        return 1;
    }

    private function obtenerNombreComercial(?int $idUserAntiguo): string
    {
        // id_dominio_emision NO mapea a comercial, siempre retorna "Sistema"
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
            $cache[$socioId] = null;
            return null;
        }
    }

    private function registrarAnomaliasProducto($seguroOriginal, $datosTransformados): void
    {
        $id = $seguroOriginal->id_seguro;
        $poliza = $seguroOriginal->poliza_seguro;

        if (empty($datosTransformados['socio_id'])) {
            $this->anomalias['sin_socio'][] = [
                'id' => $id,
                'poliza' => $poliza,
            ];
        }

        if (empty($datosTransformados['subproducto'])) {
            $this->anomalias['tipo_seguro_no_mapeado'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'tipo_seguro_original' => $seguroOriginal->id_tipo_seguro_naturaleza,
            ];
        }

        if (($datosTransformados['precio_total'] ?? 0) == 0) {
            $this->anomalias['precio_cero'][] = [
                'id' => $id,
                'poliza' => $poliza,
            ];
        }

        if (empty($datosTransformados['fecha_de_inicio']) || empty($datosTransformados['fecha_de_fin'])) {
            $this->anomalias['fechas_invalidas'][] = [
                'id' => $id,
                'poliza' => $poliza,
            ];
        }
    }

    private function generarReporteAnomalias(): void
    {
        $logPath = storage_path('logs/migracion_anomalias_naturaleza.log');
        $contenido = [];

        $contenido[] = "================================================================================";
        $contenido[] = "   REPORTE DE ANOMALÍAS - MIGRACIÓN SEGUROS NATURALEZA";
        $contenido[] = "   Fecha: " . now()->format('Y-m-d H:i:s');
        $contenido[] = "   Seguros migrados: {$this->stats['seguros_migrados']}";
        $contenido[] = "   Perros migrados: {$this->stats['perros_migrados']}";
        $contenido[] = "   Estrategia de precio: " . $this->option('estrategia-precio');
        $contenido[] = "================================================================================";
        $contenido[] = "";

        $contenido[] = "RESUMEN DE ANOMALÍAS:";
        $contenido[] = str_repeat("-", 80);
        foreach ($this->anomalias as $tipo => $items) {
            $count = count($items);
            $porcentaje = $this->stats['seguros_migrados'] > 0 
                ? number_format(($count / $this->stats['seguros_migrados']) * 100, 2) 
                : 0;
            $contenido[] = sprintf("%-35s: %5d registros (%s%%)", 
                strtoupper(str_replace('_', ' ', $tipo)), 
                $count, 
                $porcentaje
            );
        }

        file_put_contents($logPath, implode("\n", $contenido));

        $this->newLine();
        $this->info("📋 RESUMEN DE ANOMALÍAS:");
        $this->newLine();

        $tablaAnomalias = [];
        foreach ($this->anomalias as $tipo => $items) {
            $count = count($items);
            if ($count > 0) {
                $porcentaje = $this->stats['seguros_migrados'] > 0 
                    ? number_format(($count / $this->stats['seguros_migrados']) * 100, 1) 
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
            $this->warn("📄 Ver detalles completos en: storage/logs/migracion_anomalias_naturaleza.log");
        } else {
            $this->info("✅ No se detectaron anomalías");
        }
    }

    private function modoDryRun(string $connection): void
    {
        $this->warn('🔍 MODO DRY-RUN - Mostrando 3 ejemplos con sus perros');
        $this->newLine();

        $ejemplos = DB::connection($connection)
            ->table('seguro_naturaleza')
            ->where('borrado', 0)
            ->limit(3)
            ->get();

        foreach ($ejemplos as $index => $seguro) {
            $this->info("=== Ejemplo " . ($index + 1) . " ===");
            $this->line("ID Seguro: {$seguro->id_seguro}");
            $this->line("Póliza: {$seguro->poliza_seguro}");
            $this->line("Precio Total: {$seguro->precio_seguro}€");

            // Obtener perros
            $perros = DB::connection($connection)
                ->table('seguro_naturaleza_perros')
                ->where('id_seguro_naturaleza', $seguro->id_seguro)
                ->where('borrado', 0)
                ->get();

            $numPerros = $perros->count();
            $this->line("Número de perros: {$numPerros}");

            $transformadoProducto = $this->transformarDatosProducto($seguro, $numPerros);

            $this->table(
                ['Campo Producto', 'Valor'],
                [
                    ['codigo_producto', $transformadoProducto['codigo_producto']],
                    ['precio_total', $transformadoProducto['precio_total']],
                    ['numero_anexos', $transformadoProducto['numero_anexos']],
                    ['subproducto', $transformadoProducto['subproducto']],
                ]
            );

            if ($numPerros > 0) {
                $this->line("\nPerros:");
                foreach ($perros as $pIndex => $perro) {
                    $transformadoPerro = $this->transformarDatosPerro($perro, $seguro, 999, $numPerros);
                    
                    $this->line("  Perro " . ($pIndex + 1) . ": {$perro->nombre}");
                    $this->line("    - Microchip: {$perro->microchip}");
                    $this->line("    - Precio calculado: {$transformadoPerro['precio_total']}€");
                    $this->line("    - (Base: {$transformadoPerro['precio_base']}€ + Extra: {$transformadoPerro['extra_1']}€)");
                }
            }

            $this->newLine();
        }

        $this->info('✅ Dry-run completado');
        $this->info('💡 Puedes cambiar la estrategia de precio con: --estrategia-precio=fija|equitativa|proporcional');
    }

    private function mostrarEstadisticas(): void
    {
        $this->info('📈 Estadísticas de migración:');
        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['📦 Total seguros', $this->stats['total_seguros']],
                ['✅ Seguros migrados', $this->stats['seguros_migrados']],
                ['⏭️  Seguros saltados', $this->stats['seguros_saltados']],
                ['❌ Seguros con errores', $this->stats['seguros_errores']],
                ['🐕 Total perros', $this->stats['total_perros']],
                ['✅ Perros migrados', $this->stats['perros_migrados']],
                ['🗑️  Perros borrados (ignorados)', $this->stats['perros_borrados']],
                ['👥 Comerciales mapeados', count($this->mapeoComerciales)],
                ['🏢 Sociedades mapeadas', count($this->mapeoSociedades)],
                ['👤 Socios mapeados', count($this->mapeoSociosPorId)],
            ]
        );

        if ($this->stats['seguros_errores'] > 0) {
            $this->error("⚠️  Hay {$this->stats['seguros_errores']} errores.");
            $this->warn("📝 Revisa los logs: storage/logs/migracion_seguros.log");
        }

        if ($this->option('test')) {
            $this->info('✅ Modo test completado - No se insertaron datos');
        } else {
            $this->info('✅ Migración completada exitosamente');
        }
    }

    // Métodos auxiliares
    private function mapearTipoPagoId(?int $idTipoPago): int
    {
        return match ($idTipoPago) {
            1 => 6, 2 => 5, 3 => 8, 4 => 10, 5 => 9,
            default => 6,
        };
    }

    private function mapearTipoPago(?int $idTipoPago): string
    {
        return match ($idTipoPago) {
            1 => 'No completado', 2 => 'Transferencia', 3 => 'Efectivo',
            4 => 'Giro bancario', 5 => 'Tarjeta',
            default => 'No completado',
        };
    }

    private function yaExiste(string $codigoProducto, string $connection): bool
    {
        return DB::connection($connection)
            ->table('producto_smk')
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
        if (!$fecha || $fecha == '0000-00-00') return null;

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
        if (!$inicio || !$fin) return '365';

        try {
            $fechaInicio = new \DateTime($inicio);
            $fechaFin = new \DateTime($fin);
            return (string) $fechaInicio->diff($fechaFin)->days;
        } catch (\Exception $e) {
            return '365';
        }
    }
}