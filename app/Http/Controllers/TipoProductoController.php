<?php

namespace App\Http\Controllers;

use App\Models\TipoProducto;
use Illuminate\Http\Request;
use App\Models\TipoProductoSociedad;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class TipoProductoController extends Controller
{
    public function index()
    {
        $tiposProducto = TipoProducto::all();
        return response()->json($tiposProducto);
    }

    public function getTiposProductoPorSociedad($id_sociedad)
    {
        // Obtener los IDs de TipoProducto asociados con la sociedad
        $tipoProductoIds = TipoProductoSociedad::where('id_sociedad', $id_sociedad)->pluck('id_tipo_producto');

        // Obtener los TipoProducto basados en los IDs obtenidos
        $tiposProducto = TipoProducto::whereIn('id', $tipoProductoIds)->get();

        // Devolver los TipoProducto en formato JSON
        return response()->json($tiposProducto);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'letras_identificacion' => 'required|string|max:10',
        ]);

        $tipoProducto = TipoProducto::create($request->all());

        return response()->json($tipoProducto, 201);
    }

    public function getByLetras($letras){
        // Buscar el tipo de producto cuya ruta contiene la ruta pasada como parámetro
        $tipoProducto = TipoProducto::where('letras_identificacion', $letras)->first();

        // Si tiene subproductos (hay algun tipo_producto con su id en padre_id), devolverlos
        $subproductos = TipoProducto::where('padre_id', $tipoProducto->id)->get();
        if ($subproductos->count() > 0) {
            $tipoProducto['subproductos'] = self::getSubproductosPorPadreId($tipoProducto->id, $subproductos);
        }


        if (!$tipoProducto) {
            return response()->json(['message' => 'No se encontraron resultados'], 404);
        }

        return response()->json($tipoProducto);

    }

    public function show($id)
    {
        $tipoProducto = TipoProducto::findOrFail($id);
        return response()->json($tipoProducto);
    }

    public function getLogosPorTipoProducto($id) 
    {
        $camposLogos = DB::table('campos_logos')
            ->where('tipo_producto_id', $id)
            ->get()
            ->map(function ($campo) {
                return [
                    'id' => (string) $campo->id,
                    'tipo_logo' => $campo->tipo_logo,
                    'entidad_id' => (string) $campo->entidad_id,
                    'fila' => $campo->fila,
                    'columna' => $campo->columna,
                    'page' => $campo->page,
                    'altura' => $campo->altura,
                    'ancho' => $campo->ancho,
                ];
            });

        return response()->json($camposLogos);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'string|max:255',
            'letras_identificacion' => 'string|max:10',
        ]);

        $tipoProducto = TipoProducto::findOrFail($id);
        $tipoProducto->update($request->all());

        return response()->json($tipoProducto);
    }

    public function updateTipoProducto(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'campos_logos' => 'nullable|array',
            'acuerdo_kyrema' => 'nullable|integer',
            'nombre_unificado' => 'nullable|integer',
        ]);

        Log::info($request->all());

        // Editar todo menos los campos_logos:
        $tipoProducto = TipoProducto::findOrFail($id);
        $tipoProducto->update($request->except('campos_logos'));
        $campos_logos = $request->campos_logos;

        // Eliminar todos los campos_logos que haya conectado con el tipo_producto
        DB::table('campos_logos')->where('tipo_producto_id', $id)->delete();

        // Insertar los nuevos campos_logos
        foreach ($campos_logos as $campo_logo) {
            DB::table('campos_logos')->insert([
                'tipo_logo' => $campo_logo['tipo_logo'],
                'entidad_id' => $campo_logo['entidad_id'],
                'tipo_producto_id' => $id,
                'columna' => $campo_logo['columna'] ?? null,
                'fila' => $campo_logo['fila'] ?? null,
                'page' => $campo_logo['page'] ?? null,
                'altura' => $campo_logo['altura'] ?? null,
                'ancho' => $campo_logo['ancho'] ?? null,
            ]);
        }

        return response()->json($tipoProducto);
    }

    public function destroy($id)
    {
        $tipoProducto = TipoProducto::findOrFail($id);
        $tipoProducto->delete();

        return response()->json(null, 204);
    }

    public function deleteTipoProducto($productId){
 
        try{

            // Obtener letrasIdentificacion y plantilla_path antes de eliminar la tabla tipo_producto
            $product = DB::table('tipo_producto')->where('id', $productId)->first();
            $letrasIdentificacion = $product->letras_identificacion ?? null;
            $plantillasPaths = [
                $product->plantilla_path_1 ?? null, $product->plantilla_path_2 ?? null, $product->plantilla_path_3 ?? null, $product->plantilla_path_4 ?? null,
                $product->plantilla_path_5 ?? null, $product->plantilla_path_6 ?? null, $product->plantilla_path_7 ?? null, $product->plantilla_path_8 ?? null,
            ];

            // Obtener los campos con opciones (opciones != null)
            $camposConOpciones = DB::table('campos')->where('tipo_producto_id', $productId)->whereNotNull('opciones')->get();


            if($camposConOpciones != null && count($camposConOpciones) > 0){
                // Recorrer los campos con opciones y elminiar las tablas con el nombre de las opciones
                foreach ($camposConOpciones as $campo) {
                    if (Schema::hasTable($campo->opciones)) {
                        Schema::dropIfExists($campo->opciones);
                    }
                }
            }
            //Comprobar si tiene tipos hijos:
            $tiposHijos = DB::table('tipo_producto')->where('padre_id', $productId)->get();

            if($tiposHijos != null && count($tiposHijos) > 0){
                foreach ($tiposHijos as $tipoHijo) {
                    $this->call('delete:product-data', ['productId' => $tipoHijo->id]);
                }
            }

            // Delete from tipo_producto
            DB::table('tipo_producto')->where('id', $productId)->delete();

            // Delete from tipo_producto_sociedad
            DB::table('tipo_producto_sociedad')->where('id_tipo_producto', $productId)->delete();

            // Delete from tarifas_producto
            DB::table('tarifas_producto')->where('tipo_producto_id', $productId)->delete();

            // Delete from campos_logos
            DB::table('campos_logos')->where('tipo_producto_id', $productId)->delete();

            // Delete from tipo_producto_polizas
            DB::table('tipo_producto_polizas')->where('tipo_producto_id', $productId)->delete();

            // Drop the table if it exists
            if ($letrasIdentificacion && Schema::hasTable($letrasIdentificacion)) {
                Schema::dropIfExists($letrasIdentificacion);
            }

            // Delete from campos
            DB::table('campos')->where('tipo_producto_id', $productId)->delete();


            foreach ($plantillasPaths as $plantillaPath) {
                // Eliminar la plantilla si existe
                if ($plantillaPath && Storage::disk('public')->exists($plantillaPath)) {
                    Storage::disk('public')->delete($plantillaPath);
                }
            }

            

            return response()->json("Data for product ID $productId has been deleted.");

        } catch (\Exception $e) {
            return response()->json($e->getMessage());
        }
 
    }

    public function getSubproductosPorPadreId($id, $subproductos = null)
    {
        if ($subproductos == null) {
            // Obtener los subproductos con tarifas y campos utilizando las relaciones
            $subproductos = TipoProducto::where('padre_id', $id)
                ->with(['tarifas', 'campos'])
                ->get();
        }

        // Construir la respuesta incluyendo los campos y tarifas de cada subproducto
        $subproductosConDetalles = $subproductos->map(function ($subproducto) {
            return [
                'id' => $subproducto->id,
                'nombre' => $subproducto->nombre,
                'letras_identificacion' => $subproducto->letras_identificacion,
                'plantilla_path_1' => $subproducto->plantilla_path_1,
                'plantilla_path_2' => $subproducto->plantilla_path_2,
                'plantilla_path_3' => $subproducto->plantilla_path_3,
                'plantilla_path_4' => $subproducto->plantilla_path_4,
                'plantilla_path_5' => $subproducto->plantilla_path_5,
                'plantilla_path_6' => $subproducto->plantilla_path_6,
                'plantilla_path_7' => $subproducto->plantilla_path_7,
                'plantilla_path_8' => $subproducto->plantilla_path_8,
                'padre_id' => $subproducto->padre_id,
                // Utilizando relaciones para obtener tarifas y campos
                'tarifas' => $subproducto->tarifas,
                'campos' => $subproducto->campos,
                'created_at' => $subproducto->created_at,
                'updated_at' => $subproducto->updated_at,
                'tipo_duracion' => $subproducto->tipo_duracion,
                'duracion' => $subproducto->duracion,
            ];
        });

        return $subproductosConDetalles;
    }

}
