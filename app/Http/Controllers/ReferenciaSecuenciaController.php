<?php

namespace App\Http\Controllers;

use App\Models\ReferenciaSecuencia;
use Illuminate\Http\Request;

class ReferenciaSecuenciaController extends Controller
{
    /**
     * Obtener siguiente referencia para un prefijo dado.
     */
    public function generateReference($letras)
    {
        $secuencia = ReferenciaSecuencia::firstOrCreate(
            ['letras_identificacion' => $letras],
            ['ultimo_producto' => 0]
        );

        // Incrementar
        $secuencia->ultimo_producto = ($secuencia->ultimo_producto + 1) % 1000000; // reinicia a 0 si llega a 999999
        $secuencia->save();

        // Formatear como string con ceros a la izquierda
        $referencia = str_pad((string) $secuencia->ultimo_producto, 6, '0', STR_PAD_LEFT);

        return response()->json($referencia);
    }
}
