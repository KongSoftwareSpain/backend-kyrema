<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProductsSeeder extends Seeder
{
    public function run()
    {
        $faker = Faker::create();
        $tableName = 'producto_pss';
        $plantillaPath = 'plantillas/PRODUCTO PSS.docx';
        $tableDatePrefix = Carbon::now()->format('mY');

        for ($i = 0; $i < 1000; $i++) {
            $fechaDeNacimiento = $faker->dateTimeBetween('-80 years', '-18 years')->format('Y-m-d');
            $fechaDeEmision = $faker->dateTimeBetween('-30 years', 'now')->format('Y-m-d');
            $fechaDeInicio = $faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d');
            $fechaDeFin = $faker->dateTimeBetween('now', '+1 year')->format('Y-m-d');
            $createdAt = Carbon::now()->toDateTimeString();
            $updatedAt = Carbon::now()->toDateTimeString();
            
            // Obtener el último código de producto generado
            $lastProduct = DB::table($tableName)
            ->orderBy('id', 'desc')
            ->first();
            
            // Calcular el siguiente número secuencial
            $lastNumber = $lastProduct ? intval(substr($lastProduct->codigo_producto, -6)) : 0;
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
            
            // Generar el nuevo código de producto
            $newCodigoProducto = $tableDatePrefix . $newNumber;

            DB::table($tableName)->insert([
                'sociedad_id' => 1,
                'tipo_de_pago_id' => $faker->randomElement([5, 6, 7, 8, 9]),
                'comercial_id' => 1,
                'plantilla_path' => $plantillaPath,
                'duracion' => $faker->numberBetween(1, 12),
                'anulado' => false,
                'codigo_producto' => $newCodigoProducto,
                'fecha_de_emisión' => $fechaDeEmision,
                'fecha_de_inicio' => $fechaDeInicio,
                'fecha_de_fin' => $fechaDeFin,
                'sociedad' => 'Admin',
                'comercial' => 'Admin',
                'tipo_de_pago' => $faker->randomElement(['Transferencia', 'No completado', 'Domiciliación', 'Efectivo', 'Tarjeta']),
                'prima_del_seguro' => $faker->randomFloat(2, 0, 99999),
                'cuota_de_asociación' => $faker->randomFloat(2, 0, 99999),
                'precio_total' => $faker->randomFloat(2, 0, 99999),
                'precio_final' => $faker->randomFloat(2, 0, 99999),
                'numero_anexos' => 0,
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
                'dni_acompañante' => $faker->unique()->numerify('########') . strtoupper($faker->randomLetter),
                'acompañante' => $faker->firstName . ' ' . $faker->lastName,
                'cazador' => $faker->boolean,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);
        }
    }
}
