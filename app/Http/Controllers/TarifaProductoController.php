<?php

namespace App\Http\Controllers;

use App\Models\TarifasProducto;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TarifaProductoController extends Controller
{
    const SOCIEDAD_ADMIN_ID = '1';
    
    public function getTarifaPorSociedad($id_sociedad)
    {
        // Primero, intenta obtener las tarifas asociadas con el id_sociedad proporcionado
        $tarifas = TarifasProducto::where('id_sociedad', $id_sociedad)->get();

        // Si no hay tarifas para el id_sociedad, obtiene las tarifas con sociedad_id SOCIEDAD_ADMIN_ID 
        if ($tarifas->isEmpty()) {
            $tarifas = TarifasProducto::where('id_sociedad', self::SOCIEDAD_ADMIN_ID)->get();
        }

        // Devuelve las tarifas en formato JSON
        return response()->json($tarifas);
    }

    public function getTarifaPorProducto($id_producto)
    {
        // Intenta obtener las tarifas asociadas con el id_producto proporcionado
        $tarifas = TarifasProducto::where('id_producto', $id_producto)->get();

        // Si no hay tarifas para el id_producto, obtiene las tarifas con producto_id nulo
        if ($tarifas->isEmpty()) {
            $tarifas = TarifasProducto::where('id_producto', null)->get();
        }

        // Devuelve las tarifas en formato JSON
        return response()->json($tarifas);
    }

    public function getTarifaPorSociedadAndTipoProducto($id_sociedad, Request $request){
        // Primero obtenemos de la request el id del tipo de producto
        $id_tipo_producto = $request->input('tipo_producto_id');

        // Intenta obtener las tarifas asociadas con el id_sociedad y el id_tipo_producto proporcionados
        $tarifas = TarifasProducto::where('id_sociedad', $id_sociedad)->where('tipo_producto_id', $id_tipo_producto)->get();

        // Si no hay tarifas para el id_sociedad y el id_tipo_producto, obtiene las tarifas con sociedad_id SOCIEDAD_ADMIN_ID y el tipo_producto_id proporcionado
        if ($tarifas->isEmpty()) {
            $tarifas = TarifasProducto::where('id_sociedad', self::SOCIEDAD_ADMIN_ID)->where('tipo_producto_id', $id_tipo_producto)->get();
        }

        // Devuelve las tarifas en formato JSON
        return response()->json($tarifas);
    }


    public function updateTarifaPorSociedad($sociedad_id, Request $request)
    {

        $tarifa = $request->input('tarifa');
        // Coger id y meterlo en $id_tipo_producto
        $id_tipo_producto = $tarifa['id'];
        // Coger los precios
        $precio_base = $tarifa['precio_base'];
        $extra_1 = $tarifa['extra_1'];
        $extra_2 = $tarifa['extra_2'];
        $extra_3 = $tarifa['extra_3'];
        $precio_total = $tarifa['precio_total'];

        // Actualizar los datos:
        TarifasProducto::where('id_sociedad', $sociedad_id)->where('tipo_producto_id', $id_tipo_producto)->update([
            'precio_base' => $precio_base,
            'extra_1' => $extra_1,
            'extra_2' => $extra_2,
            'extra_3' => $extra_3,
            'precio_total' => $precio_total
        ]);

        // Devuelve un mensaje de éxito
        return response()->json(['message' => 'Tarifa actualizada con éxito'], 200);
    }

    public function createTarifaPorSociedad($sociedad_id, Request $request)
    {
        $tarifa = $request->input('tarifa');
        // Coger id y meterlo en $id_tipo_producto
        $id_tipo_producto = $tarifa['id'];
        // Coger los precios
        $precio_base = $tarifa['precio_base'];
        $extra_1 = $tarifa['extra_1'];
        $extra_2 = $tarifa['extra_2'];
        $extra_3 = $tarifa['extra_3'];
        $precio_total = $tarifa['precio_total'];

        // Meter los datos en la tabla
        TarifasProducto::create([
            'id_sociedad' => $sociedad_id,
            'tipo_producto_id' => $id_tipo_producto,
            'precio_base' => $precio_base,
            'extra_1' => $extra_1,
            'extra_2' => $extra_2,
            'extra_3' => $extra_3,
            'precio_total' => $precio_total
        ]);

        // Devuelve un mensaje de éxito
        return response()->json(['message' => 'Tarifa creada con éxito'], 201);

    }

    public function index()
    {
        $tarifaProductos = TarifaProducto::all();
        return response()->json($tarifaProductos);
    }

    public function store(Request $request)
    {
        // Validar los datos recibidos
        $request->validate([
            'tipo_producto_id' => 'required|numeric',
            'id_sociedad' => 'required|numeric',
            'precio_base' => 'required|numeric',
            'extra_1' => 'required|numeric',
            'extra_2' => 'required|numeric',
            'extra_3' => 'required|numeric',
            'precio_total' => 'required|numeric',
        ]);

        // Crear el nuevo registro en la base de datos con el ID generado
        $tarifaProducto = TarifasProducto::create([
            'tipo_producto_id' => $request->input('tipo_producto_id'),
            'id_sociedad' => $request->input('id_sociedad'),
            'precio_base' => $request->input('precio_base'),
            'extra_1' => $request->input('extra_1'),
            'extra_2' => $request->input('extra_2'),
            'extra_3' => $request->input('extra_3'),
            'precio_total' => $request->input('precio_total'),
        ]);

        return response()->json($tarifaProducto, 201);
    }

    public function show($id)
    {
        $tarifaProducto = TarifaProducto::findOrFail($id);
        return response()->json($tarifaProducto);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'tipo_producto_id' => 'numeric|exists:tipo_producto,id',
            'id_sociedad' => 'numeric|exists:sociedad,id',
            'precio_base' => 'numeric',
            'extra_1' => 'numeric',
            'extra_2' => 'numeric',
            'extra_3' => 'numeric',
            'precio_total' => 'numeric',
        ]);

        $tarifaProducto = TarifaProducto::findOrFail($id);
        $tarifaProducto->update($request->all());

        return response()->json($tarifaProducto);
    }

    public function destroy($id)
    {
        $tarifaProducto = TarifaProducto::findOrFail($id);
        $tarifaProducto->delete();

        return response()->json(null, 204);
    }
}
