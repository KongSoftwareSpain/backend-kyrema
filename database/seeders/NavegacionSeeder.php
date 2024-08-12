<?php

use Illuminate\Database\Seeder;
use App\Models\Navegacion;

class NavegacionSeeder extends Seeder
{
    public function run()
    {
        // Nivel 1
        $administracion = Navegacion::create([
            'navegacion' => 'Administración',
            'ruta' => '',
            'nivel' => 1,
        ]);

        $gestion = Navegacion::create([
            'navegacion' => 'Gestión',
            'ruta' => '',
            'nivel' => 1,
        ]);

        $productos = Navegacion::create([
            'navegacion' => 'Productos',
            'ruta' => '',
            'nivel' => 1,
        ]);

        // Nivel 2 - Hijos de Gestión
        $gestion_sociedades = Navegacion::create([
            'navegacion' => 'Sociedades',
            'ruta' => '/sociedades',
            'nivel' => 2,
            'navegacion_padre_id' => $gestion->id,
        ]);

        $tarifas = Navegacion::create([
            'navegacion' => 'Tarifas',
            'ruta' => '/tarifas',
            'nivel' => 2,
            'navegacion_padre_id' => $gestion->id,
        ]);

        $comisiones = Navegacion::create([
            'navegacion' => 'Comisiones',
            'ruta' => '/comisiones',
            'nivel' => 2,
            'navegacion_padre_id' => $gestion->id,
        ]);

        $productos_gestion = Navegacion::create([
            'navegacion' => 'Productos',
            'ruta' => '/gestion-productos',
            'nivel' => 2,
            'navegacion_padre_id' => $gestion->id,
        ]);

        // Nivel 3 - Hijos de Productos
        $seguros_combinados = Navegacion::create([
            'navegacion' => 'Seguros Combinados',
            'ruta' => '/operaciones/seguros-combinados',
            'nivel' => 3,
            'navegacion_padre_id' => $productos_gestion->id,
        ]);

        $seguros_cacerias = Navegacion::create([
            'navegacion' => 'Seguros de Cacerías',
            'ruta' => '/operaciones/seguros-cacerias',
            'nivel' => 3,
            'navegacion_padre_id' => $productos_gestion->id,
        ]);

        $tarjetas_emisoras = Navegacion::create([
            'navegacion' => 'Tarjetas Emisoras',
            'ruta' => '/operaciones/tarjetas-emisoras',
            'nivel' => 3,
            'navegacion_padre_id' => $productos_gestion->id,
        ]);

        $seguros_kyrema_naturaleza = Navegacion::create([
            'navegacion' => 'Seguros Kyrema Naturaleza',
            'ruta' => '/operaciones/seguros-kyrema-naturaleza',
            'nivel' => 3,
            'navegacion_padre_id' => $productos_gestion->id,
        ]);

        $seguros_extranjeros = Navegacion::create([
            'navegacion' => 'Seguros Extranjeros',
            'ruta' => '/operaciones/seguros-extranjeros',
            'nivel' => 3,
            'navegacion_padre_id' => $productos_gestion->id,
        ]);
    }
}

