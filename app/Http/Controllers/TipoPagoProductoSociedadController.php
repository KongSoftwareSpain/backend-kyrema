<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TipoPagoProductoSociedad;
use App\Models\TipoProductoSociedad;
use App\Models\TipoPago;
use Illuminate\Support\Facades\DB;

class TipoPagoProductoSociedadController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'sociedad_id' => 'required|exists:sociedad,id',
            'tipo_pago_producto_sociedades' => 'required|array',
            'tipo_pago_producto_sociedades.*.tipo_pago_id' => 'required|exists:tipos_pago,id',
            'tipo_pago_producto_sociedades.*.tipo_producto_id' => 'required|exists:tipo_producto,id',
        ]);

        // Primero eliminamos los registros existentes para la sociedad dada
        TipoPagoProductoSociedad::where('sociedad_id', $request->sociedad_id)->delete();

        // Insertar los nuevos registros
        $data = $request->tipo_pago_producto_sociedades;
        foreach ($data as $item) {
            DB::table('tipo_pago_producto_sociedad')->insert([
                'tipo_pago_id' => $item['tipo_pago_id'],
                'tipo_producto_id' => $item['tipo_producto_id'],
                'sociedad_id' => $request->sociedad_id,
            ]);
        }

        return response()->json(['message' => 'Datos guardados exitosamente'], 201);
    }

    public function getTiposPagoPorSociedad($id_sociedad)
    {
        $tiposPago = TipoPagoProductoSociedad::where('sociedad_id', $id_sociedad)->get();
        return response()->json($tiposPago);
    }

    public function transferirTiposPago($sociedad_padre_id, $sociedad_hija_id){

        // Coger todos los tipos de pago por producto de la sociedad padre:
        $tiposPagoProductoSociedad = TipoPagoProductoSociedad::where('sociedad_id', $sociedad_padre_id)->get();

        // Recorrer los tipos de pago por producto de la sociedad padre:
        foreach ($tiposPagoProductoSociedad as $tipoPagoProductoSociedad) {
            // Crear un nuevo registro en la tabla 'tipo_pago_producto_sociedad' con la sociedad hija:
            DB::table('tipo_pago_producto_sociedad')->insert([
                'tipo_pago_id' => $tipoPagoProductoSociedad->tipo_pago_id,
                'tipo_producto_id' => $tipoPagoProductoSociedad->tipo_producto_id,
                'sociedad_id' => $sociedad_hija_id,
            ]);
        }

        return response()->json(['message' => 'Tipos de pago transferidos exitosamente'], 201);
        
    }

    public function getTiposPagoPorSociedadYTipoProducto($sociedad_id, $tipo_producto_id)
    {
        $tiposPago = TipoPagoProductoSociedad::with('tipoPago') // carga la relación
            ->where('sociedad_id', $sociedad_id)
            ->where('tipo_producto_id', $tipo_producto_id)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->tipo_pago_id,
                    'nombre' => $item->tipoPago->nombre,
                    'codigo' => $item->tipoPago->codigo,
                ];
            });

        return response()->json($tiposPago);
    }


}
