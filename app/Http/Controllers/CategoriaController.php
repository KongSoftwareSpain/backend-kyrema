<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Facades\Log;

class CategoriaController extends Controller
{
    /**
     * Mostrar una lista de categorías.
     */
    public function index()
    {
        $categorias = Categoria::all();
        return response()->json($categorias);
    }

    /**
     * Crear una nueva categoría.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'comercial_responsable_id' => 'nullable',
            'logo' => 'nullable|image|max:2048', // acepta jpg, png, etc.
        ]);

        Log::info($validated);

        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('categorias', 'public');
            $validated['logo'] = $logoPath;
        }

        $categoria = Categoria::create($validated);

        return response()->json($categoria);
    }

    /**
     * Mostrar una categoría específica.
     */
    public function show($id)
    {
        $categoria = Categoria::find($id);

        if (!$categoria) {
            return response()->json(['error' => 'Categoría no encontrada'], 404);
        }

        return response()->json($categoria);
    }

    /**
     * Actualizar una categoría existente.
     */
    public function update(Request $request, $id)
    {
        $categoria = Categoria::find($id);

        if (!$categoria) {
            return response()->json(['error' => 'Categoría no encontrada'], 404);
        }

        Log::info($request->all());

        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'comercial_responsable_id' => 'nullable',
            'logo' => 'nullable|image|max:2048', // acepta jpg, png, etc.
        ]);

        if ($request->hasFile('logo')) {
            // Borrar el logo anterior si existe
            if ($categoria->logo && Storage::disk('public')->exists($categoria->logo)) {
                Storage::disk('public')->delete($categoria->logo);
            }

            // Subir el nuevo logo
            $logoPath = $request->file('logo')->store('categorias', 'public');
            $validated['logo'] = $logoPath;
        }

        // Actualizar la categoría
        $categoria->update($validated);

        return response()->json($categoria);
    }


    /**
     * Eliminar una categoría.
     */
    public function destroy($id)
    {
        $categoria = Categoria::find($id);

        if (!$categoria) {
            return response()->json(['error' => 'Categoría no encontrada'], 404);
        }

        $categoria->delete();

        return response()->json(['message' => 'Categoría eliminada exitosamente']);
    }
}
