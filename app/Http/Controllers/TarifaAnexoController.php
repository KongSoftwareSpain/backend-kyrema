<?php

namespace App\Http\Controllers;

use App\Models\TarifasAnexos;
use Illuminate\Http\Request;

class TarifaAnexoController extends Controller
{
    public function store(Request $request){
        // Validar los datos recibidos
        $request->validate([
            'id_tipo_anexo' => 'required|numeric',
            'id_sociedad' => 'required|numeric',
            'prima_seguro' => 'required|numeric',
            'cuota_asociacion' => 'required|numeric',
            'precio_total' => 'required|numeric',
        ]);

        // Crear el nuevo registro en la base de datos con el ID generado
        $tarifaAnexo = TarifasAnexos::create([
            'id_tipo_anexo' => $request->input('id_tipo_anexo'),
            'id_sociedad' => $request->input('id_sociedad'),
            'prima_seguro' => $request->input('prima_seguro'),
            'cuota_asociacion' => $request->input('cuota_asociacion'),
            'precio_total' => $request->input('precio_total'),
        ]);

        return response()->json($tarifaAnexo, 201);
    }

    public function getTarifaPorSociedadAndTipoAnexo($id_sociedad, $id_tipo_anexo){
        $tarifaAnexo = TarifasAnexos::where('id_sociedad', $id_sociedad)
            ->where('id_tipo_anexo', $id_tipo_anexo)
            ->first();

        return response()->json($tarifaAnexo);
    }

    public function index()
    {
        $tarifaAnexos = TarifaAnexo::all();
        return response()->json($tarifaAnexos);
    }


    public function show($id)
    {
        $tarifaAnexo = TarifaAnexo::findOrFail($id);
        return response()->json($tarifaAnexo);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'anexo' => 'string|max:255|exists:tipo_anexos,id',
            'tiene_escala' => 'boolean',
            'id_tipo_producto' => 'numeric|exists:tarifa_productos,id',
            'precio' => 'numeric',
        ]);

        $tarifaAnexo = TarifaAnexo::findOrFail($id);
        $tarifaAnexo->update($request->all());

        return response()->json($tarifaAnexo);
    }

    public function destroy($id)
    {
        $tarifaAnexo = TarifaAnexo::findOrFail($id);
        $tarifaAnexo->delete();

        return response()->json(null, 204);
    }
}
