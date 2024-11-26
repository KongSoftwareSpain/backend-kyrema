<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipo_producto', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo_tipo_producto')->unique();
            $table->string('letras_identificacion')->nullable();
            $table->string('plantilla_path_1')->nullable(); // Ruta del archivo en lugar de los datos binarios
            $table->string('plantilla_path_2')->nullable(); // Ruta del archivo en lugar de los datos binarios
            $table->string('plantilla_path_3')->nullable(); // Ruta del archivo en lugar de los datos binarios
            $table->string('plantilla_path_4')->nullable(); // Ruta del archivo en lugar de los datos binarios
            $table->string('plantilla_path_5')->nullable(); // Ruta del archivo en lugar de los datos binarios
            $table->string('plantilla_path_6')->nullable(); // Ruta del archivo en lugar de los datos binarios
            $table->string('plantilla_path_7')->nullable(); // Ruta del archivo en lugar de los datos binarios
            $table->string('plantilla_path_8')->nullable(); // Ruta del archivo en lugar de los datos binarios
            $table->unsignedBigInteger('padre_id')->nullable();
            $table->timestamps();

            // Referencia al padre (auto-referencia)
            $table->foreign('padre_id')
                  ->references('id')
                  ->on('tipo_producto')
                  ->onDelete('no action'); // Si se elimina el padre, se establece el campo padre_id en NULL
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_producto');
    }
};


