<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_anexos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            // Nombre cambiado para poder hacerle referencia
            $table->string('letras_identificacion');
            $table->string('id_tipo_producto')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_anexos');
    }
};
