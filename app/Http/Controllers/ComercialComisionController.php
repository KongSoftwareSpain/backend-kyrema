<?php

namespace App\Http\Controllers;

use App\Models\ComercialComision;
use Illuminate\Http\Request;

class ComercialComisionController extends Controller
{
    public function index()
    {
        $comercialComisiones = ComercialComision::all();
        return response()->json($comercialComisiones);
    }

    public function store(Request $request)
    {
        $request->validate([
            'id_comercial' => 'required|numeric|exists:comercial,id',
            'porcentual' => 'required|boolean',
            'comision' => 'required|numeric',
        ]);

        $comercialComision = ComercialComision::create($request->all());

        return response()->json($comercialComision, 201);
    }

    public function show($id)
    {
        $comercialComision = ComercialComision::findOrFail($id);
        return response()->json($comercialComision);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'porcentual' => 'boolean',
            'comision' => 'numeric',
        ]);

        $comercialComision = ComercialComision::findOrFail($id);
        $comercialComision->update($request->all());

        return response()->json($comercialComision);
    }

    public function destroy($id)
    {
        $comercialComision = ComercialComision::findOrFail($id);
        $comercialComision->delete();

        return response()->json(null, 204);
    }
}
