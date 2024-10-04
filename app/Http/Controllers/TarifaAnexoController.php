<?php

namespace App\Http\Controllers;

use App\Models\TarifasAnexos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\TarifasProducto;

class TarifaAnexoController extends Controller
{
    public function store(Request $request){
        // Validar los datos recibidos
        $request->validate([
            'id_tipo_anexo' => 'required|numeric',
            'id_sociedad' => 'required|numeric',
            'precio_base' => 'required|numeric',
            'extra_1' => 'required|numeric',
            'extra_2' => 'required|numeric',
            'extra_3' => 'required|numeric',
            'precio_total' => 'required|numeric',
        ]);
    
        // Insertar el nuevo registro y obtener el ID generado
        $id = DB::table('tarifas_anexos')->insertGetId([
            'id_tipo_anexo' => $request->input('id_tipo_anexo'),
            'id_sociedad' => $request->input('id_sociedad'),
            'precio_base' => $request->input('precio_base'),
            'extra_1' => $request->input('extra_1'),
            'extra_2' => $request->input('extra_2'),
            'extra_3' => $request->input('extra_3'),
            'precio_total' => $request->input('precio_total'),
            'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
        ]);
    
        // Devolver una respuesta JSON con el ID generado
        return response()->json(['id' => $id], 201);
    }

    public function getTarifaPorSociedadAndTipoAnexo($id_sociedad, $id_tipo_anexo){
        $tarifaAnexo = TarifasProducto::where('id_sociedad', $id_sociedad)
            ->where('tipo_producto_id', $id_tipo_anexo)
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


    public function destroy($id)
    {
        $tarifaAnexo = TarifaAnexo::findOrFail($id);
        $tarifaAnexo->delete();

        return response()->json(null, 204);
    }
}
