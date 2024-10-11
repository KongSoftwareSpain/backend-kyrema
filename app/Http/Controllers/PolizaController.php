<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PolizaController extends Controller
{
    public function getPolizasByCompany($id){
        $polizas = Poliza::where('compania_id', $id)->get();

        return response()->json($polizas);
    }

    public function getPolizaById($id){
        $poliza = Poliza::find($id);

        return response()->json($poliza);
    }

    public function store(Request $request){
        $request->validate([
            'compania_id' => 'required',
            'numero' => 'required',
            'ramo' => 'required',
            'descripcion' => 'nullable',
            'fecha_inicio' => 'nullable',
            'fecha_fin_venta' => 'nullable',
            'fecha_fin_servicio' => 'nullable',
            'prima_neta' => 'nullable',
            'comision' => 'nullable',
            'estado' => 'nullable',
            'observaciones' => 'nullable',
            'doc_adjuntos_1' => 'nullable',
            'doc_adjuntos_2' => 'nullable',
            'doc_adjuntos_3' => 'nullable',
            'doc_adjuntos_4' => 'nullable',
            'doc_adjuntos_5' => 'nullable',
            'doc_adjuntos_6' => 'nullable',
        ]);

        $poliza = Poliza::create($request->all());

        return response()->json($poliza);
    }

    public function update($id){
        $request->validate([
            'compania_id' => 'required',
            'numero' => 'required',
            'ramo' => 'required',
            'descripcion' => 'nullable',
            'fecha_inicio' => 'nullable',
            'fecha_fin_venta' => 'nullable',
            'fecha_fin_servicio' => 'nullable',
            'prima_neta' => 'nullable',
            'comision' => 'nullable',
            'estado' => 'nullable',
            'observaciones' => 'nullable',
            'doc_adjuntos_1' => 'nullable',
            'doc_adjuntos_2' => 'nullable',
            'doc_adjuntos_3' => 'nullable',
            'doc_adjuntos_4' => 'nullable',
            'doc_adjuntos_5' => 'nullable',
            'doc_adjuntos_6' => 'nullable',
        ]);

        $poliza = Poliza::find($id);
        $poliza->update($request->all());

        return response()->json($poliza);
    }
}
