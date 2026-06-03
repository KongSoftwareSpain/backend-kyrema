<?php

use Illuminate\Support\Facades\DB;

/**
 * Reparación manual de los dos productos ARAC con socio_id NULL y datos vacíos.
 *
 * ARAC37462-2026 → producto_k.id=15523 → socio SQL Server id=28081 (Carlos Soto)
 * ARAC38144-2026 → producto_k.id=15525 → socio SQL Server id=28285 (Andoni Iturrioz)
 */

$reparaciones = [
    [
        'producto_k_id'      => 15523,
        'codigo'             => 'ARAC37462-2026',
        'socio_sqlsrv_id'    => 28081,
        'id_socio_mysql'     => 16949,
        'letras'             => 'PRODUCTO_KAVIP',   // subproducto=10254
    ],
    [
        'producto_k_id'      => 15525,
        'codigo'             => 'ARAC38144-2026',
        'socio_sqlsrv_id'    => 28285,
        'id_socio_mysql'     => 17195,
        'letras'             => 'PRODUCTO_KAVIP',   // subproducto=10254
    ],
];

$nowSql = DB::raw("CONVERT(datetime, '" . now()->format('Y-m-d H:i:s') . "', 120)");

foreach ($reparaciones as $rep) {
    echo "=== Reparando {$rep['codigo']} ===\n";

    // 1. Obtener datos completos del socio en SQL Server
    $socio = DB::connection('sqlsrv')
        ->table('socios')
        ->where('id', $rep['socio_sqlsrv_id'])
        ->first();

    if (!$socio) {
        echo "  ✘ Socio id={$rep['socio_sqlsrv_id']} no encontrado en SQL Server\n\n";
        continue;
    }

    echo "  Socio: {$socio->nombre_socio} {$socio->apellido_1} | DNI={$socio->dni}\n";

    // Preparar fecha de nacimiento para SQL Server
    $fechaNac = null;
    if (!empty($socio->fecha_de_nacimiento) && $socio->fecha_de_nacimiento !== '1900-01-01') {
        $fechaNac = DB::raw("CONVERT(datetime, '{$socio->fecha_de_nacimiento}', 120)");
    }

    // 2. Actualizar producto_k con socio_id y datos del tomador
    DB::connection('sqlsrv')
        ->table('producto_k')
        ->where('id', $rep['producto_k_id'])
        ->update([
            'socio_id'            => $rep['socio_sqlsrv_id'],
            'dni'                 => $socio->dni,
            'nombre_socio'        => $socio->nombre_socio,
            'apellido_1'          => $socio->apellido_1,
            'apellido_2'          => $socio->apellido_2,
            'email'               => $socio->email,
            'telefono'            => $socio->telefono,
            'fecha_de_nacimiento' => $fechaNac,
            'sexo'                => $socio->sexo,
            'dirección'           => $socio->direccion,
            'población'           => $socio->poblacion,
            'provincia'           => $socio->provincia,
            'codigo_postal'       => $socio->codigo_postal,
            'updated_at'          => $nowSql,
        ]);

    echo "  ✔ producto_k actualizado\n";

    // 3. Crear socios_productos si no existe
    $yaExiste = DB::connection('sqlsrv')
        ->table('socios_productos')
        ->where('id_producto', $rep['producto_k_id'])
        ->where('letras_identificacion', $rep['letras'])
        ->exists();

    if (!$yaExiste) {
        DB::connection('sqlsrv')
            ->table('socios_productos')
            ->insert([
                'id_producto'           => $rep['producto_k_id'],
                'id_socio'              => $rep['socio_sqlsrv_id'],
                'letras_identificacion' => $rep['letras'],
                'created_at'            => $nowSql,
                'updated_at'            => $nowSql,
            ]);
        echo "  ✔ socios_productos creado\n";
    } else {
        echo "  ℹ socios_productos ya existía\n";
    }

    echo "\n";
}

echo "=== Verificación final ===\n";
$rows = DB::connection('sqlsrv')
    ->table('producto_k')
    ->whereIn('codigo_producto', ['ARAC37462-2026', 'ARAC38144-2026'])
    ->get(['id', 'codigo_producto', 'socio_id', 'dni', 'nombre_socio', 'apellido_1']);

foreach ($rows as $r) {
    echo "[{$r->codigo_producto}] socio_id={$r->socio_id} | dni={$r->dni} | nombre={$r->nombre_socio} {$r->apellido_1}\n";
}
