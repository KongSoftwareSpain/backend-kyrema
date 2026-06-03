<?php

use Illuminate\Support\Facades\DB;

$codigos = ['ARAC38144-2026', 'ARAC37462-2026'];

// Los productos en destino tienen id_socio en origen a través de id_seguro = poliza_seguro
// Los codigos ARAC son de seguros_combinados en MySQL
// Buscar por poliza_seguro que coincida con el numero

echo "=== Buscando en seguros_combinados por poliza ===\n";

// ARAC37462-2026 → el número es 37462
// ARAC38144-2026 → el número es 38144
foreach ($codigos as $codigo) {
    preg_match('/(\d+)-(\d+)/', $codigo, $m);
    $numero = $m[1] ?? null;
    $anio   = $m[2] ?? null;

    echo "\nBuscando código: {$codigo} (número: {$numero}, año: {$anio})\n";

    // Buscar en seguros_combinados por id_seguro o poliza_seguro
    $rows = DB::connection('mysql')
        ->table('seguros_combinados')
        ->where(function($q) use ($numero, $codigo) {
            $q->where('id_seguro', $numero)
              ->orWhere('poliza_seguro', $codigo)
              ->orWhere('poliza_seguro', 'like', "%{$numero}%");
        })
        ->get();

    foreach ($rows as $r) {
        echo "  Seguro encontrado: id_seguro={$r->id_seguro} | poliza={$r->poliza_seguro} | id_socio={$r->id_socio} | id_sociedad={$r->id_sociedad}\n";

        // Buscar el socio en MySQL
        $socio = DB::connection('mysql')
            ->table('socios')
            ->where('id_socio', $r->id_socio)
            ->first();

        if ($socio) {
            echo "  Socio MySQL: id={$socio->id_socio} | nombre={$socio->nombre_socio} | dni={$socio->dni} | email={$socio->email}\n";

            // Buscar en destino SQL Server por DNI
            $dniNorm = strtoupper(trim(str_replace([' ','-'], '', $socio->dni ?? '')));
            if ($dniNorm) {
                $socioDestino = DB::connection('sqlsrv')
                    ->table('socios')
                    ->whereRaw("UPPER(REPLACE(REPLACE(dni,' ',''),'-','')) = ?", [$dniNorm])
                    ->first(['id','dni','nombre_socio']);

                if ($socioDestino) {
                    echo "  ✔ Socio en SQL Server: id={$socioDestino->id} | {$socioDestino->nombre_socio}\n";
                    echo "  → Hay que actualizar producto_k id (ver abajo) con socio_id={$socioDestino->id} y rellenar datos del tomador\n";
                } else {
                    echo "  ✘ Socio NO está en SQL Server. DNI={$dniNorm}\n";
                }
            } else {
                echo "  ✘ El socio de origen tampoco tiene DNI.\n";
            }
        } else {
            echo "  ✘ id_socio={$r->id_socio} no encontrado en MySQL socios\n";
        }
    }

    if ($rows->isEmpty()) {
        echo "  ✘ No encontrado en seguros_combinados\n";
    }
}
