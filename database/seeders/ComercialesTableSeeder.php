<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Comercial;

class ComercialesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Definir datos del comercial de prueba
        $comercialData = [
            'nombre' => 'Pedro',
            'usuario' => 'pericoagario@gmail.com', // Puede ser un email u otro identificador único
            'email' => 'pericoagario@gmail.com',
            'contraseña' => Hash::make('admin@C1'), // Hashear la contraseña
            'id_sociedad' => 1, // ID de la sociedad a la que pertenece el comercial
            'dni' => '12345678A',
            // Otros campos según tu estructura de la tabla Comercial
        ];

        // Insertar el comercial en la base de datos
        Comercial::create($comercialData);
    }
}

