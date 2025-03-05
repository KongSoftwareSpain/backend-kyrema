<?php

namespace App\Http\Controllers;

use App\Models\ComisionSociedad;
use App\Models\Sociedad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SociedadComisionController extends Controller {
    
    public function getTotalPrice($sociedadId, Request $request)
    {
        $productoIds = $request->input('productoIds'); 

        // Obtener la sociedad actual
        $sociedad = Sociedad::find($sociedadId);

        if (!$sociedad) {
            return response()->json(['error' => 'Sociedad no encontrada'], 404);
        }

        // Si es de primer nivel, devolvemos directamente el precio del producto
        if ($sociedad->padre_id === null || $sociedad->padre_id === env('SOCIEDAD_ADMIN_ID')) {
            $productos = TarifaProductoController::getTarifasPorSociedadAndProductos($sociedadId, $productoIds);

            $resultado = array_map(function ($producto) {
                return [
                    'id' => $producto['tipo_producto_id'], 
                    'totalPrice' => $producto['totalPrice']
                ];
            }, $productos);

            return response()->json($resultado);
        }

        // Buscar la sociedad de segundo nivel asociada
        $sociedadSegundoNivel = $this->buscarSociedadSegundoNivel($sociedad);

        // Si no hay sociedad de segundo nivel, devolvemos 0
        if (!$sociedadSegundoNivel) {
            return response()->json(['error' => 'No se encontró sociedad de segundo nivel'], 404);
        }

        // Obtener el precio base de los productos
        $productos = TarifaProductoController::getTarifasPorSociedadAndProductos($sociedadSegundoNivel->id, $productoIds);

        foreach ($productos as &$producto) {
            $precioBase = $producto['totalPrice'];
            
            // Obtener comisiones de la sociedad de segundo nivel
            $comisionSegundoNivel = ComisionSociedad::where('id_sociedad', $sociedadSegundoNivel->id)->first();
            $comisionSociedad = $this->calcularComision($precioBase, $comisionSegundoNivel);

            // Restar comisiones de las sociedades de niveles superiores
            $montoDisponible = $this->restarComisionesSuperiores($sociedadId, $comisionSociedad);

            // Asignamos el monto disponible como nuevo totalPrice
            $producto['totalPrice'] = round($montoDisponible, 2);
        }

        $resultado = array_map(function ($producto) {
            return [
                'id' => $producto['tipo_producto_id'], 
                'totalPrice' => $producto['totalPrice']
            ];
        }, $productos);

        // Devolver la respuesta en formato JSON
        return response()->json($resultado);
    }

    /**
     * Busca la sociedad de segundo nivel (la primera por encima que NO sea ADMIN)
     */
    private function buscarSociedadSegundoNivel($sociedad)
    {
        while ($sociedad->padre_id !== null && $sociedad->padre_id !== env('SOCIEDAD_ADMIN_ID')) {
            $sociedad = Sociedad::find($sociedad->padre_id);
        }
        return $sociedad;
    }

    /**
     * Calcula la comisión aplicada sobre un monto base
     */
    private function calcularComision($montoBase, $comision)
    {
        if (!$comision) return 0;

        if ($comision->tipo === 'fija') {
            return $comision->monto;
        } elseif ($comision->tipo === 'porcentual') {
            return round($montoBase * ($comision->monto / 100), 2);
        }

        return 0;
    }

    /**
     * Resta todas las comisiones de las sociedades superiores hasta llegar a segundo nivel
     */
    private function restarComisionesSuperiores($sociedadId, $comisionBase)
    {
        $sociedad = Sociedad::find($sociedadId);
        $comisionTotal = $comisionBase;

        while ($sociedad->padre_id !== null && $sociedad->padre_id !== env('SOCIEDAD_ADMIN_ID')) {
            $comisionPadre = ComisionSociedad::where('id_sociedad', $sociedad->padre_id)->first();
            
            if ($comisionPadre) {
                $comisionAplicada = $this->calcularComision($comisionTotal, $comisionPadre);
                $comisionTotal -= $comisionAplicada; // Restar la comisión aplicada
            }

            $sociedad = Sociedad::find($sociedad->padre_id);
        }

        return max($comisionTotal, 0); // Asegurar que no sea negativo
    }

    public function index($id) {
        $comisiones = ComisionSociedad::where('id_sociedad', $id)
            ->get();
    
        // Mapear las comisiones para adaptarlas al formato necesario
        $comisiones = $comisiones->map(function($comision) {
            // Dependiendo del tipo, ajustamos los valores a enviar
            $comisionData = [
                'id_tipo_producto' => $comision->tipo_producto_id,
                'fixedFee' => ($comision->tipo == 'fijo') ? $comision->valor : null,
                'percentageFee' => ($comision->tipo == 'porcentual') ? $comision->valor : null,
            ];
    
            return $comisionData;
        });
    
        // Devolver la respuesta con las comisiones formateadas
        return response()->json($comisiones);
    }

    public function store(Request $request) {
        // Validación de la estructura del array de comisiones
        $validatedData = $request->validate([
            'comisiones' => 'required|array',
            'comisiones.*.id' => 'required|exists:comision_sociedades,id',  // El id debe existir en la tabla comision_sociedades
            'comisiones.*.fixedFee' => 'nullable|numeric',
            'comisiones.*.percentageFee' => 'nullable|numeric',
        ]);

        // Recoger todos los datos formateados
        $comisionesToUpsert = collect($validatedData['comisiones'])->map(function ($comisionData) {
            return [
                'id' => $comisionData['id'],
                'fixedFee' => $comisionData['fixedFee'] ?? 0,
                'percentageFee' => $comisionData['percentageFee'] ?? 0,
            ];
        });

        // Abrimos una transacción para agrupar las actualizaciones
        DB::beginTransaction();
        try {
            ComisionSociedad::upsert($comisionesToUpsert->toArray(), ['id'], ['fixedFee', 'percentageFee']);

            // Commit de la transacción si todo fue correcto
            DB::commit();

            return response()->json(['message' => 'Comisiones actualizadas correctamente'], 200);
        } catch (\Exception $e) {
            // Si algo falla, hacemos un rollback
            DB::rollBack();
            return response()->json(['error' => 'Hubo un error al actualizar las comisiones'], 500);
        }
    }
}
