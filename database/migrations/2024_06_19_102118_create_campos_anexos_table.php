<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campos_anexos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('nombre_codigo')->nullable();
            $table->string('columna')->nullable();
            $table->string('fila')->nullable();
            $table->string('tipo_dato');
            $table->unsignedBigInteger('tipo_anexo');
            $table->boolean('obligatorio');
            $table->foreign('tipo_anexo')->references('id')->on('tipos_anexos')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campos_anexos');
    }
};

