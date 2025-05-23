<?php

namespace App\Services\Remesas;

use Illuminate\Support\Collection;

interface GenerarRemesaInterface
{
    public function generar(Collection $giros, array $empresa, string $referencia, string $fechaCobro, string $tipo = 'FRST'): string;
}
