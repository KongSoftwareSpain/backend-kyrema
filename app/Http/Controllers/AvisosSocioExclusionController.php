<?php

namespace App\Http\Controllers;

use App\Models\Socio;
use App\Models\SocioExcluidoAviso;
use Illuminate\Http\Request;

class AvisosSocioExclusionController extends Controller
{
    private function autorizarAdmin(Request $request): bool
    {
        $comercial = $request->user();
        return $comercial && $comercial->id_sociedad == env('SOCIEDAD_ADMIN_ID', 1);
    }

    public function index(Request $request)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $excluidos = SocioExcluidoAviso::with('socio:id,dni,nombre_socio,apellido_1,apellido_2,email')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($excluidos);
    }

    public function store(Request $request)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $datos = $request->validate([
            'socio_id' => ['required', 'integer', 'exists:socios,id'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $excluido = SocioExcluidoAviso::firstOrCreate(
            ['socio_id' => $datos['socio_id']],
            ['motivo' => $datos['motivo'] ?? null]
        );

        return response()->json($excluido->load('socio:id,dni,nombre_socio,apellido_1,apellido_2,email'), 201);
    }

    public function destroy(Request $request, $id)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        SocioExcluidoAviso::findOrFail($id)->delete();

        return response()->json(['message' => 'Socio eliminado de la lista de exclusión.']);
    }
}
