<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Compania;
use Illuminate\Support\Facades\Storage;

class CompaniaController extends Controller
{
    public function getAll(){

        $companias = Compania::all();

        return response()->json($companias);
    }

    public function getCompanyById($id){

        $compania = Compania::find($id);

        return response()->json($compania);
    }

    public function createCompany(Request $request) {
        $request->validate([
            'nombre' => 'required',
            'CIF' => 'required',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Validar imagen
            'IBAN' => 'required',
            'nombre_contacto_1' => 'nullable',
            'cargo_contacto_1' => 'nullable',
            'email_contacto_1' => 'nullable',
            'telefono_contacto_1' => 'nullable',
            'comentarios' => 'nullable'
        ]);
    
        $data = $request->except('logo');
    
        // Guardar el archivo si se envió
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('logos', 'public'); // Guarda en storage/app/public/logos
            $data['logo'] = $logoPath;
        }
    
        $compania = Compania::create($data);
    
        return response()->json($compania);
    }
    

    public function updateCompany(Request $request, $id) {
        $request->validate([
            'nombre' => 'required',
            'CIF' => 'required',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'IBAN' => 'required',
            'nombre_contacto_1' => 'nullable',
            'cargo_contacto_1' => 'nullable',
            'email_contacto_1' => 'nullable',
            'telefono_contacto_1' => 'nullable',
            'comentarios' => 'nullable'
        ]);
    
        $compania = Compania::findOrFail($id);
        $data = $request->except('logo');
    
        // Si hay un nuevo logo, lo reemplaza
        if ($request->hasFile('logo')) {
            if ($compania->logo) {
                Storage::disk('public')->delete($compania->logo); // Elimina el logo anterior
            }
            $logoPath = $request->file('logo')->store('logos', 'public');
            $data['logo'] = $logoPath;
        }
    
        $compania->update($data);
    
        return response()->json($compania);
    }
    

    public static function getCompanyLogo($id){

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
