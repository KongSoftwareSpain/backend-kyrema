<?php

namespace App\Services\Remesas;

use Illuminate\Support\Collection;

interface GenerarRemesaInterface
{
    /**
     * @param Collection $giros            Giros a incluir, con su relación 'pago' cargada.
     * @param array      $empresa          Datos del acreedor: nombre, iban, bic, identificador_sepa.
     * @param string     $referencia       Identificador de la remesa (MsgId).
     * @param string     $fechaCobro       Fecha de cargo; se normaliza a YYYY-MM-DD.
     * @param string     $tipoPorDefecto   SeqTp a usar en los giros sin tipo_adeudo.
     */
    public function generar(
        Collection $giros,
        array $empresa,
        string $referencia,
        string $fechaCobro,
        string $tipoPorDefecto = 'FRST'
    ): string;
}
