<?php

namespace App\Support;

class Dni
{
    /**
     * Normaliza un DNI/NIE para comparación:
     * - Trim y mayúsculas
     * - Elimina espacios y separadores
     */
    public static function normalize(?string $dni): ?string
    {
        if ($dni === null) return null;

        $dni = strtoupper(trim($dni));
        // quita todo lo que no sea A-Z o 0-9 (espacios, guiones, puntos…)
        $dni = preg_replace('/[^A-Z0-9]/', '', $dni);

        return $dni;
    }

    public static function equals(?string $a, ?string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }
}
