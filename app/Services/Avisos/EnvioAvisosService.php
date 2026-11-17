<?php

namespace App\Services\Avisos;

use App\Jobs\EnviarAvisoCaducidadJob;
use App\Models\Comercial;
use App\Models\PerfilEnvioAviso;
use App\Models\SocioExcluidoAviso;
use App\Models\TipoProducto;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Construye y ejecuta el envío de avisos de caducidad de un perfil.
 *
 * Centralizado aquí porque tanto el comando programado
 * (caducidad:enviar-avisos) como el botón de "forzar envío" de un perfil
 * necesitan exactamente la misma lógica de filtros, para no duplicarla y que
 * ambos caminos se comporten igual: los dos recorren los productos con
 * recorrerProductos(), y solo cambia lo que hacen con cada uno (enviar en el
 * acto, o listarlo como candidato para previsualizar y encolar).
 */
class EnvioAvisosService
{
    public const ASUNTO = 'Aviso de vencimiento de póliza';

    /**
     * Plantillas por defecto del cuerpo del correo. Admiten dos marcadores:
     * {poliza} (número de póliza) y {dias} (días que faltan para el vencimiento).
     */
    public const TEXTO_DIAS_ANTES = 'La póliza con número: {poliza} está próxima a caducar. Tienes {dias} días para renovar la póliza, de lo contrario acabará la cobertura.';
    public const TEXTO_DIA_MES = 'La póliza con número: {poliza} está próxima a caducar este mes. Renueva la póliza cuanto antes, de lo contrario acabará la cobertura.';

    public static function textoPorDefecto(PerfilEnvioAviso $perfil): string
    {
        return $perfil->modo_tiempo === 'dia_mes' ? self::TEXTO_DIA_MES : self::TEXTO_DIAS_ANTES;
    }

    /**
     * Sustituye los marcadores de la plantilla. El front hace la misma
     * sustitución para mostrar la vista previa: si se cambia aquí, hay que
     * cambiarla también en ForzarEnvioModalComponent.renderTexto().
     */
    public static function renderizarTexto(string $plantilla, string $poliza, ?int $dias): string
    {
        return str_replace(['{poliza}', '{dias}'], [$poliza, $dias === null ? '' : (string) $dias], $plantilla);
    }

    /**
     * Ejecuta un perfil enviando en el acto (es lo que usa el comando
     * programado). En modo 'dias_antes' recorre cada día configurado; en modo
     * 'dia_mes' solo actúa si hoy es el día configurado, salvo que $forzado sea
     * true (que ignora esa comprobación de fecha, pero no los demás filtros).
     *
     * @return array{enviados:int, fallidos:int}
     */
    public function ejecutarPerfil(PerfilEnvioAviso $perfil, bool $forzado = false, bool $ignorarDuplicados = false): array
    {
        $enviados = 0;
        $fallidos = 0;

        $this->recorrerProductos($perfil, $forzado, function (string $tabla, object $producto, ?int $diasAntes) use ($perfil, $forzado, $ignorarDuplicados, &$enviados, &$fallidos) {
            $resultado = $this->procesarProducto($perfil, $tabla, $producto, $diasAntes, $forzado, $ignorarDuplicados);

            if ($resultado === null) {
                return;
            }

            $resultado ? $enviados++ : $fallidos++;
        });

        return ['enviados' => $enviados, 'fallidos' => $fallidos];
    }

    /**
     * Productos que entrarían en un envío del perfil, cada uno con su
     * destinatario y si se enviaría u omitiría (y por qué). No envía ni escribe
     * nada: es la base de la vista previa y de la confirmación del envío forzado.
     *
     * Aplica los mismos filtros y la misma comprobación de duplicados que
     * ejecutarPerfil(), porque ambos pasan por recorrerProductos().
     *
     * @return array<string, array<string, mixed>> indexado por clave "tabla:producto_id"
     */
    public function candidatos(PerfilEnvioAviso $perfil, bool $forzado, bool $ignorarDuplicados): array
    {
        $candidatos = [];
        $comerciales = []; // caché id => Comercial|null, para no consultar el mismo comercial N veces

        $this->recorrerProductos($perfil, $forzado, function (string $tabla, object $producto, ?int $diasAntes) use ($perfil, $forzado, $ignorarDuplicados, &$candidatos, &$comerciales) {
            $comercialId = $producto->comercial_creador_id ?? null;
            $claveComercial = (string) $comercialId;

            if (!array_key_exists($claveComercial, $comerciales)) {
                $comerciales[$claveComercial] = $comercialId ? Comercial::find($comercialId) : null;
            }
            $comercial = $comerciales[$claveComercial];

            $estado = 'enviar';
            $motivo = null;

            if ($perfil->evitar_duplicados && !$ignorarDuplicados
                && $this->yaFueEnviado($perfil, $tabla, $producto->id, $comercialId, $diasAntes, $forzado)) {
                $estado = 'omitido';
                $motivo = 'ya_avisado';
            } elseif (!$comercial || !$comercial->email) {
                $estado = 'omitido';
                $motivo = 'sin_email';
            }

            $clave = "{$tabla}:{$producto->id}";

            $candidatos[$clave] = [
                'clave' => $clave,
                'tabla' => $tabla,
                'producto_id' => (int) $producto->id,
                'codigo' => $this->codigoProducto($producto),
                'dias' => $diasAntes ?? $this->diasRestantes($producto),
                'dias_aviso' => $diasAntes,
                'comercial_id' => $comercialId,
                'comercial_nombre' => $comercial?->nombre,
                'email' => $comercial?->email,
                'estado' => $estado,
                'motivo' => $motivo,
            ];
        });

        return $candidatos;
    }

    /**
     * Datos para el modal de "Forzar envío": destinatarios (agrupados por
     * comercial, con sus pólizas), lo que se omitiría y la plantilla por defecto.
     * No envía ni escribe nada.
     */
    public function previsualizarEnvioForzado(PerfilEnvioAviso $perfil, bool $ignorarDuplicados): array
    {
        $destinatarios = [];
        $omitidos = [];
        $totalCorreos = 0;

        foreach ($this->candidatos($perfil, true, $ignorarDuplicados) as $c) {
            if ($c['estado'] === 'omitido') {
                $omitidos[] = [
                    'clave' => $c['clave'],
                    'tabla' => $c['tabla'],
                    'producto_id' => $c['producto_id'],
                    'codigo' => $c['codigo'],
                    'comercial_nombre' => $c['comercial_nombre'],
                    'email' => $c['email'],
                    'motivo' => $c['motivo'],
                ];
                continue;
            }

            $destinatarios[$c['comercial_id']] ??= [
                'comercial_id' => $c['comercial_id'],
                'nombre' => $c['comercial_nombre'],
                'email' => $c['email'],
                'polizas' => [],
            ];

            $destinatarios[$c['comercial_id']]['polizas'][] = [
                'clave' => $c['clave'],
                'tabla' => $c['tabla'],
                'producto_id' => $c['producto_id'],
                'codigo' => $c['codigo'],
                'dias' => $c['dias'],
            ];

            $totalCorreos++;
        }

        return [
            'texto_por_defecto' => self::textoPorDefecto($perfil),
            'asunto' => self::ASUNTO,
            'destinatarios' => array_values($destinatarios),
            'omitidos' => $omitidos,
            'total_correos' => $totalCorreos,
        ];
    }

    /**
     * Encola un correo por cada candidato seleccionado, con la plantilla ya
     * sustituida y los mismos adjuntos para todos. El envío real, y su registro
     * como enviado o fallido, lo hace EnviarAvisoCaducidadJob.
     *
     * @param  array<string, array<string, mixed>>  $seleccion  candidatos devueltos por candidatos(), ya filtrados
     * @param  array<int, array{ruta:string, nombre:string}>  $adjuntos  ruta relativa al disco 'local'
     * @return int número de correos encolados
     */
    public function encolarEnviosForzados(PerfilEnvioAviso $perfil, array $seleccion, string $plantilla, array $adjuntos): int
    {
        foreach ($seleccion as $c) {
            EnviarAvisoCaducidadJob::dispatch(
                $perfil->id,
                $c['tabla'],
                $c['producto_id'],
                $c['comercial_id'],
                $c['email'],
                self::renderizarTexto($plantilla, $c['codigo'], $c['dias']),
                $c['dias_aviso'],
                $adjuntos
            );
        }

        return count($seleccion);
    }

    /**
     * Envía un correo de aviso. Lanza excepción si no sale, incluido el caso
     * (silencioso en Laravel) de que lo cancele el interruptor maestro: sin ello
     * se registraría como enviado un aviso que nunca salió.
     *
     * @param  array<int, array{ruta:string, nombre:string}>  $adjuntos  ruta ABSOLUTA de cada PDF
     */
    public function enviarCorreo(string $email, string $texto, array $adjuntos = []): void
    {
        $enviado = Mail::raw($texto, function ($mail) use ($email, $adjuntos) {
            $mail->to($email)->subject(self::ASUNTO);

            foreach ($adjuntos as $adjunto) {
                $mail->attach($adjunto['ruta'], ['as' => $adjunto['nombre'], 'mime' => 'application/pdf']);
            }
        });

        // Mail::raw devuelve null (sin lanzar excepción) cuando un listener de
        // MessageSending cancela el envío, p.ej. el interruptor maestro.
        if ($enviado === null) {
            throw new \RuntimeException('Envío cancelado: el interruptor maestro de correo está desactivado.');
        }
    }

    /**
     * Fecha y hora para escribir en columnas datetime con el query builder (que no
     * pasa por $dateFormat de ningún modelo). Con 'Y-m-d H:i:s' el DATEFORMAT
     * español de SQL Server lee año-día-mes: error 22007 si el día pasa de 12 y
     * fecha intercambiada en silencio si no. El formato con 'T' es el único seguro.
     */
    private function marcaDeTiempo(): string
    {
        return now()->format('Y-m-d\TH:i:s');
    }

    public function registrarEnviado(int $perfilId, string $tabla, $productoId, $comercialId, ?int $diasAviso, bool $forzado): void
    {
        $ahora = $this->marcaDeTiempo();

        DB::table('avisos_caducidad')->insert([
            'perfil_id' => $perfilId,
            'letras_identificacion' => $tabla,
            'producto_id' => $productoId,
            'comercial_id' => $comercialId,
            'dias_aviso' => $diasAviso,
            'forzado' => $forzado,
            'fecha_aviso_enviado' => $ahora,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    public function registrarFallido(int $perfilId, string $tabla, $productoId, $comercialId, ?string $emailDestino, string $motivo, bool $forzado): void
    {
        DB::table('avisos_caducidad_fallidos')->insert([
            'perfil_id' => $perfilId,
            'letras_identificacion' => $tabla,
            'producto_id' => $productoId,
            'comercial_id' => $comercialId,
            'email_destino' => $emailDestino,
            'motivo_error' => $motivo,
            'forzado' => $forzado,
            'created_at' => $this->marcaDeTiempo(),
        ]);
    }

    /**
     * Recorre los productos que entran en el perfil (tablas, estado, fecha y
     * filtros de sociedad/comercial/socio/forma de pago) y llama a $alProducto
     * con (tabla, producto, diasAntes|null) por cada uno.
     */
    private function recorrerProductos(PerfilEnvioAviso $perfil, bool $forzado, callable $alProducto): void
    {
        if (!$perfil->incluir_productos_activos && !$perfil->incluir_productos_renovados) {
            return;
        }

        $sociosExcluidos = SocioExcluidoAviso::pluck('socio_id')->all();
        $sociosActivosDesde = $perfil->fecha_actividad_socio_desde
            ? $this->resolverSociosActivosDesde($perfil->fecha_actividad_socio_desde->format('Y-m-d'))
            : null;

        if ($perfil->modo_tiempo === 'dia_mes') {
            if (!$forzado && (int) now()->day !== (int) $perfil->dia_envio_mensual) {
                return;
            }

            $this->recorrerVentana($perfil, null, $sociosExcluidos, $sociosActivosDesde, $alProducto);
        } else {
            foreach (($perfil->dias_aviso ?: []) as $diasAntes) {
                $this->recorrerVentana($perfil, (int) $diasAntes, $sociosExcluidos, $sociosActivosDesde, $alProducto);
            }
        }
    }

    private function recorrerVentana(
        PerfilEnvioAviso $perfil,
        ?int $diasAntes,
        array $sociosExcluidos,
        ?array $sociosActivosDesde,
        callable $alProducto
    ): void {
        $sociedadesMarcadas = $perfil->sociedades()->pluck('sociedad.id')->all();
        $comercialesMarcados = $perfil->comerciales()->pluck('comercial.id')->all();
        $sociosMarcados = $perfil->socios()->pluck('socios.id')->all();
        $formasPagoMarcadas = $perfil->formasPago()->pluck('valor')->all();

        foreach ($this->tablasCandidatas($perfil) as $tabla) {
            if (!$this->existeTabla($tabla)) {
                continue;
            }

            $query = DB::table($tabla)
                ->where(function ($q) {
                    $q->whereNull('anulado')->orWhere('anulado', false);
                });

            $this->aplicarFiltroEstado($query, $perfil, $tabla);
            $this->aplicarFiltroFecha($query, $tabla, $diasAntes);

            if ($formasPagoMarcadas && $this->tieneColumna($tabla, 'tipo_de_pago')) {
                $query->whereIn('tipo_de_pago', $formasPagoMarcadas);
            }

            if ($sociedadesMarcadas && $this->tieneColumna($tabla, 'sociedad_id')) {
                $query->whereIn('sociedad_id', $sociedadesMarcadas);
            }

            if ($comercialesMarcados && $this->tieneColumna($tabla, 'comercial_creador_id')) {
                $query->whereIn('comercial_creador_id', $comercialesMarcados);
            }

            if ($this->tieneColumna($tabla, 'socio_id')) {
                if ($sociosMarcados) {
                    $query->whereIn('socio_id', $sociosMarcados);
                }
                if ($sociosExcluidos) {
                    $query->whereNotIn('socio_id', $sociosExcluidos);
                }
                if ($sociosActivosDesde !== null) {
                    $query->whereIn('socio_id', $sociosActivosDesde);
                }
            }

            foreach ($query->get() as $producto) {
                $alProducto($tabla, $producto, $diasAntes);
            }
        }
    }

    /**
     * @return bool|null true = enviado, false = fallido, null = omitido (duplicado)
     */
    private function procesarProducto(
        PerfilEnvioAviso $perfil,
        string $tabla,
        object $producto,
        ?int $diasAntes,
        bool $forzado,
        bool $ignorarDuplicados
    ): ?bool {
        $comercialId = $producto->comercial_creador_id ?? null;

        if ($perfil->evitar_duplicados && !$ignorarDuplicados
            && $this->yaFueEnviado($perfil, $tabla, $producto->id, $comercialId, $diasAntes, $forzado)) {
            return null;
        }

        $comercial = $comercialId ? Comercial::find($comercialId) : null;

        if (!$comercial || !$comercial->email) {
            $this->registrarFallido($perfil->id, $tabla, $producto->id, $comercialId, null, 'Comercial sin email registrado.', $forzado);

            return false;
        }

        $plantilla = $diasAntes !== null ? self::TEXTO_DIAS_ANTES : self::TEXTO_DIA_MES;

        try {
            $this->enviarCorreo(
                $comercial->email,
                self::renderizarTexto($plantilla, $this->codigoProducto($producto), $diasAntes)
            );
        } catch (\Throwable $e) {
            $this->registrarFallido($perfil->id, $tabla, $producto->id, $comercialId, $comercial->email, $e->getMessage(), $forzado);

            return false;
        }

        $this->registrarEnviado($perfil->id, $tabla, $producto->id, $comercialId, $diasAntes, $forzado);

        return true;
    }

    /**
     * Columnas (en minúsculas) de cada tabla ya consultada, o null si no existe.
     * Schema::hasTable/hasColumn lanzan una consulta de metadatos cada vez, y por
     * cada tabla de producto y cada ventana de días se llamaban ~9 veces: más de
     * 400 consultas (casi un minuto contra SQL Server) solo para saber qué
     * columnas tiene cada tabla. Se consulta una vez por tabla y se reutiliza
     * durante toda la ejecución del servicio.
     *
     * @var array<string, string[]|null>
     */
    private array $columnasPorTabla = [];

    private function columnasDe(string $tabla): ?array
    {
        if (!array_key_exists($tabla, $this->columnasPorTabla)) {
            $columnas = Schema::getColumnListing($tabla);

            // getColumnListing devuelve [] si la tabla no existe
            $this->columnasPorTabla[$tabla] = $columnas ? array_map('strtolower', $columnas) : null;
        }

        return $this->columnasPorTabla[$tabla];
    }

    private function existeTabla(string $tabla): bool
    {
        return $this->columnasDe($tabla) !== null;
    }

    private function tieneColumna(string $tabla, string $columna): bool
    {
        return in_array(strtolower($columna), $this->columnasDe($tabla) ?? [], true);
    }

    private function codigoProducto(object $producto): string
    {
        return (string) ($producto->codigo_producto ?? $producto->id);
    }

    /** Días que faltan para fecha_de_fin (0 si ya pasó). */
    private function diasRestantes(object $producto): int
    {
        $fin = Carbon::parse($producto->fecha_de_fin)->startOfDay();

        return max(0, (int) Carbon::today()->diffInDays($fin, false));
    }

    private function aplicarFiltroEstado($query, PerfilEnvioAviso $perfil, string $tabla): void
    {
        $tieneRenovado = $this->tieneColumna($tabla, 'renovado');
        $tieneCaducado = $this->tieneColumna($tabla, 'caducado');

        $query->where(function ($estado) use ($perfil, $tieneRenovado, $tieneCaducado) {
            $huboActivos = false;

            if ($perfil->incluir_productos_activos) {
                $estado->where(function ($activos) use ($tieneRenovado, $tieneCaducado) {
                    if ($tieneCaducado) {
                        $activos->where(function ($q) {
                            $q->whereNull('caducado')->orWhere('caducado', false);
                        });
                    }
                    if ($tieneRenovado) {
                        $activos->where(function ($q) {
                            $q->whereNull('renovado')->orWhere('renovado', false);
                        });
                    }
                });
                $huboActivos = true;
            }

            if ($perfil->incluir_productos_renovados && $tieneRenovado) {
                $huboActivos
                    ? $estado->orWhere('renovado', true)
                    : $estado->where('renovado', true);
            }
        });
    }

    private function aplicarFiltroFecha($query, string $tabla, ?int $diasAntes): void
    {
        if (!$this->tieneColumna($tabla, 'fecha_de_fin')) {
            $query->whereRaw('1 = 0');
            return;
        }

        if ($diasAntes !== null) {
            $query->whereDate('fecha_de_fin', Carbon::now()->addDays($diasAntes)->format('Y-m-d'));
        } else {
            $query->whereMonth('fecha_de_fin', now()->month)->whereYear('fecha_de_fin', now()->year);
        }
    }

    private function yaFueEnviado(
        PerfilEnvioAviso $perfil,
        string $tabla,
        $productoId,
        $comercialId,
        ?int $diasAntes,
        bool $forzado
    ): bool {
        $query = DB::table('avisos_caducidad')
            ->where('perfil_id', $perfil->id)
            ->where('letras_identificacion', $tabla)
            ->where('producto_id', $productoId)
            ->where('comercial_id', $comercialId)
            ->where('forzado', $forzado);

        if ($diasAntes !== null) {
            $query->where('dias_aviso', $diasAntes);
        } else {
            $query->whereNull('dias_aviso')
                ->whereMonth('fecha_aviso_enviado', now()->month)
                ->whereYear('fecha_aviso_enviado', now()->year);
        }

        return $query->exists();
    }

    private function tablasCandidatas(PerfilEnvioAviso $perfil): Collection
    {
        $tipos = $perfil->tiposProducto()->whereNotNull('letras_identificacion')->get();

        if ($tipos->isEmpty()) {
            $tipos = TipoProducto::whereNotNull('letras_identificacion')->get();
        }

        return $tipos->pluck('letras_identificacion')->map(fn ($letras) => strtolower($letras))->unique()->values();
    }

    private function resolverSociosActivosDesde(string $fecha): array
    {
        $ids = collect();

        $tablas = TipoProducto::whereNotNull('letras_identificacion')
            ->pluck('letras_identificacion')
            ->map(fn ($letras) => strtolower($letras))
            ->unique();

        foreach ($tablas as $tabla) {
            if (!$this->tieneColumna($tabla, 'socio_id') || !$this->tieneColumna($tabla, 'fecha_de_inicio')) {
                continue;
            }

            $ids = $ids->merge(
                DB::table($tabla)->whereNotNull('socio_id')->where('fecha_de_inicio', '>=', $fecha)->pluck('socio_id')
            );
        }

        return $ids->unique()->values()->all();
    }
}
