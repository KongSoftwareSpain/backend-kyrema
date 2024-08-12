<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TipoProductoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('tipo_producto')->insert([
            'id' => '1',
            'letras_identificacion' => 'SC',
            'plantilla' => DB::raw('CONVERT(varbinary(max), NULL)'),
            'nombre' => 'Seguros cacerias',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
