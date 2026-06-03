<?php

$codigos = ['ARAC38144-2026', 'ARAC37462-2026'];
$tablas  = ['producto_k', 'producto_c', 'producto_rehal', 'producto_sjk', 'producto_smk'];

foreach ($tablas as $tabla) {
    $rows = \Illuminate\Support\Facades\DB::connection('sqlsrv')
        ->table($tabla)
        ->whereIn('codigo_producto', $codigos)
        ->get();

    foreach ($rows as $r) {
        $dni      = $r->dni ?? 'SIN DNI';
        $socioId  = $r->socio_id ?? 'NULL';
        echo "[{$tabla}] id={$r->id} | codigo={$r->codigo_producto} | dni={$dni} | socio_id={$socioId}\n";

        // Buscar el socio por DNI en destino
        if ($dni !== 'SIN DNI') {
            $dniNorm = strtoupper(trim(str_replace([' ', '-'], '', $dni)));
            $socio = \Illuminate\Support\Facades\DB::connection('sqlsrv')
                ->table('socios')
                ->whereRaw("UPPER(REPLACE(REPLACE(dni, ' ', ''), '-', '')) = ?", [$dniNorm])
                ->first(['id', 'dni', 'nombre_socio']);

            if ($socio) {
                echo "  ✔ Socio en destino: id={$socio->id} | {$socio->nombre_socio} | dni={$socio->dni}\n";
            } else {
                echo "  ✘ Socio NO encontrado en SQL Server por DNI={$dniNorm}\n";

                // Buscar en origen MySQL
                $socioOrigen = \Illuminate\Support\Facades\DB::connection('mysql')
                    ->table('socios')
                    ->whereRaw("UPPER(REPLACE(REPLACE(dni, ' ', ''), '-', '')) = ?", [$dniNorm])
                    ->first(['id_socio', 'nombre_socio', 'dni', 'email']);

                if ($socioOrigen) {
                    echo "  → En MySQL origen: id={$socioOrigen->id_socio} | {$socioOrigen->nombre_socio} | email={$socioOrigen->email}\n";
                } else {
                    echo "  → TAMPOCO existe en MySQL origen. DNI huérfano.\n";
                }
            }
        }
        echo "\n";
    }
}
