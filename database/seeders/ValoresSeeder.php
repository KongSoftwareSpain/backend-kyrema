<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ValoresSeeder extends Seeder
{
    public function run()
    {
        $productosCount = 300; // Número de productos

        $campos = [
            '0931602c-3559-423f-b24c-12efa0ed531b', // Tipo
            '27c9dae6-a6b4-40e0-81f5-03ada7e04d82', // Dominio
            '415076b0-f819-4ed9-b20c-c06e98868c3f', // Nº certificado
            '66d97057-89d7-432b-8e21-b982ed2eb3e6', // DNI organizador
            '79049eb9-9374-4bff-871f-4cd4fc60e2e5', // Suma asegurada
            '9efb1f0d-cb32-4f28-8b7b-d004a4b2ebc8', // Matricula del coto
            'bbc74e0e-33aa-4b06-ab80-903e7b99e849', // Id del socio
            'cfe42923-5b92-4d3c-becd-788c5db2b643', // Nombre organizador
            'd1630ae3-acb0-4892-bd80-bd956b6939f8', // Subtipo
            'e024c61a-730e-431c-9c8b-7f2a3226a84c', // Día celebración
            'fd1f763c-0211-4cd6-aecb-92fe0c4ccc9c', // Fecha emisión
        ];

        // Fecha inicial para los productos
        $currentDate = Carbon::now();

        for ($i = 201; $i <= $productosCount; $i++) {
            foreach ($campos as $campo) {
                DB::table('valores')->insert([
                    'campo_id' => $campo,
                    'valor' => 'Valor ' . $i,
                    'producto_id' => $i,
                    'created_at' => $currentDate->format('Y-m-d H:i:s'), // Formato correcto para SQL Server
                    'updated_at' => $currentDate->format('Y-m-d H:i:s'), // Formato correcto para SQL Server
                ]);
            }
            // Retroceder 30 minutos para el siguiente producto
            $currentDate->subMinutes(30);
        }
    }
}