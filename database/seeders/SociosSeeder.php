<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SociosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('socios')->insert([
            'email' => 'socio@gmail.com',
            'password' => Hash::make('1'), // Hasheamos la contraseña
        ]);
    }
}
