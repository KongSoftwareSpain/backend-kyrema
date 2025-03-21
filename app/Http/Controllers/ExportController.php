<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\CampoController;
use App\Http\Controllers\SociedadController;
use Illuminate\Support\Facades\Schema; // Importar el facade para el esquema
use App\Models\Sociedad;
use App\Models\Compania;

class ExportController extends Controller
{

    public function getReportData(Request $request)
    {
        // Validar los parámetros
        $request->validate([
            'tipo_producto_id' => 'required|integer',
            'fecha_desde' => 'required|date',
            'fecha_hasta' => 'required|date',
            'sociedad_id' => 'nullable|integer',
        ]);
    
        // Obtener los parámetros
        $tipoProductoId = $request->input('tipo_producto_id');
        $fechaDesde = $request->input('fecha_desde');
        $fechaHasta = $request->input('fecha_hasta');
        $sociedadId = $request->input('sociedad_id');
    
        $sociedades = SociedadController::getArrayIdSociedadesHijas($sociedadId);
    
        // Obtener las letras de identificación del tipo de producto
        $tipoProducto = DB::table('tipo_producto')->where('id', $tipoProductoId)->first();
    
        if (!$tipoProducto) {
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }
    
        // Verificar si la tabla y la columna 'subproducto' existen
        $tableName = $tipoProducto->letras_identificacion;
        $hasSubproductoColumn = Schema::hasColumn($tableName, 'subproducto');
    
        // Query principal
        $data = DB::table($tableName . ' as pc')
            ->select(
                DB::raw("pc.nombre_socio + ' ' + pc.apellido_1 + ' ' + pc.apellido_2 as nombre_completo"),
                'pc.dni',
                'pc.codigo_producto',
                'pc.fecha_de_emisión',
                'pc.fecha_de_inicio',
                DB::raw(
                    $hasSubproductoColumn 
                        ? "CASE WHEN pc.subproducto IS NOT NULL THEN '". $tipoProducto->nombre ."' + ' - ' + pc.subproducto_codigo ELSE '". $tipoProducto->nombre ."' END as producto" 
                        : "'". $tipoProducto->nombre ."' as producto"
                ),
                'pc.sociedad',
                'pc.tipo_de_pago',
                DB::raw("CASE 
                            WHEN pc.comercial_creador_id IS NOT NULL THEN c.nombre
                            ELSE NULL 
                        END as referidos")
            )
            ->leftJoin('comercial as c', 'pc.comercial_creador_id', '=', 'c.id') // JOIN con la tabla comercial
            ->whereBetween('pc.fecha_de_emisión', [$fechaDesde, $fechaHasta]);
    
        // Filtrar por sociedad si se proporciona
        if (!empty($sociedadId)) {
            $data->whereIn('pc.sociedad_id', $sociedades);
        }
    
        // Ejecutar la consulta
        $results = $data->get();
    
        if (!$hasSubproductoColumn) {
            $counts = collect([
                [
                    'tipo_producto' => $tipoProducto->nombre,
                    'cantidad' => DB::table($tableName)
                        ->whereBetween('fecha_de_emisión', [$fechaDesde, $fechaHasta])
                        ->count(),
                ]
            ]);
        } else {
            // Obtener la cantidad de productos por tipo diferenciando subproductos
            $counts = DB::table($tableName)
                ->select(
                    DB::raw( "CASE 
                                    WHEN subproducto IS NOT NULL THEN CONCAT('{$tipoProducto->nombre}', ' - ', subproducto_codigo) 
                                    ELSE '{$tipoProducto->nombre}' 
                            END as tipo_producto" 
                    ),
                    DB::raw('COUNT(*) as cantidad')
                )
                ->whereBetween('fecha_de_emisión', [$fechaDesde, $fechaHasta])
                ->groupBy(
                    DB::raw("CASE 
                                    WHEN subproducto IS NOT NULL THEN CONCAT('{$tipoProducto->nombre}', ' - ', subproducto_codigo) 
                                    ELSE '{$tipoProducto->nombre}' 
                            END") 
                )
                ->get();
        }


    
        return response()->json(['data' => $results, 'counts' => $counts]);
    }
    


    public function exportToPdf($letrasIdentificacion, Request $request)
        {
            
            try {

                $id = $request->input('id');

                Log::info($id);

                if(!$id){
                    return response()->json(['error' => 'ID no proporcionado'], 400);
                }
                
                // VALORES DEL PRODUCTO
                $valores = DB::table($letrasIdentificacion)->where('id', $id)->first();

                if (!$valores) {
                    return response()->json(['error' => 'Valores no encontrados'], 404);
                }

                // Comprobar que $valores no tiene el campo 'subproducto'
                if (property_exists($valores, 'subproducto')) {
                    $tipoProducto = DB::table('tipo_producto')->where('id', $valores->subproducto)->first();
                } else {
                    // TIPO PRODUCTO
                    $tipoProducto = DB::table('tipo_producto')->where('letras_identificacion', $letrasIdentificacion)->first();
                }
                
                if (!$tipoProducto) {
                    return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
                }

                $plantillasBase64 = [];

                // Lista de posibles plantillas
                $plantillaPaths = [
                    $valores->plantilla_path_1,
                    $valores->plantilla_path_2,
                    $valores->plantilla_path_3,
                    $valores->plantilla_path_4,
                    $valores->plantilla_path_5,
                    $valores->plantilla_path_6,
                    $valores->plantilla_path_7,
                    $valores->plantilla_path_8,
                ];

                Log::info($plantillaPaths);

                foreach ($plantillaPaths as $path) {
                    if ($path !== null) { // Verifica si no es nulo
                        $fullPath = storage_path('app/public/' . $path); // Ruta completa
                        
                        if (file_exists($fullPath)) { // Verifica si el archivo existe
                            $imageData = base64_encode(file_get_contents($fullPath));
                            $mimeType = mime_content_type($fullPath);
                            $plantillasBase64[] = "data:{$mimeType};base64,{$imageData}"; // Agrega la plantilla en base64
                        } else {
                            return response()->json(['error' => 'Plantilla no encontrada: ' . $fullPath], 404);
                        }
                    }
                }

                // Obtener los campos del tipo de producto con columna y fila no nulos
                $campos = CampoController::fetchCamposCertificado($tipoProducto->id);

                // LOGOS
                $camposLogos = CampoController::fetchCamposLogos($tipoProducto->id);

                foreach($camposLogos as $campoLogo){
                    if($campoLogo->tipo_logo == 'sociedad'){
                        if($valores->sociedad_id == env('SOCIEDAD_ADMIN_ID')){
                            $campoLogo->url = 'logos/logo_18.png';
                        } else {
                            $campoLogo->url = $valores->logo_sociedad_path;
                        }        
                    } else {
                        $campoLogo->url = Compania::find($campoLogo->entidad_id)->logo;
                    }

                    $logoPath = public_path('storage/' . $campoLogo->url);
                    Log::info($logoPath);

                    if(file_exists($logoPath)){
                        
                        $logoData = base64_encode(file_get_contents($logoPath));
                        $logoMimeType = mime_content_type($logoPath);
                        $campoLogo->base64 = "data:{$logoMimeType};base64,{$logoData}";
                    } else {
                        $campoLogo->base64 = '';
                    }
                }

                // Obtener y colocar los datos de tipo_producto_polizas y las pólizas relacionadas
                $polizasTipoProducto = DB::table('tipo_producto_polizas')
                ->where('tipo_producto_id', $tipoProducto->id)
                ->get();

                $polizas = DB::table('polizas')
                ->whereIn('id', $polizasTipoProducto->pluck('poliza_id'))
                ->get();


                // Generar un objeto con tipo de producto, valores, campos y base64 de la plantilla
                $data = [
                    'tipoProducto' => $tipoProducto,
                    'valores' => $valores,
                    'campos' => $campos,
                    'polizas_tipo_producto' => $polizasTipoProducto,
                    'polizas' => $polizas,
                    'base64Plantillas' => $plantillasBase64,
                    'logos' => $camposLogos
                ];

                return response()->json($data);


            } catch (\ErrorException $e) {

                return response()->json(['error' => $e->getMessage()], 500);

            }catch (\Exception $e) {
                Log::info($e);
                return response()->json(['error' => $e->getMessage()], 500);
            }
        }

    public function exportAnexoExcelToPdf($tipoAnexoId , Request $request){
        // Obtener el id del request
        $id = $request->input('id');

        // Obtener el tipoAnexo desde las letrasIdentificacion (Para coger la plantilla)
        $tipoAnexo = DB::table('tipo_producto')
        ->where('id', $tipoAnexoId)
        ->first();


        $letrasIdentificacionAnexo = $tipoAnexo->letras_identificacion;

        // NECESITAMOS TAMBIEN LOS DATOS DEL PRODUCTO PARA RELLENAR LOS CAMPOS DE LA PLANTILLA
        $tipoProducto = DB::table('tipo_producto')
        ->where('id', $tipoAnexo->tipo_producto_asociado)
        ->first();

        $valores = DB::table($tipoProducto->letras_identificacion)->where('id', $id)->first();

        $plantillasBase64 = [];

        // Lista de posibles plantillas
        $plantillaPaths = [
            $tipoAnexo->plantilla_path_1,
            $tipoAnexo->plantilla_path_2,
            $tipoAnexo->plantilla_path_3,
            $tipoAnexo->plantilla_path_4,
            $tipoAnexo->plantilla_path_5,
            $tipoAnexo->plantilla_path_6,
            $tipoAnexo->plantilla_path_7,
            $tipoAnexo->plantilla_path_8,
        ];

        foreach ($plantillaPaths as $path) {
            if ($path !== null) { // Verifica si no es nulo
                $fullPath = storage_path('app/public/' . $path); // Ruta completa
                
                if (file_exists($fullPath)) { // Verifica si el archivo existe
                    $imageData = base64_encode(file_get_contents($fullPath));
                    $mimeType = mime_content_type($fullPath);
                    $plantillasBase64[] = "data:{$mimeType};base64,{$imageData}"; // Agrega la plantilla en base64
                } else {
                    return response()->json(['error' => 'Plantilla no encontrada: ' . $fullPath], 404);
                }
            }
        }

                
        // Coger los anexos relacionados con el id del producto de la tabla con el nombre $letrasIdentificacionAnexo
        $anexos = DB::table($letrasIdentificacionAnexo)->where('producto_id', $id)->get();

        $campos = DB::table('campos')
            ->where('tipo_producto_id', $tipoAnexoId)
            ->whereNotNull('columna')
            ->whereNotNull('fila')
            ->whereNotIn('grupo', ['datos_anexo', 'datos_precio'])
            ->get();

        $camposAnexo = DB::table('campos')
            ->where('tipo_producto_id', $tipoAnexo->id)
            ->whereNotNull('columna')
            ->whereNotNull('fila')
            ->whereIn('grupo', ['datos_anexo', 'datos_precio'])
            ->get();    


        // LOGOS
        $camposLogos = CampoController::fetchCamposLogos($tipoProducto->id);

        foreach($camposLogos as $campoLogo){
            if($campoLogo->tipo_logo == 'sociedad'){
                if($valores->sociedad_id == env('SOCIEDAD_ADMIN_ID')){
                    $campoLogo->url = 'logos/logo_18.png';
                } else {
                    $campoLogo->url = $valores->logo_sociedad_path;
                }        
            } else {
                $campoLogo->url = Compania::find($campoLogo->entidad_id)->logo;
            }

            $logoPath = public_path('storage/' . $campoLogo->url);
            Log::info($logoPath);

            if(file_exists($logoPath)){
                
                $logoData = base64_encode(file_get_contents($logoPath));
                $logoMimeType = mime_content_type($logoPath);
                $campoLogo->base64 = "data:{$logoMimeType};base64,{$logoData}";
            } else {
                $campoLogo->base64 = '';
            }
        }

        // Obtener y colocar los datos de tipo_producto_polizas y las pólizas relacionadas
        $polizasTipoProducto = DB::table('tipo_producto_polizas')
        ->where('tipo_producto_id', $tipoAnexoId)
        ->get();

        $polizas = DB::table('polizas')
        ->whereIn('id', $polizasTipoProducto->pluck('poliza_id'))
        ->get();


        // Agregar el logo y número de póliza de cada compañía en las celdas correspondientes
        foreach ($polizasTipoProducto as $tipoPoliza) {
            $poliza = $polizas->firstWhere('id', $tipoPoliza->poliza_id);
            $numeroPoliza = $poliza ? $poliza->numero : 'N/A';
        }

        $data = [
            'tipoProducto' => $tipoAnexo,
            'valores' => $valores,
            'campos' => $campos,
            'anexos' => $anexos,
            'camposAnexo' => $camposAnexo,
            'polizas_tipo_producto' => $polizasTipoProducto,
            'polizas' => $polizas,
            'base64Plantillas' => $plantillasBase64,
            'logos' => $camposLogos
        ];

        return response()->json($data);
    }


    public function getPlantillaBase64(Request $request){
        //Coger la ruta
        $path = $request->input('path');
        $file = Storage::disk('public')->get($path);
        $base64 = base64_encode($file);
        return response()->json(['base64' => $base64]);
    }

    public function getLogoBase64($tipoLogo, $entidad_id)
    {
        if($entidad_id == null){
            $entidad = Sociedad::find(env('SOCIEDAD_ADMIN_ID'));
        }

        if ($tipoLogo === env('TIPO_LOGO_SOCIEDAD', 'sociedad')) {
            $entidad = Sociedad::find($entidad_id);
        } else {
            $entidad = Compania::find($entidad_id);
        }

        Log::info($entidad);

        if (!$entidad) {
            return response()->json(['error' => 'Entidad no encontrada'], 404);
        }

        if (!$entidad->logo) {
            return null;
        }

        $path = public_path('storage/' . $entidad->logo);
        
        Log::info($path);

        if (!file_exists($path)) {
            return null;
        }

        $imageData = file_get_contents($path);
        $imageData = 'data:image/png;base64,' . base64_encode($imageData);

        Log::info($imageData);

        return response()->json($imageData);
    }



}
