<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MigrarSegurosServiciosJuridicos extends Command
{
    protected $signature = 'migrate:seguros-sjk 
                            {--limit= : Número máximo de registros a migrar}
                            {--offset=0 : Desde qué registro empezar}
                            {--test : Modo test - no inserta en BD}
                            {--dry-run : Muestra 5 ejemplos sin insertar}
                            {--force : Forzar migración sin confirmación}
                            {--rebuild-map : Reconstruir mapeo de comerciales y socios}
                            {--default-comercial=1 : ID comercial por defecto para no mapeados}';

    protected $description = 'Migrar registros de seguro_servicios_juridicos a producto_sjk';

    private $logChannel = 'migracion_seguros';

    private $stats = [
        'total' => 0,
        'migrados' => 0,
        'errores' => 0,
        'saltados' => 0,
        'sin_comercial' => 0,
        'sociedades_no_mapeadas' => 0,
        'socios_no_encontrados' => 0,
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
        'precio_cero' => [],
        'fechas_invalidas' => [],
        'sin_datos_club' => [],
    ];

    private $mapeoComerciales = [];
    private $mapeoSociedades = [];
    private $mapeoSociosPorId = [];

    public function handle()
    {
        // Limpiar logs
        $logPath = storage_path('logs/migracion_seguros.log');
        if (file_exists($logPath)) {
            file_put_contents($logPath, '');
        }

        $anomaliasPath = storage_path('logs/migracion_anomalias_sjk.log');
        if (file_exists($anomaliasPath)) {
            file_put_contents($anomaliasPath, '');
        }

        Log::channel($this->logChannel)->info('=== INICIO DE MIGRACIÓN SEGUROS SERVICIOS JURÍDICOS ===');
        Log::channel($this->logChannel)->info('Fecha: ' . now());

        $this->info('🚀 Iniciando migración de seguro_servicios_juridicos a producto_sjk');
        $this->info('📝 Logs detallados en: storage/logs/migracion_seguros.log');
        $this->info('⚠️  Anomalías en: storage/logs/migracion_anomalias_sjk.log');
        $this->newLine();

        // Construir mapeos
        $this->construirMapeoComerciales();
        $this->construirMapeoSociedades();
        $this->construirMapeoSociosPorId();

        // Configurar conexiones
        $oldConnection = 'mysql';
        $newConnection = 'sqlsrv';

        // Contar registros totales
        $totalRegistros = DB::connection($oldConnection)
            ->table('seguro_servicios_juridicos')
            ->count();

        $this->stats['total'] = $totalRegistros;
        $this->info("📊 Total de registros a migrar: {$totalRegistros}");
        Log::channel($this->logChannel)->info("Total registros: {$totalRegistros}");
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

        // Obtener registros
        $query = DB::connection($oldConnection)
            ->table('seguro_servicios_juridicos')
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
                    $this->stats['saltados']++;
                    Log::channel($this->logChannel)->info("⏭️  Saltado (ya existe): ID {$seguro->id_seguro} - {$seguro->poliza_seguro}");
                    $bar->advance();
                    continue;
                }

                $datosNuevos = $this->transformarDatos($seguro);

                // Registrar anomalías
                $this->registrarAnomalias($seguro, $datosNuevos);

                // Log detallado
                Log::channel($this->logChannel)->debug("Procesando seguro ID {$seguro->id_seguro}", [
                    'poliza' => $seguro->poliza_seguro,
                    'id_socio_antiguo' => $seguro->id_socio,
                    'socio_id_nuevo' => $datosNuevos['socio_id'],
                    'precio_total' => $datosNuevos['precio_total'],
                    'club' => $datosNuevos['nombre_club_o_sociedad'],
                ]);

                if (!$this->option('test')) {
                    DB::connection($newConnection)
                        ->table('producto_sjk')
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

    private function construirMapeoSociosPorId(): void
    {
        $cacheKey = 'mapeo_socios_id_sjk_v1';

        if (!$this->option('rebuild-map') && Cache::has($cacheKey)) {
            $this->mapeoSociosPorId = Cache::get($cacheKey);
            $this->info("✅ Mapeo de socios cargado desde caché (" . count($this->mapeoSociosPorId) . " socios)");
            Log::channel($this->logChannel)->info("Mapeo de socios desde caché: " . count($this->mapeoSociosPorId));
            return;
        }

        $this->info('🔍 Construyendo mapeo de socios por DNI...');
        Log::channel($this->logChannel)->info('Iniciando mapeo de socios');

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
            Log::channel($this->logChannel)->info("Socios mapeados: {$mapeados}");

        } catch (\Exception $e) {
            $this->error("Error construyendo mapeo de socios: {$e->getMessage()}");
            Log::channel($this->logChannel)->error("Error en mapeo de socios: {$e->getMessage()}");
        }
    }

    private function construirMapeoComerciales(): void
    {
        $cacheKey = 'mapeo_comerciales_migracion_v3';

        if (!$this->option('rebuild-map') && Cache::has($cacheKey)) {
            $this->mapeoComerciales = Cache::get($cacheKey);
            $this->info("✅ Mapeo de comerciales cargado desde caché (" . count($this->mapeoComerciales) . " comerciales)");
            Log::channel($this->logChannel)->info("Mapeo comerciales desde caché: " . count($this->mapeoComerciales));
            return;
        }

        $this->info('🔍 Construyendo mapeo de comerciales...');
        Log::channel($this->logChannel)->info('Iniciando mapeo de comerciales');

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
        Log::channel($this->logChannel)->info("Comerciales mapeados: {$mapeados}");
    }

    private function construirMapeoSociedades(): void
    {
        $this->info('🔍 Construyendo mapeo de sociedades...');
        Log::channel($this->logChannel)->info('Iniciando mapeo de sociedades');

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
            Log::channel($this->logChannel)->info("Sociedades mapeadas: {$mapeados}");
        } catch (\Exception $e) {
            $this->error("Error mapeando sociedades: {$e->getMessage()}");
            Log::channel($this->logChannel)->error("Error en sociedades: {$e->getMessage()}");
        }
    }

    private function transformarDatos($seguro): array
    {
        $comercialId = $this->obtenerComercialNuevoId($seguro->id_emisor);
        $sociedadId = $this->obtenerSociedadNuevaId($seguro->id_sociedad);
        $socioId = $this->obtenerSocioNuevoId($seguro->id_socio);

        // Obtener datos del socio
        $datosSocio = $this->obtenerDatosSocio($socioId);

        // Obtener datos del club (sociedad)
        $datosClub = $this->obtenerDatosClub($sociedadId);

        // Validar fechas
        $fechaEmision = $this->validarFecha($seguro->fecha_emision);
        $fechaInicio = $this->validarFecha($seguro->fecha_inicio);
        
        // Calcular fecha fin (1 año después de inicio)
        $fechaFin = null;
        if ($fechaInicio) {
            try {
                $dt = new \DateTime($fechaInicio);
                $dt->modify('+1 year');
                $fechaFin = $dt->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $fechaFin = null;
            }
        }
        
        // Fecha de nacimiento del socio
        $fechaNacimiento = null;
        if ($datosSocio && isset($datosSocio->fecha_de_nacimiento)) {
            $fechaNacimiento = $this->validarFechaNacimiento($datosSocio->fecha_de_nacimiento);
        }

        // Calcular precios
        $precioBase = (float) ($seguro->prima_seguro ?? 0);
        $extra1 = (float) ($seguro->cuota_asociacion ?? 0);
        $precioTotal = $precioBase + $extra1;

        // Log de datos del club
        Log::channel($this->logChannel)->debug("Datos del club para ID {$seguro->id_seguro}", [
            'id_sociedad' => $seguro->id_sociedad,
            'sociedad_id_nuevo' => $sociedadId,
            'nombre_club' => $datosClub['nombre'],
            'cif_club' => $datosClub['cif'],
        ]);

        $datos = [
            // IDs y control
            'sociedad_id' => $sociedadId,
            'tipo_de_pago_id' => $this->mapearTipoPagoId($seguro->id_tipopago),
            'tipo_de_pago' => $this->mapearTipoPago($seguro->id_tipopago),
            'comercial_id' => $comercialId,
            'comercial_creador_id' => $comercialId,
            'comercial' => $this->obtenerNombreComercial($seguro->id_emisor),
            'mediante_pagina_web' => false,
            'socio_id' => $socioId,
            'anulado' => (bool) $seguro->cancelado,
            'pago_id' => null,

            // Certificado/Póliza
            'codigo_producto' => $seguro->poliza_seguro,

            // Precios
            'precio_base' => $precioBase,
            'extra_1' => $extra1,
            'extra_2' => 0,
            'extra_3' => 0,
            'precio_total' => $precioTotal,
            'precio_final' => (string) $precioTotal,

            // Datos del socio
            'nombre_socio' => $datosSocio->nombre_socio ?? $this->extraerNombre($seguro->socio_nombre),
            'apellido_1' => $datosSocio->apellido_1 ?? $this->extraerApellido($seguro->socio_nombre, 1),
            'apellido_2' => $datosSocio->apellido_2 ?? $this->extraerApellido($seguro->socio_nombre, 2),
            'dni' => $datosSocio->dni ?? $seguro->socio_dni,
            'email' => $datosSocio->email ?? '',
            'telefono' => $datosSocio->telefono ?? null,
            'sexo' => $datosSocio->sexo ?? '',
            'dirección' => $datosSocio->direccion ?? null,
            'codigo_postal' => $datosSocio->codigo_postal ?? null,
            'población' => $datosSocio->poblacion ?? null,
            'provincia' => $datosSocio->provincia ?? null,

            // Datos del club/sociedad
            'nombre_club_o_sociedad' => $datosClub['nombre'],
            'cif_del_club' => $datosClub['cif'],
            'email_del_club' => $datosClub['email'],
            'teléfono_del_club' => $datosClub['telefono'],

            // Otros
            'duracion' => $this->calcularDuracion($fechaInicio, $fechaFin),
            'numero_anexos' => 0,
            'blob_name' => null,

            // Plantillas
            'logo_sociedad_path' => "logos/logo_{$seguro->id_sociedad}.png",
            'plantilla_path_1' => 'plantillas/Certificado Kyrema Servivio Juridico pag 1.jpg',
            'plantilla_path_2' => 'plantillas/Certificado Kyrema Servivio Juridico pag 2.jpg',
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
     * Obtener datos del club/sociedad
     */
    private function obtenerDatosClub(?int $sociedadId): array
    {
        if (!$sociedadId) {
            return [
                'nombre' => null,
                'cif' => null,
                'email' => null,
                'telefono' => null,
            ];
        }

        static $cache = [];

        if (isset($cache[$sociedadId])) {
            return $cache[$sociedadId];
        }

        try {
            $sociedad = DB::connection('sqlsrv')
                ->table('sociedad')
                ->where('id', $sociedadId)
                ->first();

            if ($sociedad) {
                $datos = [
                    'nombre' => $sociedad->nombre ?? null,
                    'cif' => $sociedad->cif ?? null,
                    'email' => $sociedad->correo_electronico ?? null,
                    'telefono' => $sociedad->telefono ?? $sociedad->movil ?? null,
                ];

                $cache[$sociedadId] = $datos;
                return $datos;
            }

            $cache[$sociedadId] = [
                'nombre' => null,
                'cif' => null,
                'email' => null,
                'telefono' => null,
            ];

            return $cache[$sociedadId];

        } catch (\Exception $e) {
            Log::channel($this->logChannel)->warning("Error obteniendo datos de sociedad ID {$sociedadId}: {$e->getMessage()}");
            
            $datos = [
                'nombre' => null,
                'cif' => null,
                'email' => null,
                'telefono' => null,
            ];
            
            $cache[$sociedadId] = $datos;
            return $datos;
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
        Log::channel($this->logChannel)->debug("Socio no encontrado: ID {$idSocioAntiguo}");
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
            Log::channel($this->logChannel)->warning("Error obteniendo socio ID {$socioId}: {$e->getMessage()}");
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

        if (empty($datosTransformados['socio_id'])) {
            $this->anomalias['sin_socio'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto ?: 'SIN NOMBRE',
                'dni' => $seguroOriginal->socio_dni,
            ];
        }

        if ($datosTransformados['comercial_id'] == $this->option('default-comercial')) {
            $this->anomalias['sin_comercial'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto,
            ];
        }

        if (($datosTransformados['precio_total'] ?? 0) == 0) {
            $this->anomalias['precio_cero'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto,
            ];
        }

        // Verificar si faltan datos del club
        if (empty($datosTransformados['nombre_club_o_sociedad']) ||
            empty($datosTransformados['cif_del_club'])) {
            $this->anomalias['sin_datos_club'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'id_sociedad_original' => $seguroOriginal->id_sociedad,
            ];
        }

        if (empty($datosTransformados['sexo'])) {
            $this->anomalias['sin_sexo'][] = ['id' => $id, 'poliza' => $poliza, 'nombre' => $nombreCompleto];
        }

        if (empty($datosTransformados['email'])) {
            $this->anomalias['sin_email'][] = ['id' => $id, 'poliza' => $poliza, 'nombre' => $nombreCompleto];
        }

        if (empty($datosTransformados['telefono'])) {
            $this->anomalias['sin_telefono'][] = ['id' => $id, 'poliza' => $poliza, 'nombre' => $nombreCompleto];
        }
    }

    private function generarReporteAnomalias(): void
    {
        $logPath = storage_path('logs/migracion_anomalias_sjk.log');
        $contenido = [];

        $contenido[] = "================================================================================";
        $contenido[] = "   REPORTE DE ANOMALÍAS - MIGRACIÓN SEGUROS SERVICIOS JURÍDICOS";
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
            $contenido[] = sprintf("%-35s: %5d registros (%s%%)", 
                strtoupper(str_replace('_', ' ', $tipo)), 
                $count, 
                $porcentaje
            );
        }
        $contenido[] = "";

        file_put_contents($logPath, implode("\n", $contenido));

        Log::channel($this->logChannel)->info("Reporte de anomalías generado");

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
            $this->warn("📄 Ver detalles completos en: storage/logs/migracion_anomalias_sjk.log");
        } else {
            $this->info("✅ No se detectaron anomalías");
        }
    }

    private function modoDryRun(string $connection): void
    {
        $this->warn('🔍 MODO DRY-RUN - Mostrando 5 ejemplos');
        $this->newLine();

        Log::channel($this->logChannel)->info('Ejecutando DRY-RUN');

        $ejemplos = DB::connection($connection)
            ->table('seguro_servicios_juridicos')
            ->limit(5)
            ->get();

        foreach ($ejemplos as $index => $seguro) {
            $this->info("Ejemplo " . ($index + 1) . ":");
            $this->line("ID: {$seguro->id_seguro}");
            $this->line("Póliza: {$seguro->poliza_seguro}");
            $this->line("DNI: {$seguro->socio_dni}");

            $transformado = $this->transformarDatos($seguro);

            $camposClave = [
                'codigo_producto',
                'socio_id',
                'precio_total',
                'nombre_club_o_sociedad',
                'cif_del_club',
            ];

            $tabla = [];
            foreach ($camposClave as $campo) {
                if (isset($transformado[$campo])) {
                    $tabla[] = [$campo, $this->formatearValor($transformado[$campo])];
                }
            }

            $this->table(['Campo', 'Valor'], $tabla);
            $this->newLine();
        }

        $this->info('✅ Dry-run completado');
        Log::channel($this->logChannel)->info('DRY-RUN completado');
    }

    private function formatearValor($valor): string
    {
        if (is_null($valor)) return 'NULL';
        if (is_bool($valor)) return $valor ? 'true' : 'false';
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
                ['Total registros', $this->stats['total']],
                ['✅ Migrados', $this->stats['migrados']],
                ['⏭️  Saltados', $this->stats['saltados']],
                ['❌ Errores', $this->stats['errores']],
                ['👥 Comerciales mapeados', count($this->mapeoComerciales)],
                ['🏢 Sociedades mapeadas', count($this->mapeoSociedades)],
                ['👤 Socios mapeados', count($this->mapeoSociosPorId)],
            ]
        );

        if ($this->stats['errores'] > 0) {
            $this->error("⚠️  Hay {$this->stats['errores']} errores.");
            $this->warn("📝 Revisa los logs: storage/logs/migracion_seguros.log");
        }

        if ($this->option('test')) {
            $this->info('✅ Modo test completado - No se insertaron datos');
        } else {
            $this->info('✅ Migración completada exitosamente');
        }

        Log::channel($this->logChannel)->info('=== FIN DE MIGRACIÓN SEGUROS SERVICIOS JURÍDICOS ===');
        Log::channel($this->logChannel)->info('Estadísticas finales:', $this->stats);
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
            ->table('producto_sjk')
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

    private function extraerNombre(?string $nombreCompleto): ?string
    {
        if (!$nombreCompleto) return null;
        $partes = explode(' ', trim($nombreCompleto));
        return $partes[0] ?? null;
    }

    private function extraerApellido(?string $nombreCompleto, int $pos): ?string
    {
        if (!$nombreCompleto) return null;
        $partes = array_slice(explode(' ', trim($nombreCompleto)), 1);
        return $partes[$pos - 1] ?? null;
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