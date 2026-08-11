<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('avisos_caducidad', function (Blueprint $table) {
            $table->id();
            $table->string('letras_identificacion');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedBigInteger('comercial_id');
            $table->integer('dias_aviso');
            $table->timestamp('fecha_aviso_enviado')->nullable();
            $table->timestamps();

            $table->index(['letras_identificacion', 'producto_id', 'comercial_id', 'dias_aviso']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('avisos_caducidad');
    }
};
