<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('nombre_codigo')->nullable();
            $table->string('tipo_producto_id')->nullable();
            $table->string('columna')->nullable();
            $table->string('fila')->nullable();
            $table->string('tipo_dato');
            $table->boolean('visible');
            $table->boolean('obligatorio');
            $table->string('grupo')->nullable();
            $table->string('opciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campos');
    }
};
