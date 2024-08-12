<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;
use Carbon\Carbon;

class ProductsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $faker = Faker::create();
        $tableName = 'SE';
        $tableDatePrefix = Carbon::now()->format('mY');
        
        for ($i = 0; $i < 100; $i++) { // Cambiado a 1000 para más datos
            $fechaDeNacimiento = $faker->dateTimeBetween('-80 years', '-18 years')->format('Y-m-d');
            $pruebaFecha = $faker->dateTimeBetween('-30 years', 'now')->format('Y-m-d');
            $createdAt = Carbon::now()->toDateTimeString();
            $updatedAt = Carbon::now()->toDateTimeString();
            
            // Obtener el último código de producto generado
            $lastProduct = DB::table($tableName)
                ->where('codigo_producto', 'like', $tableDatePrefix . $tableName . '%')
                ->orderBy('codigo_producto', 'desc')
                ->first();
            
            // Calcular el siguiente número secuencial
            $lastNumber = $lastProduct ? intval(substr($lastProduct->codigo_producto, -6)) : 0;
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
            
            // Generar el nuevo código de producto
            $newCodigoProducto = $tableDatePrefix . $tableName . $newNumber;

            DB::table($tableName)->insert([
                // Campos fijos
                'codigo_producto' => $newCodigoProducto,
                'tipo_de_pago' => $faker->randomElement(['Mensual', 'Trimestral', 'Semestral', 'Anual']),
                'tipo_de_pago_id' => $faker->randomElement([1, 2, 3, 4]),
                'prima_del_seguro' => $faker->randomFloat(2, 0, 99999),
                'cuota_de_asociación' => $faker->randomFloat(2, 0, 99999),
                'precio_total' => $faker->randomFloat(2, 0, 99999),
                'sociedad_id' => 1,
                'sociedad' => 'Admin',
                'comercial_id' => 1,
                'comercial' => 'Admin',
                'dni' => $faker->unique()->numerify('########') . strtoupper($faker->randomLetter),
                'nombre_socio' => $faker->firstName,
                'apellido_1' => $faker->lastName,
                'apellido_2' => $faker->lastName,
                'email' => $faker->unique()->safeEmail,
                'telefono' => $faker->phoneNumber,
                'sexo' => $faker->randomElement(['M', 'F']),
                'dirección' => $faker->address,
                'población' => $faker->city,
                'provincia' => $faker->state,
                'codigo_postal' => $faker->numerify('#####'),
                'fecha_de_nacimiento' => $fechaDeNacimiento,
                'numero_anexos' => 0,

                // Campos variables
                'tipo_de_caceria' => $faker->randomElement(['Montería', 'Ganchos', 'Rececho', 'Aguardo', 'Espera', 'Ojeo']),
                'poblacion' => $faker->city,
                'codigo_postal_caceria' => $faker->numerify('#####'),
                'matricula' => $faker->numerify('######'),
                'dia_de_la_caceria' => $pruebaFecha,

                // Timestamps
                'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'), // Formato 'Y-m-d\TH:i:s'
                'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'), // Formato 'Y-m-d\TH:i:s'
            ]);
        }
    }
}
