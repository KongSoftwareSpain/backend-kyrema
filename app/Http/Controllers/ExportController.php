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
use Carbon\Carbon;

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

        // Restricción de visibilidad por sociedad
        $user = auth('comercial')->user();
        if ($user && $user->id_sociedad != env('SOCIEDAD_ADMIN_ID')) {
            $sociedadId = $user->id_sociedad;
        }

        // Si no se proporciona sociedadId, no filtramos por sociedades hijas por defecto aquí, 
        // dejamos que el bloque condicional de más abajo lo gestione.
        $sociedades = !empty($sociedadId) ? SociedadController::getArrayIdSociedadesHijas($sociedadId) : [];

        // Obtener las letras de identificación del tipo de producto
        $tipoProducto = DB::table('tipo_producto')->where('id', $tipoProductoId)->first();

        if (!$tipoProducto) {
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }

        // Verificar si la tabla y la columna 'subproducto' existen
        $tableName = $tipoProducto->letras_identificacion;
        $hasSubproductoColumn = Schema::hasColumn($tableName, 'subproducto');

        // Rango [desde, hasta+1) para no liarte con horas
        $desde = Carbon::parse($fechaDesde)->toDateString();          // 'YYYY-MM-DD'
        $hasta = Carbon::parse($fechaHasta)->addDay()->toDateString(); // día siguiente

        // Estilo de fecha según cómo guardes la columna NVARCHAR:
        // - ISO 8601 con 'T'  -> 126 (p.ej. 2025-09-25T09:57:26)
        // - 'YYYY-MM-DD hh:mm:ss' -> 120
        $style = 126; // ajusta a 120 si tu formato no lleva 'T'

        $query = DB::table($tableName . ' as pc')
            ->selectRaw(
                // Evita que NULL anule la concatenación: usa CONCAT/ISNULL
                "CONCAT(pc.nombre_socio,' ',pc.apellido_1,' ',ISNULL(pc.apellido_2,'')) as nombre_completo"
            )
            ->addSelect([
                'pc.dni',
                'pc.codigo_producto',
            ])
            // Si quieres devolver también las fechas ya convertidas:
            ->selectRaw("TRY_CONVERT(datetime2, pc.[fecha_de_emisión], {$style}) as fecha_de_emision")
            ->selectRaw("TRY_CONVERT(datetime2, pc.[fecha_de_inicio], {$style})  as fecha_de_inicio")
            ->selectRaw("COALESCE((SELECT TOP 1 soc.nombre FROM socios_comerciales sc JOIN comercial cs ON sc.id_comercial = cs.id JOIN sociedad soc ON cs.id_sociedad = soc.id WHERE sc.id_socio = pc.socio_id), pc.sociedad) as sociedad")
            ->addSelect([
                'pc.tipo_de_pago',
            ])
            ->leftJoin('comercial as c', 'pc.comercial_creador_id', '=', 'c.id')
            ->selectRaw("CASE WHEN pc.comercial_creador_id IS NOT NULL AND c.nombre IS NOT NULL THEN c.nombre ELSE 'No hay' END as referidos");

        // Producto (con bindings, nada de concatenar PHP dentro del SQL)
        if ($hasSubproductoColumn) {
            $query->addSelect('pc.subproducto');
            $query->selectRaw(
                "CASE WHEN pc.subproducto IS NOT NULL THEN ? + ' - ' + pc.subproducto_codigo ELSE ? END as producto",
                [$tipoProducto->nombre, $tipoProducto->nombre]
            );
        } else {
            $query->selectRaw("? as producto", [$tipoProducto->nombre]);
        }

        // Filtro de fechas con conversión explícita + rango semiclosed
        $query->whereRaw(
            "TRY_CONVERT(datetime2, pc.[fecha_de_emisión], {$style}) >= ? AND TRY_CONVERT(datetime2, pc.[fecha_de_emisión], {$style}) < ?",
            [$desde, $hasta]
        );

        // Filtrar por sociedad si se proporciona
        if (!empty($sociedadId)) {
            $query->whereIn('pc.sociedad_id', $sociedades);
        }

        // Ejecutar la consulta
        $results = $query->get();

        // Obtener los ids de los tipos de producto (padre y subproductos si existen)
        $tipoProductoIds = collect([$tipoProductoId]);
        if ($hasSubproductoColumn) {
            $subIds = $results->pluck('subproducto')->filter()->unique();
            $tipoProductoIds = $tipoProductoIds->merge($subIds);
        }

        // Obtener las pólizas asociadas
        $polizasMap = DB::table('tipo_producto_polizas as tpp')
            ->join('polizas as p', 'tpp.poliza_id', '=', 'p.id')
            ->whereIn('tpp.tipo_producto_id', $tipoProductoIds)
            ->select('tpp.tipo_producto_id', 'p.numero')
            ->get()
            ->groupBy('tipo_producto_id')
            ->map(function ($group) {
                return $group->pluck('numero')->unique()->implode(' / ');
            });

        // Asignar pólizas y formatear fechas a cada fila
        $results->transform(function ($item) use ($polizasMap, $tipoProductoId) {
            $pid = property_exists($item, 'subproducto') && $item->subproducto !== null ? $item->subproducto : $tipoProductoId;
            $item->poliza = $polizasMap->get($pid) ?: 'N/A';

            if (!empty($item->fecha_de_emision)) {
                try {
                    $item->fecha_de_emision = Carbon::parse($item->fecha_de_emision)->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    // Fallback
                }
            }

            if (!empty($item->fecha_de_inicio)) {
                try {
                    $item->fecha_de_inicio = Carbon::parse($item->fecha_de_inicio)->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    // Fallback
                }
            }

            return $item;
        });

        if (!$hasSubproductoColumn) {
            $countsQuery = DB::table($tableName)
                ->whereRaw(
                    "TRY_CONVERT(datetime2, [fecha_de_emisión], {$style}) >= ? AND TRY_CONVERT(datetime2, [fecha_de_emisión], {$style}) < ?",
                    [$desde, $hasta]
                );

            if (!empty($sociedadId)) {
                $countsQuery->whereIn('sociedad_id', $sociedades);
            }

            $counts = collect([
                [
                    'tipo_producto' => $tipoProducto->nombre,
                    'cantidad' => $countsQuery->count(),
                ]
            ]);
        } else {
            // Obtener la cantidad de productos por tipo diferenciando subproductos
            $countsQuery = DB::table($tableName)
                ->select(
                    DB::raw(
                        "CASE 
                                    WHEN subproducto IS NOT NULL THEN CONCAT('{$tipoProducto->nombre}', ' - ', subproducto_codigo) 
                                    ELSE '{$tipoProducto->nombre}' 
                            END as tipo_producto"
                    ),
                    DB::raw('COUNT(*) as cantidad')
                )
                ->whereRaw(
                    "TRY_CONVERT(datetime2, [fecha_de_emisión], {$style}) >= ? AND TRY_CONVERT(datetime2, [fecha_de_emisión], {$style}) < ?",
                    [$desde, $hasta]
                );

            if (!empty($sociedadId)) {
                $countsQuery->whereIn('sociedad_id', $sociedades);
            }

            $counts = $countsQuery->groupBy(
                DB::raw("CASE 
                                    WHEN subproducto IS NOT NULL THEN CONCAT('{$tipoProducto->nombre}', ' - ', subproducto_codigo) 
                                    ELSE '{$tipoProducto->nombre}' 
                            END")
            )
                ->get();
        }

        return response()->json(['data' => $results, 'counts' => $counts]);
    }



    public function exportToPdf($letrasIdentificacion, $id)
    {

        try {

            if (!$id) {
                return response()->json(['error' => 'ID no proporcionado'], 400);
            }

            // VALORES DEL PRODUCTO
            $valores = DB::table($letrasIdentificacion)->where('id', $id)->first();

            if (!$valores) {
                return response()->json(['error' => 'Valores no encontrados'], 400);
            }

            // Comprobar que $valores no tiene el campo 'subproducto'
            if (property_exists($valores, 'subproducto') && $valores->subproducto !== null) {
                $tipoProducto = DB::table('tipo_producto')->where('id', $valores->subproducto)->first();
            } else {
                // TIPO PRODUCTO
                $tipoProducto = DB::table('tipo_producto')->where('letras_identificacion', $letrasIdentificacion)->first();
            }

            if (!$tipoProducto) {
                return response()->json(['error' => 'Tipo de producto no encontrado'], 400);
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

            Log::info(print_r($plantillaPaths, true));

            foreach ($plantillaPaths as $path) {
                if ($path !== null) {
                    $fullPath = storage_path('app/public/' . $path);

                    if (!file_exists($fullPath)) {
                        $fullPath = public_path('storage/' . $path);
                    }

                    if (file_exists($fullPath)) {
                        $imageData = base64_encode(file_get_contents($fullPath));
                        $mimeType = mime_content_type($fullPath);
                        $plantillasBase64[] = "data:{$mimeType};base64,{$imageData}";
                    } else {
                        Log::error("Plantilla no encontrada. Intentado en: " . storage_path('app/public/' . $path) . " y " . public_path('storage/' . $path));
                        return response()->json(['error' => 'Plantilla no encontrada: ' . $path], 400);
                    }
                }
            }

            // Obtener los campos del tipo de producto con columna y fila no nulos
            $campos = CampoController::fetchCamposCertificado($tipoProducto->id);

            // LOGOS
            $camposLogos = CampoController::fetchCamposLogos($tipoProducto->id);

            foreach ($camposLogos as $campoLogo) {
                if ($campoLogo->tipo_logo == 'sociedad') {
                    if ($valores->sociedad_id == env('SOCIEDAD_ADMIN_ID')) {
                        $campoLogo->url = 'logos/logo_18.png';
                    } else {
                        $campoLogo->url = $valores->logo_sociedad_path;
                    }
                } else {
                    $campoLogo->url = Compania::find($campoLogo->entidad_id)->logo;
                }

                $logoPath = public_path('storage/' . $campoLogo->url);
                Log::info($logoPath);

                if (file_exists($logoPath)) {

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
        } catch (\Exception $e) {
            Log::info($e);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function exportAnexoExcelToPdf($tipoAnexoId, Request $request)
    {
        Log::info("DEBUG: exportAnexoExcelToPdf alcanzado. tipoAnexoId: $tipoAnexoId, id: " . $request->input('id'));

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
            if ($path !== null) {
                $fullPath = storage_path('app/public/' . $path);

                if (!file_exists($fullPath)) {
                    $fullPath = public_path('storage/' . $path);
                }

                if (file_exists($fullPath)) {
                    $imageData = base64_encode(file_get_contents($fullPath));
                    $mimeType = mime_content_type($fullPath);
                    $plantillasBase64[] = "data:{$mimeType};base64,{$imageData}";
                } else {
                    Log::error("Plantilla de anexo no encontrada. Intentado en: " . storage_path('app/public/' . $path) . " y " . public_path('storage/' . $path));
                    return response()->json(['error' => 'Plantilla no encontrada: ' . $path], 400);
                }
            }
        }


        // Coger los anexos relacionados con el id del producto de la tabla con el nombre $letrasIdentificacionAnexo
        $anexos = DB::table($letrasIdentificacionAnexo)->where('producto_id', $id)->get();

        /* =======================================================================================================
           EXPLICACIÓN MUY GRANDE: FORZADO ESTRICTO DE FECHAS EN BACKEND (DESACTIVADO)
           =======================================================================================================
           Este bloque está comentado a petición. Su objetivo era solucionar de raíz el problema de que el PDF
           imprima la fecha del producto (ej: 01/06) en lugar de la del anexo (ej: 30/06).
           
           ¿Por qué pasa esto en la aplicación?
           1. Cuando se aprueba un producto, el PDF se genera y se guarda en Azure.
           2. Si se cambian las fechas del anexo sin volver a generar el PDF "Limpiamente" o si el cache de Azure
              sigue activo, el sistema descarga el PDF antiguo o utiliza las variables del producto padre que están
              en la variable $valores.
           
           ¿Qué hacía este código?
           Para que sea FÍSICAMENTE IMPOSIBLE que el frontend (con todos sus loops y validaciones) se equivoque
           e imprima la fecha del producto padre, este bloque tomaba las fechas de '$valores' (el producto)
           y las SOBREESCRIBÍA con fuerza bruta usando las fechas del '$anexoPrincipal'. 
           
           Al hacer esto en el Backend:
           - No importa si el generador de PDF confunde los sinónimos.
           - No importa si en la plantilla la variable se llama "fecha_de_emisión" o "inicio".
           - La única fecha que viajaría por internet hacia el frontend sería la fecha del anexo.
           
           Código original (Comentado):
           
           if ($anexos->count() > 0) {
               $anexoPrincipal = $anexos->first();
               
               // Reemplazar siempre por los valores del anexo
               $valores->fecha_de_inicio = $anexoPrincipal->fecha_de_inicio ?? null;
               
               // Cubrir emisiones con y sin tilde
               if(isset($anexoPrincipal->fecha_de_emisión)) {
                   $valores->fecha_de_emisión = $anexoPrincipal->fecha_de_emisión;
               }
               if(isset($anexoPrincipal->fecha_de_emision)) {
                   $valores->fecha_de_emision = $anexoPrincipal->fecha_de_emision;
               }
           } else {
               $valores->fecha_de_inicio = null;
               $valores->fecha_de_emisión = null;
               $valores->fecha_de_emision = null;
           }
        ======================================================================================================= */


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

        foreach ($camposLogos as $campoLogo) {
            if ($campoLogo->tipo_logo == 'sociedad') {
                if ($valores->sociedad_id == env('SOCIEDAD_ADMIN_ID')) {
                    $campoLogo->url = 'logos/logo_18.png';
                } else {
                    $campoLogo->url = $valores->logo_sociedad_path;
                }
            } else {
                $campoLogo->url = Compania::find($campoLogo->entidad_id)->logo;
            }

            $logoPath = public_path('storage/' . $campoLogo->url);
            Log::info($logoPath);

            if (file_exists($logoPath)) {

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


    public function getPlantillaBase64(Request $request)
    {
        //Coger la ruta
        $path = $request->input('path');
        $file = Storage::disk('public')->get($path);
        $base64 = base64_encode($file);
        return response()->json(['base64' => $base64]);
    }

    public function getLogoBase64($tipoLogo, $entidad_id)
    {
        if ($entidad_id == null) {
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
