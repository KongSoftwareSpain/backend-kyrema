<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MigrarSegurosCaceria extends Command
{
    protected $signature = 'migrate:seguros-caceria 
                            {--limit= : Número máximo de registros a migrar}
                            {--offset=0 : Desde qué registro empezar}
                            {--test : Modo test - no inserta en BD}
                            {--dry-run : Muestra 5 ejemplos sin insertar}
                            {--force : Forzar migración sin confirmación}
                            {--rebuild-map : Reconstruir mapeo de comerciales y socios}
                            {--default-comercial=1 : ID comercial por defecto para no mapeados}';

    protected $description = 'Migrar registros de seguro_cacerias a producto_c';

    // Canal de logs específico
    private $logChannel = 'migracion_seguros';

    private $stats = [
        'total' => 0,
        'migrados' => 0,
        'errores' => 0,
        'saltados' => 0,
        'sin_comercial' => 0,
        'sociedades_no_mapeadas' => 0,
        'socios_no_encontrados' => 0,
        'socios_mapeados_dni' => 0,
    ];

    // NUEVO: Registro de anomalías con detalles
    private $anomalias = [
        'sin_socio' => [],
        'sin_comercial' => [],
        'sin_sexo' => [],
        'sin_email' => [],
        'sin_telefono' => [],
        'sin_direccion' => [],
        'sin_fecha_nacimiento' => [],
        'sociedad_por_defecto' => [],
        'sin_tipo_caceria' => [],
        'sin_ubicacion' => [],
        'precio_cero' => [],
    ];

    private $mapeoComerciales = [];
    private $mapeoSociedades = [];
    private $mapeoSociosPorDNI = [];
    private $tiposCaceria = [];

    public function handle()
    {
        // Limpiar log anterior
        $logPath = storage_path('logs/migracion_seguros.log');
        if (file_exists($logPath)) {
            file_put_contents($logPath, '');
        }

        // NUEVO: Limpiar log de anomalías
        $anomaliasPath = storage_path('logs/migracion_anomalias.log');
        if (file_exists($anomaliasPath)) {
            file_put_contents($anomaliasPath, '');
        }

        Log::channel($this->logChannel)->info('=== INICIO DE MIGRACIÓN ===');
        Log::channel($this->logChannel)->info('Fecha: ' . now());

        $this->info('🚀 Iniciando migración de seguro_cacerias a producto_c');
        $this->info('📝 Logs detallados en: storage/logs/migracion_seguros.log');
        $this->info('⚠️  Anomalías en: storage/logs/migracion_anomalias.log');
        $this->newLine();

        // Construir todos los mapeos
        $this->construirMapeoComerciales();
        $this->construirMapeoSociedades();
        $this->construirMapeoSociosPorDNI();
        $this->precargarTiposCaceria();

        // Configurar conexiones
        $oldConnection = 'mysql';
        $newConnection = 'sqlsrv';

        // Contar registros totales
        $totalRegistros = DB::connection($oldConnection)
            ->table('seguro_cacerias')
            ->where('borrado', 0)
            ->count();

        $this->stats['total'] = $totalRegistros;
        $this->info("📊 Total de registros a migrar: {$totalRegistros}");

        // Modo dry-run: mostrar ejemplos
        if ($this->option('dry-run')) {
            $this->modoDryRun($oldConnection);
            return 0;
        }

        // Confirmar antes de proceder
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
            ->table('seguro_cacerias')
            ->where('borrado', 0)
            ->orderBy('id_seguro_caceria');

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
                if ($this->yaExiste($seguro->numero_certificado, $newConnection)) {
                    $this->stats['saltados']++;
                    $bar->advance();
                    continue;
                }

                $datosNuevos = $this->transformarDatos($seguro);

                // NUEVO: Registrar anomalías ANTES de insertar
                $this->registrarAnomalias($seguro, $datosNuevos);

                // Log detallado antes de insertar
                Log::channel($this->logChannel)->debug("Procesando seguro ID {$seguro->id_seguro_caceria}", [
                    'certificado' => $seguro->numero_certificado,
                    'suma_asegurada' => $seguro->suma_asegurada,
                    'subproducto_buscado' => $datosNuevos['subproducto_codigo'],
                    'subproducto_id' => $datosNuevos['subproducto'],
                    'socio_id' => $datosNuevos['socio_id'],
                    'tipo_pago' => $datosNuevos['tipo_de_pago'],
                ]);

                if (!$this->option('test')) {
                    DB::connection($newConnection)
                        ->table('producto_c')
                        ->insert($datosNuevos);
                }

                $this->stats['migrados']++;
                Log::channel($this->logChannel)->info("✅ Migrado: ID {$seguro->id_seguro_caceria} - {$seguro->numero_certificado}");
            } catch (\Exception $e) {
                $this->stats['errores']++;

                // Log muy detallado del error
                Log::channel($this->logChannel)->error("❌ ERROR en ID {$seguro->id_seguro_caceria}", [
                    'certificado' => $seguro->numero_certificado,
                    'error' => $e->getMessage(),
                    'linea' => $e->getLine(),
                    'archivo' => basename($e->getFile()),
                    'suma_asegurada' => $seguro->suma_asegurada,
                    'tipo_pago' => $seguro->tipo_pago,
                    'nombre_organizador' => $seguro->nombre_organizador,
                    'dni_organizador' => $seguro->dni_cif_organizador,
                ]);

                // También log en el canal default para compatibilidad
                Log::error("Error migrando ID {$seguro->id_seguro_caceria}: {$e->getMessage()}", [
                    'seguro' => $seguro,
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

    /**
     * NUEVO: Registrar anomalías detectadas durante la transformación
     */
    private function registrarAnomalias($seguroOriginal, $datosTransformados): void
    {
        $id = $seguroOriginal->id_seguro_caceria;
        $nombreCompleto = trim(($datosTransformados['nombre_socio'] ?? '') . ' ' .
            ($datosTransformados['apellido_1'] ?? '') . ' ' .
            ($datosTransformados['apellido_2'] ?? ''));
        $certificado = $seguroOriginal->numero_certificado;

        // SIN SOCIO (no se encontró en la BD por DNI)
        if (empty($datosTransformados['socio_id'])) {
            $this->anomalias['sin_socio'][] = [
                'id' => $id,
                'certificado' => $certificado,
                'nombre' => $nombreCompleto ?: 'SIN NOMBRE',
                'dni' => $seguroOriginal->dni_cif_organizador ?? 'SIN DNI',
            ];
        }

        // SIN COMERCIAL (usó el comercial por defecto)
        if (
            $datosTransformados['comercial_id'] == $this->option('default-comercial') &&
            $datosTransformados['comercial'] === 'Sistema'
        ) {
            $this->anomalias['sin_comercial'][] = [
                'id' => $id,
                'certificado' => $certificado,
                'nombre' => $nombreCompleto,
                'emisor_original' => $seguroOriginal->id_emisor ?? 'NULL',
            ];
        }

        // SIN SEXO
        if (empty($datosTransformados['sexo']) || trim($datosTransformados['sexo']) === '') {
            $this->anomalias['sin_sexo'][] = [
                'id' => $id,
                'certificado' => $certificado,
                'nombre' => $nombreCompleto,
                'dni' => $datosTransformados['dni'] ?? 'SIN DNI',
            ];
        }

        // SIN EMAIL
        if (empty($datosTransformados['email']) || trim($datosTransformados['email']) === '') {
            $this->anomalias['sin_email'][] = [
                'id' => $id,
                'certificado' => $certificado,
                'nombre' => $nombreCompleto,
            ];
        }

        // SIN TELÉFONO
        if (empty($datosTransformados['telefono'])) {
            $this->anomalias['sin_telefono'][] = [
                'id' => $id,
                'certificado' => $certificado,
                'nombre' => $nombreCompleto,
            ];
        }

        // SIN DIRECCIÓN
        if (empty($datosTransformados['dirección'])) {
            $this->anomalias['sin_direccion'][] = [
                'id' => $id,
                'certificado' => $certificado,
                'nombre' => $nombreCompleto,
            ];
        }

        // SIN FECHA DE NACIMIENTO
        if (empty($datosTransformados['fecha_de_nacimiento'])) {
            $this->anomalias['sin_fecha_nacimiento'][] = [
                'id' => $id,
                'certificado' => $certificado,
                'nombre' => $nombreCompleto,
            ];
        }

        // SOCIEDAD POR DEFECTO (ID = 1)
        if ($datosTransformados['sociedad_id'] == 1 && $seguroOriginal->id_sociedad != 1) {
            $this->anomalias['sociedad_por_defecto'][] = [
                'id' => $id,
                'certificado' => $certificado,
                'nombre' => $nombreCompleto,
                'sociedad_original' => $seguroOriginal->id_sociedad,
            ];
        }

        // SIN TIPO DE CACERÍA
        if (empty($datosTransformados['tipo_de_cacería'])) {
            $this->anomalias['sin_tipo_caceria'][] = [
                'id' => $id,
                'certificado' => $certificado,
                'nombre' => $nombreCompleto,
                'tipo_original' => $seguroOriginal->tipo_caceria ?? 'NULL',
            ];
        }

        // SIN UBICACIÓN (finca/lugar)
        if (
            empty($datosTransformados['finca_o_lugar_de_evento']) ||
            empty($datosTransformados['poblacion'])
        ) {
            $this->anomalias['sin_ubicacion'][] = [
                'id' => $id,
                'certificado' => $certificado,
                'nombre' => $nombreCompleto,
                'lugar' => $seguroOriginal->lugar ?? 'NULL',
                'poblacion' => $seguroOriginal->poblacion ?? 'NULL',
            ];
        }

        // PRECIO CERO
        if (($datosTransformados['precio_total'] ?? 0) == 0) {
            $this->anomalias['precio_cero'][] = [
                'id' => $id,
                'certificado' => $certificado,
                'nombre' => $nombreCompleto,
                'precio_base' => $seguroOriginal->precio_seguro ?? 0,
                'cuota_kyrema' => $seguroOriginal->cuota_kyrema ?? 0,
            ];
        }
    }

    /**
     * NUEVO: Generar reporte de anomalías en archivo de log separado
     */
    private function generarReporteAnomalias(): void
    {
        $logPath = storage_path('logs/migracion_anomalias.log');
        $contenido = [];

        $contenido[] = "================================================================================";
        $contenido[] = "   REPORTE DE ANOMALÍAS - MIGRACIÓN SEGUROS DE CACERÍA";
        $contenido[] = "   Fecha: " . now()->format('Y-m-d H:i:s');
        $contenido[] = "   Total migrados: {$this->stats['migrados']}";
        $contenido[] = "================================================================================";
        $contenido[] = "";

        // Resumen ejecutivo
        $contenido[] = "RESUMEN DE ANOMALÍAS:";
        $contenido[] = str_repeat("-", 80);
        foreach ($this->anomalias as $tipo => $items) {
            $count = count($items);
            $porcentaje = $this->stats['migrados'] > 0
                ? number_format(($count / $this->stats['migrados']) * 100, 2)
                : 0;
            $contenido[] = sprintf(
                "%-30s: %5d registros (%s%%)",
                strtoupper(str_replace('_', ' ', $tipo)),
                $count,
                $porcentaje
            );
        }
        $contenido[] = "";
        $contenido[] = "";

        // Detalle de cada anomalía
        foreach ($this->anomalias as $tipo => $items) {
            if (empty($items)) {
                continue;
            }

            $contenido[] = str_repeat("=", 80);
            $contenido[] = strtoupper(str_replace('_', ' ', $tipo)) . " ({$count} registros)";
            $contenido[] = str_repeat("=", 80);
            $contenido[] = "";

            foreach ($items as $item) {
                $contenido[] = "ID: {$item['id']} | Certificado: {$item['certificado']}";
                $contenido[] = "Nombre: {$item['nombre']}";

                // Información adicional específica del tipo
                unset($item['id'], $item['certificado'], $item['nombre']);
                foreach ($item as $key => $value) {
                    $contenido[] = "  - " . ucfirst($key) . ": " . ($value ?? 'NULL');
                }

                $contenido[] = "";
            }

            $contenido[] = "";
        }

        // Escribir al archivo
        file_put_contents($logPath, implode("\n", $contenido));

        // Mostrar resumen en consola
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
            $this->warn("📄 Ver detalles completos en: storage/logs/migracion_anomalias.log");
        } else {
            $this->info("✅ No se detectaron anomalías");
        }
    }

    /**
     * Modo dry-run: muestra ejemplos sin insertar
     */
    private function modoDryRun(string $connection): void
    {
        $this->warn('🔍 MODO DRY-RUN - Mostrando 5 ejemplos de transformación');
        $this->newLine();

        $ejemplos = DB::connection($connection)
            ->table('seguro_cacerias')
            ->where('borrado', 0)
            ->limit(5)
            ->get();

        foreach ($ejemplos as $index => $seguro) {
            $this->info("Ejemplo " . ($index + 1) . ":");
            $this->line("ID Original: {$seguro->id_seguro_caceria}");
            $this->line("Certificado: {$seguro->numero_certificado}");
            $this->line("DNI Organizador: {$seguro->dni_cif_organizador}");

            $transformado = $this->transformarDatos($seguro);

            // Mostrar solo campos clave
            $camposClave = [
                'codigo_producto',
                'sociedad_id',
                'socio_id',
                'comercial_id',
                'tipo_de_pago_id',
                'precio_total',
                'tipo_de_cacería',
                'dni',
                'nombre_socio',
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

    /**
     * Pre-cargar tipos de cacería para evitar consultas repetidas
     */
    private function precargarTiposCaceria(): void
    {
        $this->info('🔍 Pre-cargando tipos de cacería...');

        try {
            $tipos = DB::connection('mysql')
                ->table('tipo_caceria')
                ->get()
                ->keyBy('id_tipo_caceria');

            foreach ($tipos as $tipo) {
                $this->tiposCaceria[$tipo->id_tipo_caceria] =
                    $tipo->nombre_tipo_caceria
                    ?? $tipo->nombre
                    ?? 'Desconocido';
            }

            $this->info("✅ {$tipos->count()} tipos de cacería pre-cargados");
        } catch (\Exception $e) {
            $this->warn("⚠️  Error pre-cargando tipos de cacería: {$e->getMessage()}");
        }
    }

    /**
     * Construir mapeo de comerciales (users → comercial)
     */
    private function construirMapeoComerciales(): void
    {
        $cacheKey = 'mapeo_comerciales_migracion_v3';

        if (!$this->option('rebuild-map') && Cache::has($cacheKey)) {
            $this->mapeoComerciales = Cache::get($cacheKey);
            $this->info("✅ Mapeo de comerciales cargado desde caché ({$this->countMapeados('comerciales')} comerciales)");
            return;
        }

        $this->info('🔍 Construyendo mapeo de comerciales (por EMAIL)...');

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
    }

    /**
     * Construir mapeo de sociedades (IDs antiguos → IDs nuevos)
     */
    private function construirMapeoSociedades(): void
    {
        $this->info('🔍 Construyendo mapeo de sociedades (por NOMBRE)...');

        try {
            $sociedadesAntiguas = DB::connection('mysql')
                ->table('sociedades')
                ->get();

            $sociedadesNuevas = DB::connection('sqlsrv')
                ->table('sociedad')
                ->get();

            $mapeados = 0;

            // Mapear por nombre (más confiable que por ID)
            foreach ($sociedadesAntiguas as $antigua) {
                foreach ($sociedadesNuevas as $nueva) {
                    // Normalizar nombres para comparar
                    $nombreAntiguo = strtolower(trim($antigua->nombre ?? ''));
                    $nombreNuevo = strtolower(trim($nueva->nombre ?? ''));

                    if ($nombreAntiguo && $nombreAntiguo === $nombreNuevo) {
                        $this->mapeoSociedades[$antigua->id_sociedad] = $nueva->id;
                        $mapeados++;
                        break;
                    }
                }
            }

            $this->info("✅ Sociedades mapeadas: {$mapeados} / {$sociedadesAntiguas->count()}");

            // Advertir si hay sociedades sin mapear
            $noMapeadas = $sociedadesAntiguas->count() - $mapeados;
            if ($noMapeadas > 0) {
                $this->warn("⚠️  {$noMapeadas} sociedades sin mapear - se usará ID 1 por defecto");
            }
        } catch (\Exception $e) {
            $this->error("Error mapeando sociedades: {$e->getMessage()}");
        }
    }

    /**
     * Construir mapeo de socios por DNI
     */
    private function construirMapeoSociosPorDNI(): void
    {
        $cacheKey = 'mapeo_socios_dni_migracion_v3';

        if (!$this->option('rebuild-map') && Cache::has($cacheKey)) {
            $this->mapeoSociosPorDNI = Cache::get($cacheKey);
            $this->info("✅ Mapeo de socios cargado desde caché ({$this->countMapeados('socios')} socios)");
            return;
        }

        $this->info('🔍 Construyendo mapeo de socios por DNI...');

        try {
            // Obtener DNIs únicos de organizadores en seguros
            $dnisOrganizadores = DB::connection('mysql')
                ->table('seguro_cacerias')
                ->where('borrado', 0)
                ->whereNotNull('dni_cif_organizador')
                ->distinct()
                ->pluck('dni_cif_organizador')
                ->filter()
                ->map(fn($dni) => $this->normalizarDNI($dni))
                ->unique()
                ->values();

            $this->info("   DNIs únicos de organizadores: {$dnisOrganizadores->count()}");

            // Obtener todos los socios de SQL Server
            $sociosNuevos = DB::connection('sqlsrv')
                ->table('socios')
                ->select('id', 'dni')
                ->whereNotNull('dni')
                ->get();

            $this->info("   Socios en SQL Server: {$sociosNuevos->count()}");

            // Crear mapeo DNI → ID
            $mapeados = 0;
            foreach ($sociosNuevos as $socio) {
                $dniNormalizado = $this->normalizarDNI($socio->dni);
                if ($dniNormalizado) {
                    $this->mapeoSociosPorDNI[$dniNormalizado] = $socio->id;
                    $mapeados++;
                }
            }

            Cache::put($cacheKey, $this->mapeoSociosPorDNI, now()->addHours(24));
            $this->info("✅ Socios indexados por DNI: {$mapeados}");

            // Verificar cobertura
            $dnisEncontrados = $dnisOrganizadores->filter(function ($dni) {
                return isset($this->mapeoSociosPorDNI[$dni]);
            })->count();

            $porcentaje = $dnisOrganizadores->count() > 0
                ? round(($dnisEncontrados / $dnisOrganizadores->count()) * 100, 1)
                : 0;

            $this->info("   Cobertura: {$dnisEncontrados} / {$dnisOrganizadores->count()} ({$porcentaje}%)");

            if ($porcentaje < 100) {
                $noEncontrados = $dnisOrganizadores->count() - $dnisEncontrados;
                $this->warn("   ⚠️  {$noEncontrados} organizadores no tienen socio en SQL Server (se asignará NULL)");
            }
        } catch (\Exception $e) {
            $this->error("Error construyendo mapeo de socios: {$e->getMessage()}");
        }
    }

    /**
     * Normalizar DNI para comparación
     */
    private function normalizarDNI(?string $dni): ?string
    {
        if (!$dni) return null;

        // Quitar espacios, guiones y convertir a mayúsculas
        $normalizado = strtoupper(trim(str_replace([' ', '-'], '', $dni)));

        // Si está vacío después de limpiar, retornar null
        return $normalizado === '' ? null : $normalizado;
    }

    /**
     * Obtener ID del comercial nuevo
     */
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

    /**
     * Obtener ID de la sociedad nueva
     */
    private function obtenerSociedadNuevaId(?int $idSociedadAntigua): int
    {
        if (!$idSociedadAntigua) {
            return 1; // Sociedad por defecto
        }

        if (isset($this->mapeoSociedades[$idSociedadAntigua])) {
            return $this->mapeoSociedades[$idSociedadAntigua];
        }

        $this->stats['sociedades_no_mapeadas']++;
        return 1; // Sociedad por defecto
    }

    /**
     * Obtener ID del socio por DNI del organizador
     */
    private function obtenerSocioIdPorDNI(?string $dniOrganizador): ?int
    {
        if (!$dniOrganizador) {
            return null;
        }

        $dniNormalizado = $this->normalizarDNI($dniOrganizador);

        if (!$dniNormalizado) {
            return null;
        }

        if (isset($this->mapeoSociosPorDNI[$dniNormalizado])) {
            $this->stats['socios_mapeados_dni']++;
            return $this->mapeoSociosPorDNI[$dniNormalizado];
        }

        // No se encontró el socio
        $this->stats['socios_no_encontrados']++;
        return null;
    }

    /**
     * Obtener nombre del comercial
     */
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

    private function yaExiste(string $codigoProducto, string $connection): bool
    {
        return DB::connection($connection)
            ->table('producto_c')
            ->where('codigo_producto', $codigoProducto)
            ->exists();
    }

    private function transformarDatos($seguro): array
    {
        $idUserAntiguo = $seguro->id_emisor;
        $comercialId = $this->obtenerComercialNuevoId($idUserAntiguo);
        $sociedadId = $this->obtenerSociedadNuevaId($seguro->id_sociedad);
        $socioId = $this->obtenerSocioIdPorDNI($seguro->dni_cif_organizador);

        // Obtener datos del socio si existe
        $datosSocio = $this->obtenerDatosSocio($socioId);

        // Validar y preparar TODAS las fechas
        $fechaEmision = $this->validarFecha($seguro->fecha_emision);
        $fechaInicio = $this->validarFecha($seguro->dia_celebracion);
        $fechaFin = $this->validarFecha($seguro->dia_celebracion);
        $createdAt = $this->validarFecha($seguro->creado);
        $updatedAt = $this->validarFecha($seguro->creado);

        // Fecha de nacimiento - datetime con hora 00:00:00
        $fechaNacimiento = null;
        if ($datosSocio && isset($datosSocio->fecha_de_nacimiento)) {
            $fechaNacimiento = $this->validarFechaNacimiento($datosSocio->fecha_de_nacimiento);
        }

        // Log detallado
        Log::channel($this->logChannel)->debug("Verificando fechas para ID {$seguro->id_seguro_caceria}", [
            'fecha_emision_raw' => $seguro->fecha_emision,
            'fecha_emision_validada' => $fechaEmision,
            'fecha_nacimiento_validada' => $fechaNacimiento,
            'created_at_validado' => $createdAt,
        ]);

        $datos = [
            // IDs y control
            'sociedad_id' => $sociedadId,
            'tipo_de_pago_id' => $this->mapearTipoPagoId($seguro->tipo_pago),
            'tipo_de_pago' => $this->mapearTipoPago($seguro->tipo_pago),
            'socio_id' => $socioId,
            'anulado' => (bool) $seguro->borrado,
            'pago_id' => null,

            // Comercial
            'comercial_id' => $comercialId,
            'comercial_creador_id' => $comercialId,
            'comercial' => $this->obtenerNombreComercial($idUserAntiguo),
            'mediante_pagina_web' => $seguro->id_origen_emision === 1,

            // Certificado
            'codigo_producto' => $seguro->numero_certificado,

            // Precios
            'precio_base' => $seguro->precio_seguro ?? 0,
            'extra_1' => $seguro->cuota_kyrema ?? 0,
            'extra_2' => 0,
            'extra_3' => 0,
            'precio_total' => ($seguro->precio_seguro ?? 0) + ($seguro->cuota_kyrema ?? 0),
            'precio_final' => (string)(($seguro->precio_seguro ?? 0) + ($seguro->cuota_kyrema ?? 0)),

            // Subproducto
            'subproducto' => $this->obtenerIdSubproducto($seguro->suma_asegurada, $seguro->id_pais),
            'subproducto_codigo' => $this->mapearSubproducto($seguro->suma_asegurada, $seguro->id_pais),

            // Datos de la cacería
            'tipo_de_cacería' => $this->obtenerTipoCaceriaTexto($seguro->tipo_caceria),
            'tipo_de_caceria' => $this->obtenerTipoCaceriaTexto($seguro->tipo_caceria),
            'puestos' => $this->mapearPuestos($seguro->numero_puestos),
            'nº_de_puestos' => $this->mapearPuestos($seguro->numero_puestos),
            'numero_de_puestos' => $this->mapearPuestos($seguro->numero_puestos),
            'finca_o_lugar_de_evento' => $seguro->lugar,
            'finca_o_lugar_del_evento' => $seguro->lugar,
            'población_o_finca' => $seguro->lugar,
            'matricula' => $seguro->matricula,
            'matricula_del_coto' => $seguro->matricula,
            'poblacion' => $seguro->poblacion,
            'poblacion_finca' => $seguro->poblacion,
            'cod_postal_finca' => $seguro->codigo_postal,
            'codigo_postal_finca' => $seguro->codigo_postal,

            // Datos del cazador
            'nombre_cazador' => $this->limpiarValor($seguro->nombre_cazador, false) ?: '',
            'dni_cazador' => $this->limpiarValor($seguro->dni_cazador, false) ?: '',

            // Datos del socio
            'nombre_socio' => $datosSocio ? ($datosSocio->nombre_socio ?? null) : $this->extraerNombre($seguro->nombre_organizador),
            'apellido_1' => $datosSocio ? ($datosSocio->apellido_1 ?? null) : $this->extraerApellido($seguro->nombre_organizador, 1),
            'apellido_2' => $datosSocio ? ($datosSocio->apellido_2 ?? null) : $this->extraerApellido($seguro->nombre_organizador, 2),
            'dni' => $datosSocio ? ($datosSocio->dni ?? null) : $seguro->dni_cif_organizador,
            'email' => $datosSocio ? ($datosSocio->email ?? '') : ($seguro->email_organizador ?? ''),
            'telefono' => $datosSocio ? ($datosSocio->telefono ?? null) : $seguro->telefono_organizador,
            'sexo' => $datosSocio ? ($datosSocio->sexo ?? '') : '',
            'dirección' => $datosSocio ? ($datosSocio->direccion ?? null) : $seguro->direccion_organizador,
            'codigo_postal' => $datosSocio ? ($datosSocio->codigo_postal ?? null) : $this->limpiarCodigoPostal($seguro->codigo_postal_organizador),
            'población' => $datosSocio ? ($datosSocio->poblacion ?? null) : $seguro->poblacion_organizador,
            'provincia' => $datosSocio ? ($datosSocio->provincia ?? null) : $seguro->provincia_organizador,

            // Otros
            'blob_name' => $seguro->url_pdf,
            'duracion' => $this->calcularDuracion($seguro->fecha_emision, $seguro->dia_celebracion),
            'numero_anexos' => 0,

            // Plantillas
            'logo_sociedad_path' => "logos/logo_{$seguro->id_sociedad}.png",
            'plantilla_path_1' => 'plantillas/default.jpg',
        ];

        // Añadir fechas con CONVERT (más seguro en SQL Server)
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

        if ($createdAt) {
            $datos['created_at'] = DB::raw("CONVERT(datetime, '{$createdAt}', 120)");
        }

        if ($updatedAt) {
            $datos['updated_at'] = DB::raw("CONVERT(datetime, '{$updatedAt}', 120)");
        }

        return $datos;
    }

    private function countMapeados(string $tipo): int
    {
        return match ($tipo) {
            'comerciales' => count($this->mapeoComerciales),
            'sociedades' => count($this->mapeoSociedades),
            'socios' => count($this->mapeoSociosPorDNI),
            default => 0,
        };
    }

    private function mostrarEstadisticas(): void
    {
        $this->info('📈 Estadísticas de migración:');
        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Total registros', $this->stats['total']],
                ['✅ Migrados', $this->stats['migrados']],
                ['⏭️  Saltados (ya existían)', $this->stats['saltados']],
                ['❌ Errores', $this->stats['errores']],
                ['👥 Comerciales mapeados', $this->countMapeados('comerciales')],
                ['🏢 Sociedades mapeadas', $this->countMapeados('sociedades')],
                ['👤 Socios disponibles (por DNI)', $this->countMapeados('socios')],
                ['✅ Socios asignados', $this->stats['socios_mapeados_dni']],
                ['⚠️  Sin comercial (usaron ID 1)', $this->stats['sin_comercial']],
                ['⚠️  Sociedades no mapeadas', $this->stats['sociedades_no_mapeadas']],
                ['⚠️  Socios no encontrados (NULL)', $this->stats['socios_no_encontrados']],
            ]
        );

        if ($this->stats['errores'] > 0) {
            $this->error("⚠️  Hay {$this->stats['errores']} errores.");
            $this->warn("📝 Revisa los logs detallados en: storage/logs/migracion_seguros.log");
            $this->warn("   También en: storage/logs/laravel.log");
        }

        if ($this->option('test')) {
            $this->info('✅ Modo test completado - No se insertaron datos');
        } else {
            $this->info('✅ Migración completada exitosamente');
        }

        Log::channel($this->logChannel)->info('=== FIN DE MIGRACIÓN ===');
        Log::channel($this->logChannel)->info('Total migrados: ' . $this->stats['migrados']);
        Log::channel($this->logChannel)->info('Total errores: ' . $this->stats['errores']);
    }

    // === MÉTODOS AUXILIARES ===

    /**
     * Mapear tipo de pago a ID
     */
    private function mapearTipoPagoId(?string $tipo): int
    {
        return match ($tipo) {
            '1' => 6,  // no completado
            '2' => 5,  // banco → Transferencia
            '3' => 8,  // en mano → Efectivo
            '4' => 10, // paypal → Giro bancario
            '5' => 9,  // tarjeta credito → Tarjeta
            default => 6, // NULL o desconocido → No completado
        };
    }

    /**
     * Mapear tipo de pago a nombre
     */
    private function mapearTipoPago(?string $tipo): string
    {
        return match ($tipo) {
            '1' => 'No completado',
            '2' => 'Transferencia',
            '3' => 'Efectivo',
            '4' => 'Giro bancario',
            '5' => 'Tarjeta',
            default => 'No completado', // NULL o desconocido
        };
    }

    /**
     * Obtener datos del socio desde la tabla socios
     */
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

    private function mapearSubproducto(?int $suma, ?int $idPais = 1): string
    {
        // Mapear exactamente como aparecen en tipo_producto
        if ($idPais === 1) {
            // España
            return match ($suma) {
                300000 => 'Cacerias 300.000',
                600000 => 'Caceria 600.000€ España',
                1000000 => 'Caceria 1.000.000€ España',
                default => $suma ? "Caceria {$suma}" : 'Cacerias 300.000',
            };
        } else {
            // Portugal u otros
            return match ($suma) {
                600000 => 'Cacerias 600.000€ Portugal',
                default => "Caceria {$suma}",
            };
        }
    }

    /**
     * Obtener el ID del producto desde la tabla tipo_producto
     */
    private function obtenerIdSubproducto(?int $suma, ?int $idPais = 1): ?int
    {
        static $cache = [];

        $cacheKey = "{$suma}_{$idPais}";

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        try {
            $nombreProducto = $this->mapearSubproducto($suma, $idPais);

            $producto = DB::connection('sqlsrv')
                ->table('tipo_producto')
                ->whereRaw('LOWER(nombre) = LOWER(?)', [$nombreProducto])
                ->first();

            if ($producto) {
                $cache[$cacheKey] = $producto->id;
                Log::channel('migracion_seguros')->debug("✅ Producto encontrado: '{$nombreProducto}' → ID {$producto->id}");
                return $producto->id;
            }

            $nombreNormalizado = trim(preg_replace('/\s+/', ' ', $nombreProducto));
            $producto = DB::connection('sqlsrv')
                ->table('tipo_producto')
                ->whereRaw('LOWER(REPLACE(nombre, \' \', \'\')) = LOWER(REPLACE(?, \' \', \'\'))', [$nombreNormalizado])
                ->first();

            if ($producto) {
                $cache[$cacheKey] = $producto->id;
                Log::channel('migracion_seguros')->info("✅ Producto encontrado con normalización: '{$nombreProducto}' → ID {$producto->id}");
                return $producto->id;
            }

            Log::channel('migracion_seguros')->warning("⚠️ Producto NO encontrado: '{$nombreProducto}' (suma: {$suma}, pais: {$idPais})");

            $cache[$cacheKey] = null;
            return null;
        } catch (\Exception $e) {
            Log::error("Error obteniendo ID de producto para suma {$suma}: {$e->getMessage()}");
            $cache[$cacheKey] = null;
            return null;
        }
    }

    private function mapearPuestos(?int $puestos): ?string
    {
        if (!$puestos) return null;

        return match (true) {
            $puestos <= 10 => 'Hasta 10',
            $puestos <= 20 => 'De 11 a 20',
            $puestos <= 40 => 'De 21 a 40',
            $puestos <= 70 => 'Desde 41 a 70',
            default => '71 o más',
        };
    }

    private function obtenerTipoCaceriaTexto(?int $tipoId): ?string
    {
        if (!$tipoId) return null;
        return $this->tiposCaceria[$tipoId] ?? null;
    }

    private function limpiarValor(?string $valor, bool $permitirNull = true): ?string
    {
        if (!$valor || trim($valor) === '') {
            return $permitirNull ? null : '';
        }

        if ($valor === '-') {
            return $permitirNull ? null : '';
        }

        return trim($valor);
    }

    /**
     * Validar y limpiar fechas datetime
     */
    private function validarFecha($fecha): ?string
    {
        if (!$fecha) {
            return null;
        }

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

    /**
     * Validar fecha de nacimiento (datetime con hora 00:00:00)
     */
    private function validarFechaNacimiento($fecha): ?string
    {
        if (!$fecha) {
            return null;
        }

        try {
            $dt = new \DateTime($fecha);

            if ($dt->format('Y') < 1753 || $dt->format('Y') > 9999) {
                return null;
            }

            // Retornar datetime con hora 00:00:00
            return $dt->format('Y-m-d') . ' 00:00:00';
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extraerNombre(?string $nombreCompleto): ?string
    {
        $limpio = $this->limpiarValor($nombreCompleto);
        if (!$limpio) return null;

        $partes = explode(' ', $limpio);
        return $partes[0] ?? null;
    }

    private function extraerApellido(?string $nombreCompleto, int $pos): ?string
    {
        $limpio = $this->limpiarValor($nombreCompleto);
        if (!$limpio) return null;

        $partes = array_slice(explode(' ', $limpio), 1);
        return $partes[$pos - 1] ?? null;
    }

    private function limpiarCodigoPostal(?string $cp): ?int
    {
        if (!$cp) return null;

        $limpio = preg_replace('/[^0-9]/', '', $cp);
        return $limpio ? (int) $limpio : null;
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
}
