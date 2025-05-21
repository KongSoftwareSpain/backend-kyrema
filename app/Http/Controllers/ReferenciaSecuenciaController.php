<?php

namespace App\Http\Controllers;

use App\Models\ReferenciaSecuencia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

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

        // Obtén el prefijo desde la configuración
        $prefijo = strtolower(Config::get('app.prefijo_tipo_producto'));

        // Elimina el prefijo del código
        $letras = str_replace($prefijo, '', strtolower($letras));

        // Incrementar el contador
        $secuencia->ultimo_producto = ($secuencia->ultimo_producto + 1) % pow(10, 10 - strlen($letras));
        $secuencia->save();

        // Calcular longitud que debe ocupar el número
        $longitudNumero = 10 - strlen($letras);

        // Rellenar con ceros a la izquierda
        $numeroFormateado = str_pad((string) $secuencia->ultimo_producto, $longitudNumero, '0', STR_PAD_LEFT);

        return response()->json($numeroFormateado, 200);
    }
}

