<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('configuracion_avisos_caducidad', function (Blueprint $table) {
            $table->id();
            $table->json('dias_aviso');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        DB::table('configuracion_avisos_caducidad')->insert([
            'dias_aviso' => json_encode([30, 15, 1]),
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuracion_avisos_caducidad');
    }
};
