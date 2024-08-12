<?php

namespace App\Http\Controllers;

use App\Models\Campos;
use Illuminate\Http\Request;

class CampoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $campos = Campos::all();
        return response()->json($campos);
    }

    public function getByTipoProducto(Request $request)
    {
        $id_tipo_producto = $request->input('id_tipo_producto');
        // Obtener todos los campos que tengan el id_tipo_producto pasado por parámetro
        $campos = Campos::where('tipo_producto_id', $id_tipo_producto)->get();
        
        // Devolver los resultados en formato JSON
        return response()->json($campos);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'tipo_producto_id' => 'nullable|string|max:255',
            'visible' => 'required|boolean',
            'obligatorio' => 'required|boolean',
            'grupo' => 'nullable|string|max:255',
        ]);

        $campo = Campos::create($request->all());

        return response()->json($campo, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $campo = Campos::findOrFail($id);
        return response()->json($campo);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'string|max:255',
            'tipo_producto_id' => 'nullable|string|max:255',
            'visible' => 'boolean',
            'obligatorio' => 'boolean',
            'grupo' => 'nullable|string|max:255',
        ]);

        $campo = Campos::findOrFail($id);
        $campo->update($request->all());

        return response()->json($campo);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $campo = Campos::findOrFail($id);
        $campo->delete();

        return response()->json(null, 204);
    }
}

