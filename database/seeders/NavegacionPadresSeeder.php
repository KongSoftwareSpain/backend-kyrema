<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Navegacion;

class NavegacionPadresSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
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

    }
}
