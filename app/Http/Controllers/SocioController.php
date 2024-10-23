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

    public function getAsegurado($dni){
        $socio = Socio::where('dni', $dni)->first();
        return response()->json($socio);
    }

    // Mostrar el formulario para crear un nuevo socio
    public function create()
    {
        //
    }

    // Almacenar un nuevo socio
    public function store(Request $request)
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

        if ($request->fecha_nacimiento) {
            $request->merge([
                'fecha_nacimiento' => date('Y-m-d\TH:i:s', strtotime($request->fecha_nacimiento))
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

    // Mostrar el formulario para editar un socio específico
    public function edit($id)
    {
        //
    }

    // Actualizar un socio específico
    public function update(Request $request, $id)
    {
        $socio = Socio::findOrFail($id);
        $socio->update($request->all());
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
