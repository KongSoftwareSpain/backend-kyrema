<?php

namespace App\Http\Controllers;

use App\Models\EscaladoAnexo;
use Illuminate\Http\Request;

class EscaladoAnexoController extends Controller
{
    public function index()
    {
        $escaladoAnexos = EscaladoAnexo::all();
        return response()->json($escaladoAnexos);
    }

    public function store(Request $request)
    {
        $request->validate([
            'anexo_id' => 'required|numeric|exists:tipo_anexos,id',
            'desde' => 'required|numeric',
            'hasta' => 'required|numeric',
            'precio' => 'required|numeric',
        ]);

        $escaladoAnexo = EscaladoAnexo::create($request->all());

        return response()->json($escaladoAnexo, 201);
    }

    public function show($anexo_id, $desde, $hasta)
    {
        $escaladoAnexo = EscaladoAnexo::where('anexo_id', $anexo_id)
                                      ->where('desde', $desde)
                                      ->where('hasta', $hasta)
                                      ->firstOrFail();
        return response()->json($escaladoAnexo);
    }

    public function update(Request $request, $anexo_id, $desde, $hasta)
    {
        $request->validate([
            'precio' => 'numeric',
        ]);

        $escaladoAnexo = EscaladoAnexo::where('anexo_id', $anexo_id)
                                      ->where('desde', $desde)
                                      ->where('hasta', $hasta)
                                      ->firstOrFail();
        $escaladoAnexo->update($request->all());

        return response()->json($escaladoAnexo);
    }

    public function destroy($anexo_id, $desde, $hasta)
    {
        $escaladoAnexo = EscaladoAnexo::where('anexo_id', $anexo_id)
                                      ->where('desde', $desde)
                                      ->where('hasta', $hasta)
                                      ->firstOrFail();
        $escaladoAnexo->delete();

        return response()->json(null, 204);
    }
}
