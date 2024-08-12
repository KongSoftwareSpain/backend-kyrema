<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sociedad;

class SociedadSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Sociedad::create([ 
            'nombre' => 'Admin',
            'codigo_postal' => '0000',
            'poblacion' => 'Admin',
            'tipo_sociedad' => 'Admin',
            'nivel_sociedad' => 1,
            'sociedad_padre_id' => null, // Opcional: Si tiene sociedad padre, cambia esto
        ]);

        // Puedes añadir más registros según sea necesario
    }
}
