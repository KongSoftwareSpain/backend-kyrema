<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Hash;
use App\Models\Comercial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComercialController extends Controller
{
    public function getAllUsers()
    {
        $comerciales = Comercial::all();
        return response()->json($comerciales);
    }

    
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'id_sociedad' => 'required|numeric|exists:sociedad,id',
            'usuario' => 'required|string|max:255',
            'email' => 'required|string|max:255',
            'responsable' => 'nullable|boolean',
            'dni' => 'nullable|string|max:255',
            'sexo' => 'nullable|string|max:10',
            'fecha_nacimiento' => 'required|date',
            'fecha_alta' => 'nullable|date',
            'referido' => 'nullable|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'poblacion' => 'nullable|string|max:255',
            'provincia' => 'nullable|string|max:255',
            'cod_postal' => 'nullable|string|max:10',
            'telefono' => 'nullable|string|max:20',
            'fax' => 'nullable|string|max:20',
            'path_licencia_cazador' => 'nullable|string|max:255',
            'path_dni' => 'nullable|string|max:255',
            'path_justificante_iban' => 'nullable|string|max:255',
            'path_otros' => 'nullable|string|max:255',
            'path_foto' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:4096',
        ]);
        


        // Cambiar el formato de las fechas 'Y-m-d\TH:i:s'
        if ($request->fecha_nacimiento) {
            $request->merge([
                'fecha_nacimiento' => date('Y-m-d\TH:i:s', strtotime($request->fecha_nacimiento))
            ]);
        }
        if ($request->fecha_alta) {
            $request->merge([
                'fecha_alta' => date('Y-m-d\TH:i:s', strtotime($request->fecha_alta))
            ]);
        }
    
        // Crear una copia de los datos del request
        $data = $request->all();
    
        // Hashear la contraseña
        $data['contraseña'] = Hash::make($request->contraseña);
        $data['dni'] == null ? $data['dni'] = '' : $data['dni']; 
    
        // Crear el comercial usando los datos modificados
        $comercial = Comercial::create($data->except('path_foto'));

        // Si se ha subido una foto, guardarla
        if ($request->hasFile('path_foto')) {
            $foto = $request->file('path_foto');
            $fotoPath = $foto->storeAs('public/profile-pics', 'foto_' . $foto->getClientOriginalName() . '_' . $comercial->id . '.' . $foto->extension());
            $comercial->path_foto = str_replace('public/', '', $fotoPath); // Guardar la ruta de la foto
            $comercial->save();
        }
    
        return response()->json($comercial, 201);
    }


    public function getComercialesPorSociedad($sociedad)
    {
        $comerciales = Comercial::where('id_sociedad', $sociedad)->get();
        return response()->json($comerciales);
    }

    public function show($id)
    {
        $comercial = Comercial::findOrFail($id);
        return response()->json($comercial);
    }


    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'id_sociedad' => 'required|numeric|exists:sociedad,id',
            'usuario' => 'required|string|max:255',
            'email' => 'required|string|max:255',
            'responsable' => 'nullable|boolean',
            'dni' => 'nullable|string|max:255',
            'sexo' => 'nullable|string|max:10',
            'fecha_nacimiento' => 'required|date',
            'fecha_alta' => 'nullable|date',
            'referido' => 'nullable|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'poblacion' => 'nullable|string|max:255',
            'provincia' => 'nullable|string|max:255',
            'cod_postal' => 'nullable|string|max:10',
            'telefono' => 'nullable|string|max:20',
            'fax' => 'nullable|string|max:20',
            'path_licencia_cazador' => 'nullable|string|max:255',
            'path_dni' => 'nullable|string|max:255',
            'path_justificante_iban' => 'nullable|string|max:255',
            'path_otros' => 'nullable|string|max:255',
            'path_foto' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:4096',
        ]);

        // Preparar los datos a actualizar
        $data = $request->except(['path_foto', 'created_at', 'updated_at']);
        
        // Formatear fechas antes de actualizar
        if (!empty($data['fecha_nacimiento'])) {
            $data['fecha_nacimiento'] = date('Y-m-d', strtotime($data['fecha_nacimiento']));
        }
        if (!empty($data['fecha_alta'])) {
            $data['fecha_alta'] = date('Y-m-d', strtotime($data['fecha_alta']));
        }

        // Actualizar en la base de datos
        DB::table('comercials')
            ->where('id', $id)
            ->update($data);

        // Procesar la foto si se subió una nueva
        if ($request->hasFile('path_foto')) {
            $foto = $request->file('path_foto');
            $fotoPath = $foto->storeAs(
                'public/profile-pics',
                'foto_' . $id . '.' . $foto->extension()
            );
            DB::table('comercials')
                ->where('id', $id)
                ->update(['path_foto' => str_replace('public/', '', $fotoPath)]);
        }

        // Recuperar el registro actualizado para devolverlo
        $comercial = DB::table('comercials')->where('id', $id)->first();

        return response()->json($comercial);
    }


    public function destroy($id)
    {
        $comercial = Comercial::findOrFail($id);
        $comercial->delete();

        return response()->json(null, 204);
    }
}
