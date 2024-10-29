<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Poliza;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

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

    public function store(Request $request)
    {
        // Validación de los campos
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

        Log::info($request->all());

        // Crear una nueva póliza sin los documentos primero
        $polizaData = $request->except(['doc_adjuntos_1', 'doc_adjuntos_2', 'doc_adjuntos_3', 'doc_adjuntos_4', 'doc_adjuntos_5', 'doc_adjuntos_6']);
        $poliza = Poliza::create($polizaData);

        // Guardar cada archivo adjunto si está presente
        for ($i = 1; $i <= 6; $i++) {
            $docField = "doc_adjuntos_$i";
            if ($request->hasFile($docField)) {
                $doc = $request->file($docField);
                $nombreArchivo = $doc->getClientOriginalName();
                $rutaArchivo = 'docs/' . $nombreArchivo;

                // Comprobar si ya existe un archivo con el mismo nombre
                if (Storage::disk('public')->exists($rutaArchivo)) {
                    return response()->json(['error' => 'Ya existe un document con el nombre '. $nombreArchivo], 400);
                }

                // Guardar la plantilla Excel en el sistema de archivos
                Storage::disk('public')->putFileAs('docs', $doc, $nombreArchivo);
            } else {
                Log::info("No se encontró el archivo $docField");
            }
        }

        $poliza->save();

        return response()->json($poliza);
    }

public function update(Request $request, $id)
{
    // Validación de los campos
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
        'doc_adjuntos_1' => 'nullable|file|mimes:pdf,doc,docx',
        'doc_adjuntos_2' => 'nullable|file|mimes:pdf,doc,docx',
        'doc_adjuntos_3' => 'nullable|file|mimes:pdf,doc,docx',
        'doc_adjuntos_4' => 'nullable|file|mimes:pdf,doc,docx',
        'doc_adjuntos_5' => 'nullable|file|mimes:pdf,doc,docx',
        'doc_adjuntos_6' => 'nullable|file|mimes:pdf,doc,docx',
    ]);

    // Buscar la póliza existente
    $poliza = Poliza::findOrFail($id);
    $poliza->update($request->except(['doc_adjuntos_1', 'doc_adjuntos_2', 'doc_adjuntos_3', 'doc_adjuntos_4', 'doc_adjuntos_5', 'doc_adjuntos_6']));

    // Actualizar o agregar nuevos archivos adjuntos
    for ($i = 1; $i <= 6; $i++) {
        $docField = "doc_adjuntos_$i";
        if ($request->hasFile($docField)) {
            // Almacenar el nuevo archivo
            $filePath = $request->file($docField)->store('public/docs');
            $poliza->$docField = basename($filePath); // Almacena el nombre del archivo
        }
    }

    $poliza->save();

    return response()->json($poliza);
}

}
