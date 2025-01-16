<?php

namespace App\Http\Controllers;

use App\Models\Socio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocioController extends Controller
{
    // Mostrar una lista de los socios
    public function index()
    {
        $socios = Socio::all();
        return response()->json($socios);
    }

    public function getAsegurado($dni, $categoria_id){
        $socio = Socio::where('dni', $dni)->where('categoria_id', $categoria_id)->first();
        if (!$socio) {
            return response()->json(['message' => 'Socio not found.'], 404);
        }
        return response()->json($socio);
    }

    public function store(Request $request, $categoria_id)
    {
        $request->validate([
            'dni' => 'required|string',
            'nombre_socio' => 'required|string',
            'apellido_1' => 'nullable|string',
            'apellido_2' => 'nullable|string',
            'email' => 'required|email',
            'telefono' => 'nullable|string',
            'fecha_de_nacimiento' => 'required|date',
            'sexo' => 'nullable|string',
            'direccion' => 'nullable|string',
            'poblacion' => 'nullable|string',
            'provincia' => 'nullable|string',
            'codigo_postal' => 'nullable|string'
        ]);

        $request->merge([
            'categoria_id' => $categoria_id
        ]);

        // Validar si el DNI ya existe en la misma categoría
        if (DB::table('socios')
         ->where('dni', $request->dni)
         ->where('categoria_id', $categoria_id)
         ->exists()) {
            return response()->json(['message' => 'El DNI ya está en uso en esta categoría.'], 409);
        }

        if ($request->fecha_nacimiento) {
            $request->merge([
                'fecha_nacimiento' => date('Y-m-d\TH:i:s', strtotime($request->fecha_nacimiento)),
            ]);
        }

        $socio = DB::table('socios')->insertGetId($request->all());
        return response()->json($socio, 201);
    }

    // Mostrar un socio específico
    public function show($id)
    {
        $socio = Socio::find($id);
        return response()->json($socio);
    }

    // Actualizar un socio específico
    public function update(Request $request, $id)
    {
        $socio = Socio::findOrFail($id);
        $socio->update($request->except(['created_at, updated_at']));
        return response()->json($socio);
    }

    // Eliminar un socio específico
    public function destroy($id)
    {
        $socio = Socio::findOrFail($id);
        $socio->delete();
        return response()->json(null, 204);
    }
}
