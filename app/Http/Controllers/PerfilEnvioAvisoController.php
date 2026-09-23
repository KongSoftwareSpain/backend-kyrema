<?php

namespace App\Http\Controllers;

use App\Models\Comercial;
use App\Models\PerfilEnvioAviso;
use App\Models\Sociedad;
use App\Models\TipoProducto;
use App\Services\Avisos\EnvioAvisosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PerfilEnvioAvisoController extends Controller
{
    private const RELACIONES = ['tiposProducto', 'sociedades', 'comerciales', 'socios', 'formasPago'];

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

    public function forzar(Request $request, $id, EnvioAvisosService $envioAvisosService)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $perfil = PerfilEnvioAviso::findOrFail($id);

        $resultado = $envioAvisosService->ejecutarPerfil(
            $perfil,
            forzado: true,
            ignorarDuplicados: $request->boolean('ignorar_duplicados')
        );

        return response()->json($resultado);
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
