<?php

namespace App\Http\Controllers;

use App\Models\Comercial;
use App\Models\ConfiguracionEnvioCorreos;
use App\Models\PerfilEnvioAviso;
use App\Models\Sociedad;
use App\Models\TipoProducto;
use App\Services\Avisos\EnvioAvisosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PerfilEnvioAvisoController extends Controller
{
    private const RELACIONES = ['tiposProducto', 'sociedades', 'comerciales', 'socios', 'formasPago'];

    // Límites de los PDF adjuntos del envío forzado. El total deja margen bajo el
    // límite típico de un servidor SMTP (el adjunto crece ~1/3 al codificarse).
    // Si se cambian, hay que cambiarlos también en ForzarEnvioModalComponent.
    private const MAX_ADJUNTOS = 5;
    private const MAX_KB_ADJUNTO = 5120;
    private const MAX_BYTES_ADJUNTOS_TOTAL = 10 * 1024 * 1024;
    private const MAX_ENVIOS_POR_LOTE = 20000;

    /**
     * Los avisos de caducidad afectan a todas las sociedades: solo la
     * sociedad admin gestiona los perfiles de envío.
     */
    private function autorizarAdmin(Request $request): bool
    {
        $comercial = $request->user();
        return $comercial && $comercial->id_sociedad == env('SOCIEDAD_ADMIN_ID', 1);
    }

    public function index(Request $request)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        return response()->json(
            PerfilEnvioAviso::with(self::RELACIONES)->orderBy('nombre')->get()
        );
    }

    public function opciones(Request $request)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        return response()->json([
            'tipos_producto' => TipoProducto::whereNotNull('letras_identificacion')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'letras_identificacion']),
            'sociedades' => Sociedad::orderBy('nombre')->get(['id', 'nombre']),
            'comerciales' => Comercial::orderBy('nombre')->get(['id', 'nombre', 'id_sociedad']),
            'formas_pago' => $this->obtenerFormasPagoDistintas(),
        ]);
    }

    public function store(Request $request)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $datos = $this->validarDatos($request);

        $perfil = DB::transaction(function () use ($datos) {
            $perfil = PerfilEnvioAviso::create($datos['perfil']);
            $this->sincronizarRelaciones($perfil, $datos);
            return $perfil;
        });

        return response()->json($perfil->load(self::RELACIONES), 201);
    }

    public function update(Request $request, $id)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $perfil = PerfilEnvioAviso::findOrFail($id);
        $datos = $this->validarDatos($request);

        DB::transaction(function () use ($perfil, $datos) {
            $perfil->update($datos['perfil']);
            $this->sincronizarRelaciones($perfil, $datos);
        });

        return response()->json($perfil->fresh(self::RELACIONES));
    }

    public function destroy(Request $request, $id)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        PerfilEnvioAviso::findOrFail($id)->delete();

        return response()->json(['message' => 'Perfil eliminado.']);
    }

    /**
     * Paso 1 del "Forzar envío": qué se enviaría y a quién (con el texto por
     * defecto), sin enviar ni escribir nada. El modal del front lo enseña para
     * que se confirme antes de enviar.
     */
    public function previsualizarForzado(Request $request, $id, EnvioAvisosService $envioAvisosService)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        if (!ConfiguracionEnvioCorreos::actual()->activo) {
            return $this->respuestaInterruptorApagado();
        }

        $perfil = PerfilEnvioAviso::findOrFail($id);

        return response()->json(
            $envioAvisosService->previsualizarEnvioForzado($perfil, $request->boolean('ignorar_duplicados'))
        );
    }

    /**
     * Paso 2 del "Forzar envío" (multipart): encola los correos que el admin
     * confirmó en el modal, con el texto que haya editado y los PDF adjuntos.
     *
     * Los destinatarios nunca se aceptan del cliente: llegan solo las claves
     * "tabla:producto_id" de la previsualización y se vuelven a calcular aquí,
     * de modo que se envía únicamente lo que sigue siendo válido.
     */
    public function forzar(Request $request, $id, EnvioAvisosService $envioAvisosService)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        // Con el interruptor apagado no se envía nada: se avisa en vez de encolar
        // correos que el listener cancelaría uno a uno.
        if (!ConfiguracionEnvioCorreos::actual()->activo) {
            return $this->respuestaInterruptorApagado();
        }

        $request->validate([
            'texto' => ['required', 'string', 'max:3000'],
            'ignorar_duplicados' => ['sometimes', 'boolean'],
            'envios' => ['required', 'string'],
            'adjuntos' => ['sometimes', 'array', 'max:' . self::MAX_ADJUNTOS],
            'adjuntos.*' => ['file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:' . self::MAX_KB_ADJUNTO],
        ], [
            'texto.required' => 'El texto del correo no puede estar vacío.',
            'texto.max' => 'El texto del correo no puede superar los 3000 caracteres.',
            'adjuntos.max' => 'Se pueden adjuntar como máximo ' . self::MAX_ADJUNTOS . ' PDF.',
            'adjuntos.*.uploaded' => 'No se pudo subir uno de los adjuntos (puede superar el tamaño que admite el servidor).',
            'adjuntos.*.mimes' => 'Los adjuntos deben ser archivos PDF.',
            'adjuntos.*.mimetypes' => 'Los adjuntos deben ser archivos PDF.',
            'adjuntos.*.max' => 'Cada PDF puede pesar como máximo ' . (self::MAX_KB_ADJUNTO / 1024) . ' MB.',
        ]);

        $claves = $this->leerClavesEnvio($request->input('envios'));
        $this->validarPesoTotalAdjuntos($request);

        $perfil = PerfilEnvioAviso::findOrFail($id);

        $candidatos = $envioAvisosService->candidatos($perfil, true, $request->boolean('ignorar_duplicados'));
        $seleccion = array_filter(
            array_intersect_key($candidatos, array_flip($claves)),
            fn ($c) => $c['estado'] === 'enviar'
        );

        if (!$seleccion) {
            return response()->json([
                'error' => 'Ninguno de los correos seleccionados sigue siendo válido (puede que ya se hayan avisado o que los datos hayan cambiado). Cierra el modal y vuelve a abrirlo para ver el estado actual.',
            ], 409);
        }

        // Los PDF se guardan después de comprobar que hay algo que enviar, para no
        // dejar archivos huérfanos. Los borra avisos:limpiar-adjuntos pasados unos días.
        $adjuntos = $this->guardarAdjuntos($request);

        $encolados = $envioAvisosService->encolarEnviosForzados($perfil, $seleccion, $request->input('texto'), $adjuntos);

        return response()->json([
            'encolados' => $encolados,
            'descartados' => count($claves) - $encolados,
            'adjuntos' => count($adjuntos),
        ]);
    }

    private function respuestaInterruptorApagado()
    {
        return response()->json([
            'error' => 'El envío de correos está desactivado (interruptor maestro). Actívalo para poder forzar el envío.',
        ], 409);
    }

    /**
     * 'envios' llega como un único campo con un JSON ["tabla:id", ...] en vez de
     * como array de formulario: con cientos o miles de pólizas, PHP recortaría en
     * silencio los campos que pasen de max_input_vars (1000 por defecto).
     *
     * @return array<int, string>
     */
    private function leerClavesEnvio($bruto): array
    {
        $claves = is_string($bruto) ? json_decode($bruto, true) : null;

        $valido = is_array($claves)
            && $claves !== []
            && count($claves) <= self::MAX_ENVIOS_POR_LOTE
            && collect($claves)->every(fn ($c) => is_string($c) && preg_match('/^[a-z0-9_]+:\d+$/i', $c));

        if (!$valido) {
            throw ValidationException::withMessages(['envios' => 'La lista de correos a enviar no es válida.']);
        }

        return array_values(array_unique($claves));
    }

    private function validarPesoTotalAdjuntos(Request $request): void
    {
        $total = collect($request->file('adjuntos') ?? [])->sum(fn ($archivo) => $archivo->getSize());

        if ($total > self::MAX_BYTES_ADJUNTOS_TOTAL) {
            throw ValidationException::withMessages([
                'adjuntos' => 'Los PDF adjuntos no pueden pesar más de ' . (self::MAX_BYTES_ADJUNTOS_TOTAL / 1048576) . ' MB en total.',
            ]);
        }
    }

    /**
     * Guarda los PDF en una carpeta propia de este envío (disco 'local', fuera de
     * public) y devuelve su ruta relativa y el nombre con el que se adjuntarán.
     * El nombre del lote empieza por la fecha: así avisos:limpiar-adjuntos sabe
     * cuándo caduca sin consultar nada más.
     *
     * @return array<int, array{ruta:string, nombre:string}>
     */
    private function guardarAdjuntos(Request $request): array
    {
        $archivos = array_values($request->file('adjuntos') ?? []);

        if (!$archivos) {
            return [];
        }

        $carpeta = 'avisos-adjuntos/' . now()->format('YmdHis') . '-' . Str::random(8);
        $adjuntos = [];

        foreach ($archivos as $i => $archivo) {
            $ruta = $archivo->storeAs($carpeta, $i . '.pdf', 'local');

            if (!$ruta) {
                throw new \RuntimeException('No se pudo guardar un adjunto en el servidor.');
            }

            $adjuntos[] = [
                'ruta' => $ruta,
                'nombre' => $this->nombreAdjunto($archivo->getClientOriginalName()),
            ];
        }

        return $adjuntos;
    }

    /** Nombre con el que lo verá quien reciba el correo: el original, saneado y siempre .pdf. */
    private function nombreAdjunto(string $original): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', $base);
        $base = trim((string) $base) !== '' ? mb_substr(trim($base), 0, 100) : 'adjunto';

        return $base . '.pdf';
    }

    private function validarDatos(Request $request): array
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'activo' => ['required', 'boolean'],
            'modo_tiempo' => ['required', Rule::in(['dias_antes', 'dia_mes'])],
            'dias_aviso' => ['nullable', 'required_if:modo_tiempo,dias_antes', 'array', 'min:1'],
            'dias_aviso.*' => ['integer', 'min:1', 'max:365'],
            'dia_envio_mensual' => ['nullable', 'required_if:modo_tiempo,dia_mes', 'integer', 'min:1', 'max:31'],
            'incluir_productos_activos' => ['required', 'boolean'],
            'incluir_productos_renovados' => ['required', 'boolean'],
            'fecha_actividad_socio_desde' => ['nullable', 'date'],
            'evitar_duplicados' => ['required', 'boolean'],
            'tipos_producto' => ['array'],
            'tipos_producto.*' => ['string'],
            'formas_pago' => ['array'],
            'formas_pago.*' => ['string'],
            'sociedades' => ['array'],
            'sociedades.*' => ['integer'],
            'comerciales' => ['array'],
            'comerciales.*' => ['string'],
            'socios' => ['array'],
            'socios.*' => ['integer'],
        ]);

        if (!$validado['incluir_productos_activos'] && !$validado['incluir_productos_renovados']) {
            throw ValidationException::withMessages([
                'incluir_productos_activos' => 'El perfil debe incluir productos activos, renovados, o ambos.',
            ]);
        }

        return [
            'perfil' => collect($validado)->only([
                'nombre', 'activo', 'modo_tiempo', 'dias_aviso', 'dia_envio_mensual',
                'incluir_productos_activos', 'incluir_productos_renovados',
                'fecha_actividad_socio_desde', 'evitar_duplicados',
            ])->all(),
            'tipos_producto' => $validado['tipos_producto'] ?? [],
            'formas_pago' => $validado['formas_pago'] ?? [],
            'sociedades' => $validado['sociedades'] ?? [],
            'comerciales' => $validado['comerciales'] ?? [],
            'socios' => $validado['socios'] ?? [],
        ];
    }

    private function sincronizarRelaciones(PerfilEnvioAviso $perfil, array $datos): void
    {
        $perfil->tiposProducto()->sync($datos['tipos_producto']);
        $perfil->sociedades()->sync($datos['sociedades']);
        $perfil->comerciales()->sync($datos['comerciales']);
        $perfil->socios()->sync($datos['socios']);

        $perfil->formasPago()->delete();
        foreach ($datos['formas_pago'] as $valor) {
            $perfil->formasPago()->create(['valor' => $valor]);
        }
    }

    private function obtenerFormasPagoDistintas(): array
    {
        $valores = collect();

        $tablas = TipoProducto::whereNotNull('letras_identificacion')
            ->pluck('letras_identificacion')
            ->map(fn ($letras) => strtolower($letras))
            ->unique();

        foreach ($tablas as $tabla) {
            if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, 'tipo_de_pago')) {
                continue;
            }

            $valores = $valores->merge(
                DB::table($tabla)->whereNotNull('tipo_de_pago')->distinct()->pluck('tipo_de_pago')
            );
        }

        return $valores->unique()->sort()->values()->all();
    }
}
