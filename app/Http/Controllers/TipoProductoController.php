<?php

namespace App\Http\Controllers;

use App\Models\TipoProducto;
use Illuminate\Http\Request;
use App\Models\TipoProductoSociedad;
use Illuminate\Support\Facades\DB;

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
            $tipoProducto['subproductos'] = self::getSubproductosPorPadreId($tipoProducto->id);
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

    public function updateNombre(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
        ]);

        $tipoProducto = TipoProducto::findOrFail($id);
        $tipoProducto->update($request->all());

        return response()->json($tipoProducto);
    }

    public function destroy($id)
    {
        $tipoProducto = TipoProducto::findOrFail($id);
        $tipoProducto->delete();

        return response()->json(null, 204);
    }

    public function deleteTipoProducto($productId){
        //  // Obtener letrasIdentificacion y plantilla_path antes de eliminar la tabla tipo_producto
        //  $product = DB::table('tipo_producto')->where('id', $productId)->first();
        //  $letrasIdentificacion = $product->letras_identificacion ?? null;
        //  $plantillaPath = $product->plantilla_path ?? null;
 
        //  // Delete from tipo_producto
        //  DB::table('tipo_producto')->where('id', $productId)->delete();
 
        // Delete from tipo_producto_sociedad
        DB::table('tipo_producto_sociedad')->where('id_tipo_producto', $productId)->delete();
 
        //  // Delete from tarifas_producto
        //  DB::table('tarifas_producto')->where('tipo_producto_id', $productId)->delete();
 
        // // Drop the table if it exists
        //  if ($letrasIdentificacion && Schema::hasTable($letrasIdentificacion)) {
        //      Schema::dropIfExists($letrasIdentificacion);
        //  }
 
        //  // Delete from campos
        //  DB::table('campos')->where('tipo_producto_id', $productId)->delete();
 
        //  // Eliminar la plantilla si existe
        //  if ($plantillaPath && Storage::disk('public')->exists($plantillaPath)) {
        //      Storage::disk('public')->delete($plantillaPath);
        //  }
 
    }

    public function getSubproductosPorPadreId($id)
    {
        $subproductos = TipoProducto::where('padre_id', $id)->get();


        // Construir la respuesta incluyendo los campos y tarifas de cada subproducto
        $subproductosConDetalles = $subproductos->map(function ($subproducto) {
            // Obtener las tarifas asociadas al subproducto
            $tarifas = DB::table('tarifas_producto')->where('tipo_producto_id', $subproducto->id)->get();

            // Obtener los campos asociados al subproducto
            $campos = DB::table('campos')->where('tipo_producto_id', $subproducto->id)->get();

            // Retornar la estructura de datos con los detalles completos del subproducto
            return [
                'id' => $subproducto->id,
                'nombre' => $subproducto->nombre,
                'letras_identificacion' => $subproducto->letras_identificacion,
                'plantilla_path' => $subproducto->plantilla_path,
                'padre_id' => $subproducto->padre_id,
                'tarifas' => $tarifas,
                'campos' => $campos,
                'created_at' => $subproducto->created_at,
                'updated_at' => $subproducto->updated_at,
            ];
        });

        

        return $subproductosConDetalles;
    }
}
