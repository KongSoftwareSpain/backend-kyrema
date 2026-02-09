<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MigrarSegurosRehalas extends Command
{
    protected $signature = 'migrate:seguros-rehalas 
                            {--limit= : Número máximo de registros a migrar}
                            {--offset=0 : Desde qué registro empezar}
                            {--test : Modo test - no inserta en BD}
                            {--dry-run : Muestra 5 ejemplos sin insertar}
                            {--force : Forzar migración sin confirmación}
                            {--rebuild-map : Reconstruir mapeo de comerciales y socios}
                            {--default-comercial=1 : ID comercial por defecto para no mapeados}';

    protected $description = 'Migrar registros de seguro_rehalas a producto_rehal';

    private $logChannel = 'migracion_seguros';

    private $stats = [
        'total' => 0,
        'migrados' => 0,
        'errores' => 0,
        'saltados' => 0,
        'sin_comercial' => 0,
        'sociedades_no_mapeadas' => 0,
        'socios_no_encontrados' => 0,
        'seguros_aer' => 0,
        'seguros_kyrema' => 0,
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
        'sin_cobertura' => [],
        'sin_cantidad_perros' => [],
    ];

    private $mapeoComerciales = [];
    private $mapeoSociedades = [];
    private $mapeoSociosPorId = [];

    // ID de la sociedad AER
    private const SOCIEDAD_AER = 103;

    public function handle()
    {
        // Limpiar logs
        $logPath = storage_path('logs/migracion_seguros.log');
        if (file_exists($logPath)) {
            file_put_contents($logPath, '');
        }

        $anomaliasPath = storage_path('logs/migracion_anomalias_rehalas.log');
        if (file_exists($anomaliasPath)) {
            file_put_contents($anomaliasPath, '');
        }

        Log::channel($this->logChannel)->info('=== INICIO DE MIGRACIÓN SEGUROS REHALAS ===');
        Log::channel($this->logChannel)->info('Fecha: ' . now());

        $this->info('🚀 Iniciando migración de seguro_rehalas a producto_rehal');
        $this->info('📝 Logs detallados en: storage/logs/migracion_seguros.log');
        $this->info('⚠️  Anomalías en: storage/logs/migracion_anomalias_rehalas.log');
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
            ->table('seguro_rehalas')
            ->count();

        // Estadísticas por tipo
        $totalAER = DB::connection($oldConnection)
            ->table('seguro_rehalas')
            ->where('id_sociedad', self::SOCIEDAD_AER)
            ->count();

        $totalKyrema = $totalRegistros - $totalAER;

        $this->stats['total'] = $totalRegistros;
        $this->info("📊 Total de registros a migrar: {$totalRegistros}");
        $this->info("   └─ Seguros AER (sociedad 103): {$totalAER}");
        $this->info("   └─ Seguros Kyrema (otras sociedades): {$totalKyrema}");
        Log::channel($this->logChannel)->info("Total: {$totalRegistros} | AER: {$totalAER} | Kyrema: {$totalKyrema}");
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
            ->table('seguro_rehalas')
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

                // Contar por tipo
                if ($seguro->id_sociedad == self::SOCIEDAD_AER) {
                    $this->stats['seguros_aer']++;
                } else {
                    $this->stats['seguros_kyrema']++;
                }

                // Log detallado
                Log::channel($this->logChannel)->debug("Procesando seguro ID {$seguro->id_seguro}", [
                    'poliza' => $seguro->poliza_seguro,
                    'sociedad' => $seguro->id_sociedad,
                    'tipo' => $seguro->id_sociedad == self::SOCIEDAD_AER ? 'AER' : 'KYREMA',
                    'id_socio_antiguo' => $seguro->id_socio,
                    'socio_id_nuevo' => $datosNuevos['socio_id'],
                    'precio_total' => $datosNuevos['precio_total'],
                    'cobertura' => $datosNuevos['ambito_de_cobertura'],
                    'perros' => $datosNuevos['nº_de_perros'],
                ]);

                if (!$this->option('test')) {
                    DB::connection($newConnection)
                        ->table('producto_rehal')
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
        $cacheKey = 'mapeo_socios_id_rehalas_v1';

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
                ->keyBy(function ($socio) {
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

            $porcentaje = $sociosAntiguos->count() > 0
                ? round(($mapeados / $sociosAntiguos->count()) * 100, 1)
                : 0;
            $this->info("   Cobertura: {$porcentaje}%");
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
                if (
                    $antiguo->email && $nuevo->email &&
                    strtolower(trim($antiguo->email)) === strtolower(trim($nuevo->email))
                ) {

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

        // Determinar si es AER o Kyrema
        $esAER = ($seguro->id_sociedad == self::SOCIEDAD_AER);

        // Obtener datos del socio
        $datosSocio = $this->obtenerDatosSocio($socioId);

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

        // Mapear cobertura y cantidad de perros (LÓGICA CONDICIONAL)
        $cobertura = $this->mapearCobertura($seguro->id_cobertura);
        $cantidadPerros = $this->mapearCantidadPerros($seguro->id_cantidad, $esAER);

        // Log de mapeos
        Log::channel($this->logChannel)->debug("Mapeos para ID {$seguro->id_seguro}", [
            'tipo' => $esAER ? 'AER' : 'KYREMA',
            'id_cobertura' => $seguro->id_cobertura,
            'cobertura_mapeada' => $cobertura,
            'id_cantidad' => $seguro->id_cantidad,
            'cantidad_mapeada' => $cantidadPerros,
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

            // Subproducto - Rehalas Kyrema España (producto REKE)
            'subproducto' => '232', // Ajusta este ID si es necesario
            'subproducto_codigo' => 'Rehala Kyrema España',

            // Datos específicos de Rehalas
            'nº_de_perros' => $cantidadPerros,
            'ambito_de_cobertura' => $cobertura,
            'nº_de_socio' => $seguro->n_socio,

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

            // Otros
            'duracion' => $this->calcularDuracion($fechaInicio, $fechaFin),
            'numero_anexos' => 0,
            'blob_name' => null,

            // Plantillas
            'logo_sociedad_path' => "logos/logo_{$seguro->id_sociedad}.png",
            'plantilla_path_1' => 'plantillas/Certificado rehalas Kyrema pag1. 2025.jpg',
            'plantilla_path_2' => 'plantillas/Certificado rehalas Kyrema pag2. 2.025.jpg',
            'plantilla_path_3' => 'plantillas/Certificado rehalas Kyrema pag3. 2.025.jpg',
            'plantilla_path_4' => 'plantillas/Certificado rehalas Kyrema pag4. 2.025.jpg',
            'plantilla_path_5' => 'plantillas/Parte siniestro RC rehalas Kyrema 2.025.jpg',
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
     * Mapear id_cobertura a texto
     */
    private function mapearCobertura(?int $idCobertura): ?string
    {
        return match ($idCobertura) {
            0 => 'No especificado',
            1 => 'España',
            2 => 'España y Portugal',
            default => null,
        };
    }

    /**
     * Mapear id_cantidad a número de perros
     * LÓGICA CONDICIONAL según si es AER o Kyrema
     * 
     * @param int|null $idCantidad
     * @param bool $esAER
     * @return string|null
     */
    private function mapearCantidadPerros(?int $idCantidad, bool $esAER = false): ?string
    {
        if ($esAER) {
            // Rangos AER (sociedad 103)
            // id_cantidad 5-8 con rangos: 0-40, 41-70, 71-105, 106-140
            return match ($idCantidad) {
                0 => 'Hasta 35',      // Por defecto
                5 => 'Hasta 35',      // 0-40 perros → Hasta 35 (aproximado)
                6 => 'Hasta 70',      // 41-70 perros → Hasta 70
                7 => 'Hasta 105',     // 71-105 perros → Hasta 105
                8 => 'Más de 105',    // 106-140 perros → Más de 105
                default => null,
            };
        } else {
            // Rangos Kyrema (general)
            // id_cantidad 1-4 con rangos: 0-35, 36-70, 71-105, 106-140
            return match ($idCantidad) {
                0 => 'Hasta 35',      // Por defecto
                1 => 'Hasta 35',      // 0-35 perros
                2 => 'Hasta 70',      // 36-70 perros
                3 => 'Hasta 105',     // 71-105 perros
                4 => 'Más de 105',    // 106-140 perros
                default => null,
            };
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

        if (empty($datosTransformados['ambito_de_cobertura'])) {
            $this->anomalias['sin_cobertura'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'id_cobertura_original' => $seguroOriginal->id_cobertura,
            ];
        }

        if (empty($datosTransformados['nº_de_perros'])) {
            $this->anomalias['sin_cantidad_perros'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'id_cantidad_original' => $seguroOriginal->id_cantidad,
                'id_sociedad' => $seguroOriginal->id_sociedad,
            ];
        }

        if (($datosTransformados['precio_total'] ?? 0) == 0) {
            $this->anomalias['precio_cero'][] = [
                'id' => $id,
                'poliza' => $poliza,
                'nombre' => $nombreCompleto,
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
        $logPath = storage_path('logs/migracion_anomalias_rehalas.log');
        $contenido = [];

        $contenido[] = "================================================================================";
        $contenido[] = "   REPORTE DE ANOMALÍAS - MIGRACIÓN SEGUROS REHALAS";
        $contenido[] = "   Fecha: " . now()->format('Y-m-d H:i:s');
        $contenido[] = "   Total migrados: {$this->stats['migrados']}";
        $contenido[] = "   - Seguros AER: {$this->stats['seguros_aer']}";
        $contenido[] = "   - Seguros Kyrema: {$this->stats['seguros_kyrema']}";
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
            $this->warn("📄 Ver detalles completos en: storage/logs/migracion_anomalias_rehalas.log");
        } else {
            $this->info("✅ No se detectaron anomalías");
        }
    }

    private function modoDryRun(string $connection): void
    {
        $this->warn('🔍 MODO DRY-RUN - Mostrando ejemplos de AER y Kyrema');
        $this->newLine();

        Log::channel($this->logChannel)->info('Ejecutando DRY-RUN');

        // 3 ejemplos de AER
        $this->info("=== EJEMPLOS AER (sociedad 103) ===");
        $ejemplosAER = DB::connection($connection)
            ->table('seguro_rehalas')
            ->where('id_sociedad', self::SOCIEDAD_AER)
            ->limit(3)
            ->get();

        foreach ($ejemplosAER as $index => $seguro) {
            $this->info("AER Ejemplo " . ($index + 1) . ":");
            $this->line("ID: {$seguro->id_seguro} | Póliza: {$seguro->poliza_seguro}");
            $this->line("id_cantidad: {$seguro->id_cantidad}");

            $transformado = $this->transformarDatos($seguro);
            $this->line("→ Mapeado a: {$transformado['nº_de_perros']}");
            $this->newLine();
        }

        // 2 ejemplos de Kyrema
        $this->info("=== EJEMPLOS KYREMA (otras sociedades) ===");
        $ejemplosKyrema = DB::connection($connection)
            ->table('seguro_rehalas')
            ->where('id_sociedad', '!=', self::SOCIEDAD_AER)
            ->limit(2)
            ->get();

        foreach ($ejemplosKyrema as $index => $seguro) {
            $this->info("Kyrema Ejemplo " . ($index + 1) . ":");
            $this->line("ID: {$seguro->id_seguro} | Póliza: {$seguro->poliza_seguro}");
            $this->line("id_cantidad: {$seguro->id_cantidad}");

            $transformado = $this->transformarDatos($seguro);
            $this->line("→ Mapeado a: {$transformado['nº_de_perros']}");
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
                ['   └─ Seguros AER', $this->stats['seguros_aer']],
                ['   └─ Seguros Kyrema', $this->stats['seguros_kyrema']],
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

        Log::channel($this->logChannel)->info('=== FIN DE MIGRACIÓN SEGUROS REHALAS ===');
        Log::channel($this->logChannel)->info('Estadísticas finales:', $this->stats);
    }

    // Métodos auxiliares
    private function mapearTipoPagoId(?int $idTipoPago): int
    {
        return match ($idTipoPago) {
            1 => 6,
            2 => 5,
            3 => 8,
            4 => 10,
            5 => 9,
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
            ->table('producto_rehal')
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
