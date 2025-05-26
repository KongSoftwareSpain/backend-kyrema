<?php

namespace App\Services;

use App\Models\ReferenciaSecuencia;
use Illuminate\Support\Facades\Config;

class ReferenceService
{
    public function generarReferencia(string $letras): string
    {
        $letras = strtolower($letras);

        // Obtener o crear la secuencia
        $secuencia = ReferenciaSecuencia::firstOrCreate(
            ['letras_identificacion' => $letras],
            ['ultimo_producto' => 0]
        );

        // Obtener prefijo de configuración y quitarlo si existe
        $prefijo = strtolower(Config::get('app.prefijo_tipo_producto', ''));
        $letrasSinPrefijo = str_replace($prefijo, '', $letras);

        // Calcular número máximo y actualizar contador
        $maximo = pow(10, 10 - strlen($letrasSinPrefijo));
        $secuencia->ultimo_producto = ($secuencia->ultimo_producto + 1) % $maximo;
        $secuencia->save();

        // Generar parte numérica rellenada con ceros
        $numeroFormateado = str_pad(
            (string) $secuencia->ultimo_producto,
            10 - strlen($letrasSinPrefijo),
            '0',
            STR_PAD_LEFT
        );

        return strtoupper($letrasSinPrefijo . $numeroFormateado);
    }
}
