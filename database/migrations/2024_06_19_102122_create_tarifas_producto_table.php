<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarifas_producto', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tipo_producto_id');
            $table->unsignedBigInteger('id_sociedad');
            $table->decimal('prima_seguro', 10, 2)->nullable();
            $table->decimal('cuota_asociacion', 10, 2)->nullable();
            $table->decimal('precio_total', 10, 2)->nullable();
            $table->foreign('id_sociedad')->references('id')->on('sociedad')->onDelete('cascade');
            $table->foreign('tipo_producto_id')->references('id')->on('tipo_producto')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifas_producto');
    }
};
