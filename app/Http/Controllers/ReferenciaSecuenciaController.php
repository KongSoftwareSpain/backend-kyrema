<?php

namespace App\Http\Controllers;


use App\Services\ReferenceService;

class ReferenciaSecuenciaController extends Controller
{
    /**
     * Obtener siguiente referencia para un prefijo dado.
     */
    public function generateReference($letras)
    {
        $referenciaService = new ReferenceService();
        $numeroFormateado = $referenciaService->generarReferencia($letras);

        return response()->json($numeroFormateado, 200);
    }
}

