<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TipoPagoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $paymentTypes = ['No completado', 'Banco', 'En mano', 'Tarjeta de crédito'];
        
        foreach ($paymentTypes as $paymentType) {
            DB::table('tipos_pago')->insert([
                'nombre' => $paymentType,
                'codigo' => strtolower(str_replace(' ', '_', $paymentType)), // Código en formato snake_case
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }
}
