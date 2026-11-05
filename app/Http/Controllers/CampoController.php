<?php

namespace App\Http\Controllers;

use App\Models\Campos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CampoController extends Controller
{
    public function index()
    {
        return Campos::all();
    }

    public function getByTipoProducto(Request $request)
    {
        $id_tipo_producto = $request->query('id_tipo_producto');
        // Endpoint público de la contratación; TTL 5 min como getByLetras
        return Cache::remember("campos_tipo_producto_{$id_tipo_producto}", 300, fn () => Campos::where('tipo_producto_id', $id_tipo_producto)->get());
    }

    public function store(Request $request)
    {
        $campo = Campos::create($request->all());
        $this->olvidarCacheCampos($campo->tipo_producto_id);
        return response()->json($campo, 201);
    }

    public function show($id)
    {
        return Campos::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $campo = Campos::findOrFail($id);
        $campo->update($request->all());
        $this->olvidarCacheCampos($campo->tipo_producto_id);
        return response()->json($campo, 200);
    }

    public function updatePorTipoProducto(Request $request, $id_tipo_producto)
    {
        $campos = $request->input('campos');
        
        foreach ($campos as $campoData) {
            $campo = Campos::find($campoData['id']);
            if ($campo) {
                unset($campoData['created_at'], $campoData['updated_at']);
                $campo->update($campoData);
            }
        }

        $this->olvidarCacheCampos($id_tipo_producto);

        return response()->json(['message' => 'Campos actualizados correctamente'], 200);
    }

    public function addCampos(Request $request, $id_tipo_producto)
    {
        $campos = $request->input('campos');

        // El parámetro puede llegar como id numérico o como letras_identificacion
        if (is_numeric($id_tipo_producto)) {
            $tipoProducto = DB::table('tipo_producto')->where('id', $id_tipo_producto)->first();
        } else {
            $tipoProducto = DB::table('tipo_producto')->where('letras_identificacion', $id_tipo_producto)->first();
        }

        if (!$tipoProducto) {
            return response()->json(['error' => "Tipo de producto '{$id_tipo_producto}' no encontrado"], 404);
        }

        // Tabla física: la del padre si es subproducto, la propia en productos y anexos
        $nombreTabla = $tipoProducto->letras_identificacion;
        if ($tipoProducto->padre_id) {
            $padre = DB::table('tipo_producto')->where('id', $tipoProducto->padre_id)->first();
            if ($padre) {
                $nombreTabla = $padre->letras_identificacion;
            }
        }

        try {
            foreach ($campos as $campoData) {
                $campoData['tipo_producto_id'] = (string) $tipoProducto->id;
                $campoData['nombre_codigo'] = strtolower(str_replace(' ', '_', str_replace('.', '', $campoData['nombre'])));

                if ($nombreTabla && Schema::hasTable($nombreTabla) && !Schema::hasColumn($nombreTabla, $campoData['nombre_codigo'])) {
                    Schema::table($nombreTabla, function ($table) use ($campoData) {
                        switch ($campoData['tipo_dato']) {
                            case 'decimal':
                                $table->decimal($campoData['nombre_codigo'], 10, 2)->nullable();
                                break;
                            case 'number':
                                $table->integer($campoData['nombre_codigo'])->nullable();
                                break;
                            case 'date':
                                $table->date($campoData['nombre_codigo'])->nullable();
                                break;
                            case 'time':
                                $table->time($campoData['nombre_codigo'])->nullable();
                                break;
                            default:
                                $table->string($campoData['nombre_codigo'])->nullable();
                                break;
                        }
                    });
                }

                Campos::create($campoData);
            }
        } catch (\Exception $e) {
            Log::error("Error añadiendo campos a {$nombreTabla}: " . $e->getMessage());
            return response()->json(['error' => 'Error añadiendo campos: ' . $e->getMessage()], 500);
        }

        $this->olvidarCacheCampos($tipoProducto->id);

        return response()->json(['message' => 'Campos añadidos correctamente'], 201);
    }

    public function createCampoConOpcionesHTTP(Request $request, $id_tipo_producto)
    {
        DB::beginTransaction();
        try {
            $campo = $this->crearCampoConOpciones($request->all(), $id_tipo_producto);

            DB::commit();
            $this->olvidarCacheCampos($id_tipo_producto);

            return response()->json($campo, 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Crea (o actualiza si trae id) un campo con su tabla de opciones.
     * No gestiona transacción: la abre quien lo llama.
     */
    public function crearCampoConOpciones(array $campoData, $id_tipo_producto)
    {
        $campoData['tipo_producto_id'] = $id_tipo_producto;
        unset($campoData['created_at'], $campoData['updated_at']);
        $opcionesFront = $campoData['opciones'] ?? [];
        unset($campoData['opciones']);

        $campo = !empty($campoData['id']) ? Campos::find($campoData['id']) : null;
        $esNuevo = $campo === null;

        if ($esNuevo) {
            $campo = Campos::create($campoData);
        } else {
            $campo->update($campoData);
        }

        $this->sincronizarOpciones($campo, is_array($opcionesFront) ? $opcionesFront : [], $esNuevo);
        $campo->setAttribute('opciones', $this->leerOpciones($campo));

        return $campo;
    }

    public function updateCampoConOpciones(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $campo = Campos::findOrFail($id);
            $campoData = $request->all();
            unset($campoData['created_at'], $campoData['updated_at']);

            $opcionesFront = $campoData['opciones'] ?? [];
            unset($campoData['opciones']);
            $campo->update($campoData);

            $this->sincronizarOpciones($campo, is_array($opcionesFront) ? $opcionesFront : []);

            DB::commit();
            $this->olvidarCacheCampos($campo->tipo_producto_id);

            $campo->setAttribute('opciones', $this->leerOpciones($campo));

            return response()->json($campo, 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Deja la tabla de opciones del campo igual que la lista recibida del front.
     * Si el campo aún no tiene tabla (o se perdió), la crea y la enlaza.
     */
    private function sincronizarOpciones(Campos $campo, array $opcionesFront, bool $crearSiFalta = false): void
    {
        $opcionesValidas = array_filter(
            $opcionesFront,
            fn ($o) => isset($o['nombre']) && $o['nombre'] !== ''
        );

        if (!$campo->opciones || !Schema::hasTable($campo->opciones)) {
            // Sin tabla: solo se crea si el campo es nuevo o llegan opciones que guardar
            if (!$crearSiFalta && empty($opcionesValidas)) {
                return;
            }

            $nombreTabla = 'opc_' . substr(md5(uniqid()), 0, 8);
            Schema::create($nombreTabla, function ($table) {
                $table->id();
                $table->string('nombre');
                $table->decimal('precio', 10, 2)->nullable();
            });

            // 'opciones' no es fillable en Campos (Campos::create/update la descartaba y el
            // campo quedaba sin tabla, así que sus opciones nunca llegaban al formulario).
            $campo->opciones = $nombreTabla;
            $campo->save();
        }

        $tablaOpciones = $campo->opciones;
        $opcionesIds = [];
        foreach ($opcionesValidas as $opcionData) {
            if (!empty($opcionData['id'])) {
                DB::table($tablaOpciones)->where('id', $opcionData['id'])->update([
                    'nombre' => $opcionData['nombre'],
                    'precio' => $opcionData['precio'] ?? null,
                ]);
                $opcionesIds[] = $opcionData['id'];
            } else {
                $opcionesIds[] = DB::table($tablaOpciones)->insertGetId([
                    'nombre' => $opcionData['nombre'],
                    'precio' => $opcionData['precio'] ?? null,
                ]);
            }
        }
        DB::table($tablaOpciones)->whereNotIn('id', $opcionesIds)->delete();
    }

    private function leerOpciones(Campos $campo)
    {
        if ($campo->opciones && Schema::hasTable($campo->opciones)) {
            return DB::table($campo->opciones)->get();
        }

        return [];
    }

    /**
     * getByTipoProducto cachea los campos 5 min; hay que invalidarla al tocar el configurador
     * o el formulario de alta no muestra los cambios hasta que caduque.
     */
    private function olvidarCacheCampos($tipoProductoId): void
    {
        Cache::forget("campos_tipo_producto_{$tipoProductoId}");
    }

    public function getOpcionesPorCampo($id_campo)
    {
        $campo = Campos::findOrFail($id_campo);
        // Schema::hasTable consulta el catálogo de SQL Server en cada petición; se cachea como en ProductoController
        if ($campo->opciones && Cache::remember("schema_table_{$campo->opciones}", 3600, fn() => Schema::hasTable($campo->opciones))) {
            $opciones = DB::table($campo->opciones)->selectRaw('id, nombre, CAST(precio AS DECIMAL(10,2)) as precio')->get();
            $opciones = $opciones->map(function ($item) {
                $item->precio = (float) $item->precio;
                return $item;
            });
            return response()->json($opciones);
        }
        return response()->json([]);
    }

    public static function fetchCamposCertificado($id_tipo_producto)
    {
        return Campos::where('tipo_producto_id', $id_tipo_producto)
            ->whereNotNull('columna')
            ->whereNotNull('fila')
            ->get();
    }

    public static function fetchCamposLogos($id_tipo_producto)
    {
        return DB::table('campos_logos')
            ->where('tipo_producto_id', $id_tipo_producto)
            ->get();
    }

    public function destroy($id)
    {
        $campo = Campos::findOrFail($id);
        $campo->delete();
        $this->olvidarCacheCampos($campo->tipo_producto_id);

        return response()->json(null, 204);
    }

    /**
     * Borrar un campo por id (pensado para casos de campos duplicados en subproductos).
     */
    public function deleteCampo(int $id)
    {
        // Buscamos el campo
        $campo = Campos::findOrFail($id);

        // Por seguridad, solo dejamos borrar campos de subproducto, producto o anexo
        $gruposPermitidos = ['datos_subproducto', 'datos_producto', 'datos_anexo'];
        if (!in_array($campo->grupo, $gruposPermitidos)) {
            return response()->json([
                'message' => 'Solo se pueden eliminar campos de subproductos, productos o anexos desde este endpoint.'
            ], 400);
        }

        // Si el campo tiene opciones relacionadas, las borramos primero
        if (method_exists($campo, 'opciones')) {
            $campo->opciones()->delete();
        }

        // Borramos el campo
        $campo->delete();
        $this->olvidarCacheCampos($campo->tipo_producto_id);

        return response()->json([
            'message' => 'Campo eliminado correctamente',
        ]);
    }
}
