<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Compania;

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

    public function createCompany(Request $request){

        $request->validate([
            'nombre' => 'required',
            'CIF' => 'required',
            'logo' => 'nullable',
            'IBAN' => 'required',
            'nombre_contacto_1' => 'nullable',
            'cargo_contacto_1' => 'nullable',
            'email_contacto_1' => 'nullable',
            'telefono_contacto_1' => 'nullable',
            'comentarios' => 'nullable'
        ]);

        $compania = Compania::create($request->all());

        return response()->json($compania);
    }

    public function updateCompany(Request $request, $id){

        $request->validate([
            'nombre' => 'required',
            'CIF' => 'required',
            'logo' => 'required',
            'IBAN' => 'required',
            'nombre_contacto_1' => 'nullable',
            'cargo_contacto_1' => 'nullable',
            'email_contacto_1' => 'nullable',
            'telefono_contacto_1' => 'nullable',
            'comentarios' => 'nullable'
        ]);

        $compania = Compania::find($id);
        $compania->update($request->all());

        return response()->json($compania);
    }

    public static function getCompanyLogo($id){

        $compania = Compania::find($id);

        return response()->json($compania->logo);
    }
}
