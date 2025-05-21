<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TipoPago;

class TipoPagoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $tiposPago = TipoPago::all();
        return response()->json($tiposPago);
    }

    public function storeOrUpdate(Request $request, $id = null)
    {
        // Validar los datos
        $request->validate([
            'type' => 'required|string',
            'paymentTypes' => 'required|array',
            'paymentTypes.*.id' => 'required|integer',
            'paymentTypes.*.name' => 'required|string',
            'insurances' => 'required|array',
            'insurances.*.id' => 'required|integer',
            'insurances.*.nombre' => 'required|string'
        ]);

        // Si se proporciona un ID, actualizar el registro
        if ($id) {
            $tipoPago = TipoPago::findOrFail($id);
        } else {
            // De lo contrario, crear uno nuevo
            $tipoPago = new TipoPago();
        }

        // Llenar el modelo con los datos
        $tipoPago->type = $request->input('type');
        $tipoPago->paymentTypes = $request->input('paymentTypes');
        $tipoPago->insurances = $request->input('insurances');

        // Guardar el registro en la base de datos
        $tipoPago->save();

        return response()->json([
            'success' => true,
            'message' => $id ? 'Tipo de pago actualizado correctamente.' : 'Tipo de pago creado correctamente.',
            'data' => $tipoPago
        ]);
    }

}
