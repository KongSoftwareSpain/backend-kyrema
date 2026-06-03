<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComercialRequest;
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

    public function getResponsables()
    {
        $comerciales = Comercial::where('responsable', '1')->get();
        return response()->json($comerciales);
    }

    public function isComercialPaginaWeb($id_comercial)
    {
        $comercial = Comercial::find($id_comercial);
        return response()->json($comercial->pagina_web == '1');
    }

    public function store(StoreComercialRequest $request)
    {

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
        $data = $request->except('path_foto');

        if ($request->responsable == '2') {
            $data['responsable'] = '1';
            $data['pagina_web'] = '1';
        } else {
            $data['pagina_web'] = '0';
        }

        // Hashear la contraseña
        $data['contraseña'] = Hash::make($request->contraseña);
        $data['dni'] == null ? $data['dni'] = '' : $data['dni'];

        // Crear el comercial usando los datos modificados
        $comercial = Comercial::create($data);

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
        if ($sociedad == 1) {
            $comerciales = Comercial::all();
        } else {
            $comerciales = Comercial::where('id_sociedad', $sociedad)->get();
        }
        return response()->json($comerciales);
    }

    public function show($id)
    {
        $comercial = Comercial::findOrFail($id);
        return response()->json($comercial);
    }


    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'nombre'                          => 'required|string|max:255',
            'id_sociedad'                     => 'required|numeric|exists:sociedad,id',
            'comercial_responsable_categoria' => 'nullable|string',
            'usuario'                         => 'required|string|max:255',
            'email'                           => 'required|email|max:255',
            'responsable'                     => 'nullable|string|max:1',
            'dni'                             => 'nullable|string|max:255',
            'sexo'                            => 'nullable|string|max:10',
            'fecha_nacimiento'                => 'nullable|date',
            'fecha_alta'                      => 'nullable|date',
            'referido'                        => 'nullable|string|max:255',
            'direccion'                       => 'nullable|string|max:255',
            'poblacion'                       => 'nullable|string|max:255',
            'provincia'                       => 'nullable|string|max:255',
            'cod_postal'                      => 'nullable|string|max:10',
            'telefono'                        => 'nullable|string|max:20',
            'fax'                             => 'nullable|string|max:20',
            'path_licencia_cazador'           => 'nullable|string|max:255',
            'path_dni'                        => 'nullable|string|max:255',
            'path_justificante_iban'          => 'nullable|string|max:255',
            'path_otros'                      => 'nullable|string|max:255',
            'path_foto'                       => 'nullable|file|mimes:jpeg,png,jpg,gif|max:4096',
        ], [
            'nombre.required'      => 'El nombre es obligatorio.',
            'id_sociedad.required' => 'La sociedad es obligatoria.',
            'id_sociedad.exists'   => 'La sociedad seleccionada no existe.',
            'usuario.required'     => 'El usuario es obligatorio.',
            'email.required'       => 'El correo electrónico es obligatorio.',
            'email.email'          => 'El correo electrónico no tiene un formato válido.',
            'fecha_nacimiento.date'=> 'La fecha de nacimiento no tiene un formato válido.',
            'fecha_alta.date'      => 'La fecha de alta no tiene un formato válido.',
        ]);

        // Preparar los datos a actualizar
        $data = $request->except(['path_foto', 'created_at', 'updated_at', 'contraseña', '_method']);

        // DNI es obligatorio en BD pero opcional en frontend. Si llega vacío o nulo, guardar como cadena vacía.
        if (!isset($data['dni']) || is_null($data['dni'])) {
            $data['dni'] = '';
        }

        // Si tipo comercial responsable es '2' (Pagina Web), poner responsable '1' y pagina_web '1'
        if (isset($data['responsable'])) {
            if ($data['responsable'] == '2') {
                $data['responsable'] = '1';
                $data['pagina_web'] = '1';
            } else {
                $data['pagina_web'] = '0';
            }
        }

        // Formatear fechas antes de actualizar (solo si vienen rellenas)
        if (!empty($data['fecha_nacimiento'])) {
            $data['fecha_nacimiento'] = date('Y-m-d', strtotime($data['fecha_nacimiento']));
        } else {
            $data['fecha_nacimiento'] = null;
        }
        if (!empty($data['fecha_alta'])) {
            $data['fecha_alta'] = date('Y-m-d', strtotime($data['fecha_alta']));
        } else {
            $data['fecha_alta'] = null;
        }

        // Actualizar en la base de datos
        Comercial::where('id', $id)->update($data);

        // Procesar la foto si se subió una nueva
        if ($request->hasFile('path_foto')) {
            $foto = $request->file('path_foto');
            $fotoPath = $foto->storeAs(
                'public/profile-pics',
                'foto_' . $id . '.' . $foto->extension()
            );
            Comercial::where('id', $id)
                ->update(['path_foto' => str_replace('public/', '', $fotoPath)]);
        }

        // Recuperar el registro actualizado para devolverlo
        $comercial = Comercial::where('id', $id)->first();

        return response()->json($comercial);
    }


    public function destroy($id)
    {
        $comercial = Comercial::findOrFail($id);
        $comercial->delete();

        return response()->json(null, 204);
    }
}
