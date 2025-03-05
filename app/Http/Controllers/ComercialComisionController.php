<?php

namespace App\Http\Controllers;

use App\Models\ComercialComision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComercialComisionController extends Controller
{
    public function index($id)
    {
        $comisiones = ComercialComision::where('id_comercial', $id)
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
            ComercialComision::upsert($comisionesToUpsert->toArray(), ['id'], ['fixedFee', 'percentageFee']);

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
