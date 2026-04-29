<?php

namespace App\Http\Controllers;

use App\Models\Campos;
use App\Models\Opciones;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class CampoController extends Controller
{
    public function index()
    {
        return Campos::all();
    }

    public function getByTipoProducto(Request $request)
    {
        $id_tipo_producto = $request->query('id_tipo_producto');
        return Campos::where('tipo_producto_id', $id_tipo_producto)->get();
    }

    public function store(Request $request)
    {
        $campo = Campos::create($request->all());
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
        return response()->json($campo, 200);
    }

    public function updatePorTipoProducto(Request $request, $id_tipo_producto)
    {
        $campos = $request->input('campos');
        
        foreach ($campos as $campoData) {
            $campo = Campos::find($campoData['id']);
            if ($campo) {
                $campo->update($campoData);
            }
        }

        return response()->json(['message' => 'Campos actualizados correctamente'], 200);
    }

    public function addCampos(Request $request, $id_tipo_producto)
    {
        $campos = $request->input('campos');
        
        foreach ($campos as $campoData) {
            $campoData['tipo_producto_id'] = $id_tipo_producto;
            Campos::create($campoData);
        }

        return response()->json(['message' => 'Campos añadidos correctamente'], 201);
    }

    public function createCampoConOpcionesHTTP(Request $request, $id_tipo_producto)
    {
        DB::beginTransaction();
        try {
            $campoData = $request->all();
            $campoData['tipo_producto_id'] = $id_tipo_producto;
            
            // Si viene con ID, intentamos actualizar en lugar de crear
            if (isset($campoData['id']) && $campoData['id']) {
                $campo = Campos::find($campoData['id']);
                if ($campo) {
                    $campo->update($campoData);
                } else {
                    $campo = Campos::create($campoData);
                }
            } else {
                $campo = Campos::create($campoData);
            }

            if (isset($campoData['opciones']) && is_array($campoData['opciones'])) {
                // Borrar opciones antiguas si estamos editando
                Opciones::where('campo_id', $campo->id)->delete();
                
                foreach ($campoData['opciones'] as $opcionData) {
                    if (isset($opcionData['nombre']) && $opcionData['nombre'] !== '') {
                        $opcionData['campo_id'] = $campo->id;
                        Opciones::create($opcionData);
                    }
                }
            }

            DB::commit();
            return response()->json($campo->load('opciones'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateCampoConOpciones(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $campo = Campos::findOrFail($id);
            $campoData = $request->all();
            $campo->update($campoData);

            if (isset($campoData['opciones']) && is_array($campoData['opciones'])) {
                // Sincronizar opciones
                $opcionesIds = [];
                foreach ($campoData['opciones'] as $opcionData) {
                    if (isset($opcionData['nombre']) && $opcionData['nombre'] !== '') {
                        if (isset($opcionData['id']) && $opcionData['id']) {
                            $opcion = Opciones::find($opcionData['id']);
                            if ($opcion) {
                                $opcion->update($opcionData);
                                $opcionesIds[] = $opcion->id;
                            } else {
                                $opcionData['campo_id'] = $campo->id;
                                $nuevaOpcion = Opciones::create($opcionData);
                                $opcionesIds[] = $nuevaOpcion->id;
                            }
                        } else {
                            $opcionData['campo_id'] = $campo->id;
                            $nuevaOpcion = Opciones::create($opcionData);
                            $opcionesIds[] = $nuevaOpcion->id;
                        }
                    }
                }
                // Borrar las que ya no están
                Opciones::where('campo_id', $campo->id)->whereNotIn('id', $opcionesIds)->delete();
            }

            DB::commit();
            return response()->json($campo->load('opciones'), 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getOpcionesPorCampo($id_campo)
    {
        return Opciones::where('campo_id', $id_campo)->get();
    }

    public static function fetchCamposCertificado($id_tipo_producto)
    {
        return Campos::where('tipo_producto_id', $id_tipo_producto)
            ->where('visible', 1)
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

        return response()->json([
            'message' => 'Campo eliminado correctamente',
        ]);
    }
}
