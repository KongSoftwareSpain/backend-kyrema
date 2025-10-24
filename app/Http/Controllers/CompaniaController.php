<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Compania;
use Illuminate\Support\Facades\Storage;

class CompaniaController extends Controller
{
    public function getAll()
    {

        $companias = Compania::all();

        return response()->json($companias);
    }

    public function getCompanyById($id)
    {

        $compania = Compania::find($id);

        return response()->json($compania);
    }

    public function createCompany(Request $request)
    {
        $rules = [
            'nombre'              => 'required',
            'CIF'                 => 'required',
            'logo'                => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'IBAN'                => 'required',
            'nombre_contacto_1'   => 'nullable',
            'cargo_contacto_1'    => 'nullable',
            'email_contacto_1'    => 'nullable',
            'telefono_contacto_1' => 'nullable',
            'comentarios'         => 'nullable',
        ];

        $messages = [
            'required' => 'El campo :attribute es obligatorio.',
            'email'    => 'El :attribute debe ser un correo electrónico válido.',
            'image'    => 'El :attribute debe ser una imagen.',
            'mimes'    => 'El :attribute debe ser de tipo: :values.',
            'dimensions' => 'El :attribute debe tener entre 256×256 y 3000×3000 píxeles.',
            'max.file' => 'El :attribute no debe pesar más de :max kilobytes.',
            'max.string' => 'El :attribute no debe superar :max caracteres.',
        ];

        $attributes = [
            'nombre'              => 'nombre de la compañía',
            'CIF'                 => 'CIF',
            'logo'                => 'logo',
            'IBAN'                => 'IBAN',
            'nombre_contacto_1'   => 'nombre del contacto',
            'cargo_contacto_1'    => 'cargo del contacto',
            'email_contacto_1'    => 'email del contacto',
            'telefono_contacto_1' => 'teléfono del contacto',
            'comentarios'         => 'comentarios',
        ];

        $request->validate($rules, $messages, $attributes);

        $data = $request->except('logo');

        // Guardar el archivo si se envió
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('logos', 'public'); // Guarda en storage/app/public/logos
            $data['logo'] = $logoPath;
        }

        $compania = Compania::create($data);

        return response()->json($compania);
    }


    public function updateCompany(Request $request, $id)
    {
        $rules = [
            'nombre'              => 'required',
            'CIF'                 => 'required',
            'logo'                => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'IBAN'                => 'required',
            'nombre_contacto_1'   => 'nullable',
            'cargo_contacto_1'    => 'nullable',
            'email_contacto_1'    => 'nullable',
            'telefono_contacto_1' => 'nullable',
            'comentarios'         => 'nullable',
        ];

        $messages = [
            'required' => 'El campo :attribute es obligatorio.',
            'email'    => 'El :attribute debe ser un correo electrónico válido.',
            'image'    => 'El :attribute debe ser una imagen.',
            'mimes'    => 'El :attribute debe ser de tipo: :values.',
            'max.file' => 'El :attribute no debe pesar más de :max kilobytes.',
            'max.string' => 'El :attribute no debe superar :max caracteres.',
        ];

        $attributes = [
            'nombre'              => 'nombre de la compañía',
            'CIF'                 => 'CIF',
            'logo'                => 'logo',
            'IBAN'                => 'IBAN',
            'nombre_contacto_1'   => 'nombre del contacto',
            'cargo_contacto_1'    => 'cargo del contacto',
            'email_contacto_1'    => 'email del contacto',
            'telefono_contacto_1' => 'teléfono del contacto',
            'comentarios'         => 'comentarios',
        ];

        $request->validate($rules, $messages, $attributes);

        $compania = Compania::findOrFail($id);
        $data = $request->except('logo');

        if ($request->hasFile('logo')) {
            if ($compania->logo) {
                Storage::disk('public')->delete($compania->logo);
            }
            $logoPath = $request->file('logo')->store('logos', 'public');
            $data['logo'] = $logoPath;
        }

        $compania->update($data);

        return response()->json($compania);
    }


    public static function getCompanyLogo($id)
    {

        $compania = Compania::find($id);

        return response()->json($compania->logo);
    }

    public function deleteCompany($id)
    {
        $compania = Compania::findOrFail($id);

        // Eliminar las pólizas asociadas antes de eliminar la compañía
        $compania->polizas()->delete();

        // Eliminar la compañía
        $compania->delete();

        return response()->json('Compañía y pólizas eliminadas correctamente');
    }
}
