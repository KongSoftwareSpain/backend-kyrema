<?php

namespace App\Http\Controllers;

use App\Models\CPTipoProducto;
use Illuminate\Http\Request;

class CPTipoProductoController extends Controller
{
    public function index()
    {
        $cpfFamilias = CPTipoProducto::all();
        return response()->json($cpfFamilias);
    }

    public function store(Request $request)
    {
        $request->validate([
            'id_tipo_producto' => 'required|numeric|exists:tipo_anexos,id',
            'vetado' => 'required|boolean',
            'cp' => 'required|string|max:10',
        ]);

        $cpfFamilia = CPTipoProducto::create($request->all());

        return response()->json($cpfFamilia, 201);
    }

    public function show($id)
    {
        $cpfFamilia = CPTipoProducto::findOrFail($id);
        return response()->json($cpfFamilia);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'id_familia' => 'numeric|exists:tipo_anexos,id',
            'vetado' => 'boolean',
            'cp' => 'string|max:10',
        ]);

        $cpfFamilia = CPTipoProducto::findOrFail($id);
        $cpfFamilia->update($request->all());

        return response()->json($cpfFamilia);
    }

    public function destroy($id)
    {
        $cpfFamilia = CPTipoProducto::findOrFail($id);
        $cpfFamilia->delete();

        return response()->json(null, 204);
    }
}
