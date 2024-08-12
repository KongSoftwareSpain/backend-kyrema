<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CampoSeeder extends Seeder
{
    public function run()
    {
        DB::table('campos')->insert([
            [
                'id' => Str::uuid(),
                'nombre' => 'Nº certificado',
                'tipo_producto_id' => '1',
                'visible' => true,
                'obligatorio' => true,
                'aparece_formulario' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'nombre' => 'Id del socio',
                'tipo_producto_id' => '1',
                'visible' => true,
                'obligatorio' => true,
                'aparece_formulario' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'nombre' => 'DNI organizador',
                'tipo_producto_id' => '1',
                'visible' => true,
                'obligatorio' => true,
                'aparece_formulario' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'nombre' => 'Nombre organizador',
                'tipo_producto_id' => '1',
                'visible' => true,
                'obligatorio' => true,
                'aparece_formulario' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'nombre' => 'Tipo',
                'tipo_producto_id' => '1',
                'visible' => true,
                'obligatorio' => true,
                'aparece_formulario' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'nombre' => 'Subtipo',
                'tipo_producto_id' => '1',
                'visible' => true,
                'obligatorio' => true,
                'aparece_formulario' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'nombre' => 'Suma asegurada',
                'tipo_producto_id' => '1',
                'visible' => true,
                'obligatorio' => true,
                'aparece_formulario' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'nombre' => 'Día celebración',
                'tipo_producto_id' => '1',
                'visible' => true,
                'obligatorio' => true,
                'aparece_formulario' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'nombre' => 'Fecha emisión',
                'tipo_producto_id' => '1',
                'visible' => true,
                'obligatorio' => true,
                'aparece_formulario' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'nombre' => 'Dominio',
                'tipo_producto_id' => '1',
                'visible' => true,
                'obligatorio' => true,
                'aparece_formulario' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'nombre' => 'Matricula del coto',
                'tipo_producto_id' => '1',
                'visible' => false,
                'obligatorio' => false,
                'aparece_formulario' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}
