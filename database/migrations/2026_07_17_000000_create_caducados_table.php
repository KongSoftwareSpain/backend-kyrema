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
        Schema::create('caducados', function (Blueprint $table) {
            $table->id();
            $table->date('fecha'); // Fecha en la que se archivó por caducidad
            $table->unsignedBigInteger('sociedad_id')->nullable();
            $table->string('letrasIdentificacion'); // Nombre de la tabla donde está el seguro caducado
            $table->unsignedBigInteger('producto_id'); // ID del producto en su tabla original
            $table->string('codigo_producto')->nullable();
            $table->date('fecha_de_fin')->nullable(); // Fecha de fin original de la póliza
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caducados');
    }
};
