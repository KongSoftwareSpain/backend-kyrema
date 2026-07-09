<?php

namespace App\Exports\Concerns;

trait FormateaCamposPago
{
    /**
     * Corrige las referencias antiguas que quedaron con las siglas duplicadas
     * (p.ej. "062026SJKSJK0000005" -> "062026SJK0000005"). Si la referencia no
     * tiene siglas repetidas se devuelve tal cual.
     */
    protected function limpiarReferencia(?string $referencia): ?string
    {
        if (!$referencia) {
            return $referencia;
        }

        // <prefijo fecha 6 dígitos><siglas><siglas repetidas><número>
        // Las siglas pueden ser alfanuméricas (p.ej. "K1"), pero siempre empiezan
        // por letra, por eso el grupo se ancla con [A-Za-z] para no confundirlas
        // con los dígitos del prefijo de fecha ni del número de secuencia.
        return preg_replace('/^(\d{6})([A-Za-z][A-Za-z0-9]*?)\2(\d+)$/', '$1$2$3', $referencia);
    }

    /**
     * Convierte el tipo de pago almacenado (p.ej. "giro_bancario") en un texto
     * legible ("Giro bancario").
     */
    protected function formatearTipoPago(?string $tipoPago): string
    {
        if (!$tipoPago) {
            return 'N/A';
        }

        return ucfirst(str_replace('_', ' ', $tipoPago));
    }

    /**
     * Los conceptos antiguos se guardaron con fechas 'YYYY-MM-DD' incrustadas
     * en el texto; se convierten a DD/MM/YYYY al exportar.
     */
    protected function formatearFechasConcepto(?string $concepto): ?string
    {
        if (!$concepto) {
            return $concepto;
        }

        return preg_replace('/\b(\d{4})-(\d{2})-(\d{2})\b/', '$3/$2/$1', $concepto);
    }
}
