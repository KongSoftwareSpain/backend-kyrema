<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Poliza;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
        $polizaData['estado'] = $polizaData['estado'] ?? '';
        $polizaData['descripcion'] = $polizaData['descripcion'] ?? '';
        $polizaData['observaciones'] = $polizaData['observaciones'] ?? '';
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

                // Guardar el nombre del archivo en la base de datos
                $poliza->$docField = $nombreArchivo;
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
            'doc_adjuntos_1' => 'nullable',
            'doc_adjuntos_2' => 'nullable',
            'doc_adjuntos_3' => 'nullable',
            'doc_adjuntos_4' => 'nullable',
            'doc_adjuntos_5' => 'nullable',
            'doc_adjuntos_6' => 'nullable',
        ]);

        Log::info($request->all());

        // Buscar la póliza existente
        $poliza = Poliza::findOrFail($id);
        $poliza->update($request->except(['doc_adjuntos_1', 'doc_adjuntos_2', 'doc_adjuntos_3', 'doc_adjuntos_4', 'doc_adjuntos_5', 'doc_adjuntos_6']));
        $polizaData['estado'] = $polizaData['estado'] ?? '';
        $polizaData['descripcion'] = $polizaData['descripcion'] ?? '';
        $polizaData['observaciones'] = $polizaData['observaciones'] ?? '';

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

                // Guardar el nombre del archivo en la base de datos
                $poliza->$docField = $nombreArchivo;
            } else {
                Log::info("No se encontró el archivo $docField");
            }
        }

        $poliza->save();

        return response()->json($poliza);
    }

    public function downloadPoliza(Request $request, $id){
        $poliza = Poliza::findOrFail($id);

        $docField = $request->doc_adjunto;
        $doc = $poliza->$docField;

        if (!$doc) {
            return response()->json(['error' => 'No se encontró el documento adjunto'], 404);
        }

        $rutaArchivo = 'docs/' . $doc;

        if (!Storage::disk('public')->exists($rutaArchivo)) {
            return response()->json(['error' => 'No se encontró el documento adjunto'], 404);
        }

        return Storage::disk('public')->download($rutaArchivo);
    }

    public function getPolizasByTipoProducto($id){

        $polizas = DB::table('tipo_producto_polizas')
            ->select('id',
            'poliza_id',
            'compania_id',
            'fila',
            'columna',
            'page',
            'copia')
            ->where('tipo_producto_id', $id)
            ->get();

        return response()->json($polizas);
    }

    public function updatePolizas(Request $request, $id){
        $polizas = $request->all();

        Log::info($polizas);

        //Borrar todas las plizas conectadas anteriormente:
        DB::table('tipo_producto_polizas')
            ->where('tipo_producto_id', $id)
            ->delete(); 

        foreach ($polizas as $poliza) {
            DB::table('tipo_producto_polizas')
                ->insert([
                    'tipo_producto_id' => $id,
                    'poliza_id' => $poliza['poliza_id'],
                    'compania_id' => $poliza['compania_id'],
                    'fila' => $poliza['fila'],
                    'columna' => $poliza['columna'],
                    'page' => $poliza['page'],
                    'copia' => $poliza['copia'],
                ]);
        }

        return response()->json($polizas);
    }

    public function destroy($id){
        $poliza = Poliza::findOrFail($id);

        // Eliminar los documentos adjuntos
        for ($i = 1; $i <= 6; $i++) {
            $docField = "doc_adjuntos_$i";
            if ($poliza->$docField) {
                $rutaArchivo = 'docs/' . $poliza->$docField;
                if (Storage::disk('public')->exists($rutaArchivo)) {
                    Storage::disk('public')->delete($rutaArchivo);
                }
            }
        }

        $poliza->delete();

        return response()->json(['message' => 'Póliza eliminada correctamente']);
    }

}
