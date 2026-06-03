<?php

$codigos = ['ARAC38144-2026', 'ARAC37462-2026'];

// Buscar en todas las tablas de origen posibles que tengan numero_certificado o codigo_producto
$tablasOrigen = [
    'seguro_kyrema'         => ['pk' => 'id_seguro_kyrema',     'cert' => 'numero_certificado',  'dni' => 'dni_tomador'],
    'seguro_combinados_k'   => ['pk' => 'id',                   'cert' => 'numero_certificado',  'dni' => 'dni'],
];

foreach ($tablasOrigen as $tabla => $cols) {
    try {
        $rows = \Illuminate\Support\Facades\DB::connection('mysql')
            ->table($tabla)
            ->whereIn($cols['cert'], $codigos)
            ->get();

        foreach ($rows as $r) {
            $dni = $r->{$cols['dni']} ?? 'SIN DNI';
            $cert = $r->{$cols['cert']};
            $pk   = $r->{$cols['pk']};
            echo "[MySQL:{$tabla}] pk={$pk} | cert={$cert} | dni={$dni}\n";
            echo "  Datos completos:\n";
            foreach ((array) $r as $k => $v) {
                if (in_array($k, ['dni', 'dni_tomador', 'nombre_tomador', 'nombre_socio', 'email', $cols['cert'], $cols['pk']])) {
                    echo "    {$k} = {$v}\n";
                }
            }
            echo "\n";
        }
    } catch (\Exception $e) {
        echo "[MySQL:{$tabla}] ERROR: {$e->getMessage()}\n";
    }
}
