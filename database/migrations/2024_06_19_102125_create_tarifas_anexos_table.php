<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarifas_anexos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_tipo_anexo');
            $table->unsignedBigInteger('id_sociedad');
            $table->boolval('tiene_escalado');
            $table->decimal('prima_seguro', 10, 2)->nullable();
            $table->decimal('cuota_asociacion', 10, 2)->nullable();
            $table->decimal('precio_total', 10, 2)->nullable();
            $table->foreign('id_sociedad')->references('id')->on('sociedad')->onDelete('cascade');
            $table->foreign('id_tipo_anexo')->references('id')->on('tipos_anexos')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifas_anexos');
    }
};
