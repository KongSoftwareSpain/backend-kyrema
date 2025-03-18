<?php

namespace App\Http\Controllers;

use App\Models\ComisionSociedad;
use App\Models\Sociedad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        if ($sociedad->sociedad_padre_id === null || $sociedad->sociedad_padre_id === env('SOCIEDAD_ADMIN_ID')) {
            
            $productos = TarifaProductoController::getTarifasPorSociedadAndProductos($sociedadId, $productoIds);

            $resultado = $productos->map(function ($producto) {
                return [
                    'id' => $producto['tipo_producto_id'], 
                    'totalPrice' => $producto['precio_total']
                ];
            }, $productos);

            return response()->json($resultado);
        }

        Log::info($sociedad);
        // Buscar la sociedad de segundo nivel asociada
        $sociedadSegundoNivel = $this->buscarSociedadSegundoNivel($sociedad);


        // Si no hay sociedad de segundo nivel, devolvemos 0
        if (!$sociedadSegundoNivel) {
            return response()->json(['error' => 'No se encontró sociedad de segundo nivel'], 404);
        }

        // Obtener el precio base de los productos
        $productos = TarifaProductoController::getTarifasPorSociedadAndProductos($sociedadSegundoNivel->id, $productoIds);
        Log::info($productos);

        foreach ($productos as &$producto) {
            $precioBase = $producto['precio_total'];
            
            $comisionSegundoNivel = ComisionSociedad::where('id_sociedad', $sociedad->sociedad_padre_id)
                ->where('tipo_producto_id', $producto['tipo_producto_id'])
                ->first();

            $comisionSegundoNivel = $this->calularComision($precioBase, $comisionSegundoNivel);

            if ($sociedad->sociedad_padre_id == $sociedadSegundoNivel->id) {
                // Obtener la comisión de la sociedad padre inmediata
                
                // Caso 1: la sociedad padre ya es de segundo nivel:
                // Se calcula el porcentaje directamente desde la tarifa (precio_base)
                $comisionSociedad = $this->calcularComision($precioBase, $comisionSegundoNivel);
            } else {
                // Caso 2: la sociedad padre no es de segundo nivel, se debe seguir subiendo en la cadena
                $comisionSociedad = $this->getRestOfCommissions($sociedad->sociedad_padre_id, $producto['tipo_producto_id'], $comisionSegundoNivel);
            }
    
            Log::info($comisionSegundoNivel);
            Log::info($comisionSociedad);
    
            // Asignamos la comisión calculada al producto (redondeada a dos decimales)
            $producto['comision_calculada'] = round($comisionSociedad, 2);
        }

        $resultado = $productos->map(function ($producto) {
            return [
                'id' => $producto['tipo_producto_id'], 
                'totalPrice' => $producto['comision_calculada']
            ];
        }, $productos);

        // Devolver la respuesta en formato JSON
        return response()->json($resultado);
    }


    private function getRestOfCommissions($sociedadPadreId, $tipoProductoId, $comisionSegundoNivel)
    {
        $currentSociedad = Sociedad::find($sociedadPadreId);

        // Mientras exista una sociedad padre y no se haya alcanzado la sociedad de admin
        while ($currentSociedad->sociedad_padre_id !== null && $currentSociedad->sociedad_padre_id !== env('SOCIEDAD_ADMIN_ID')) {
            $comision = ComisionSociedad::where('id_sociedad', $currentSociedad->sociedad_padre_id)
                        ->where('tipo_producto_id', $tipoProductoId)
                        ->first();
            if ($comision) {
                $comisionSegundoNivel = $comisionSegundoNivel - $this->calcularComision($comisionSegundoNivel, $comision);
            }
            // Se sube un nivel en la jerarquía
            $currentSociedad = Sociedad::find($currentSociedad->sociedad_padre_id);
        }
        return $comisionSegundoNivel; // Puede ser null si no se encontró comisión
    }

    /**
     * Busca la sociedad de segundo nivel (la primera por encima que NO sea ADMIN)
     */
    private function buscarSociedadSegundoNivel($sociedad)
    {
        while ($sociedad->sociedad_padre_id !== null && $sociedad->sociedad_padre_id !== env('SOCIEDAD_ADMIN_ID')) {
            $sociedad = Sociedad::find($sociedad->sociedad_padre_id);
        }
        return $sociedad;
    }

    /**
     * Calcula la comisión aplicada sobre un monto base
     */
    private function calcularComision($montoBase, $comision)
    {
        if (!$comision) return 0;

        if ($comision->tipo === env('COMISION_TIPO_FIJO')) {
            return $comision->valor;
        } elseif ($comision->tipo === env('COMISION_TIPO_PORCENTUAL')) {
            return round($montoBase * ($comision->valor / 100), 2);
        }

        return 0;
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

    public function store(Request $request, $id)
    {
        // Validación de la estructura del array de comisiones
        $validatedData = $request->validate([
            'comisiones' => 'required|array',
            'comisiones.*.id' => 'required',  
            'comisiones.*.fixedFee' => 'nullable|numeric',
            'comisiones.*.percentageFee' => 'nullable|numeric',
        ]);

        // Filtrar comisiones que tengan ambos valores en 0 y asegurarse de que solo uno tenga valor distinto de 0
        $comisionesToUpsert = collect($validatedData['comisiones'])
            ->filter(function ($comisionData) {
                return ($comisionData['fixedFee'] != 0 || $comisionData['percentageFee'] != 0);
            })
            ->map(function ($comisionData) use ($id) {
                // Determinar el tipo de comisión
                if ($comisionData['fixedFee'] != 0) {
                    $tipo = 'fijo';
                    $valor = $comisionData['fixedFee'];
                } else {
                    $tipo = 'porcentual';
                    $valor = $comisionData['percentageFee'];
                }

                return [
                    'tipo_producto_id' => $comisionData['id'],
                    'valor' => $valor,
                    'tipo' => $tipo, // Nuevo campo con el tipo de comisión
                    'id_sociedad' => $id,
                ];
            });

        // Verificar si hay comisiones a insertar antes de continuar
        if ($comisionesToUpsert->isEmpty()) {
            return response()->json(['message' => 'No hay comisiones válidas para actualizar'], 200);
        }

        // Abrimos una transacción para agrupar las actualizaciones
        DB::beginTransaction();
        try {
            ComisionSociedad::upsert($comisionesToUpsert->toArray(), ['tipo_producto_id', 'id_sociedad'], 
            ['valor', 'tipo']);

            // Commit de la transacción si todo fue correcto
            DB::commit();

            return response()->json(['message' => 'Comisiones actualizadas correctamente'], 200);
        } catch (\Exception $e) {
            // Si algo falla, hacemos un rollback
            DB::rollBack();
            return response()->json(['error' => 'Hubo un error al actualizar las comisiones', 'msg' => $e], 500);
        }
    }

}
