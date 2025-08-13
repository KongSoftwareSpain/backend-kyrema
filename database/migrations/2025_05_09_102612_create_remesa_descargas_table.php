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
        Schema::create('remesa_descargas', function (Blueprint $table) {
            $table->id();
            $table->string('ruta_xml');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->timestamp('descargado_en');
            $table->unsignedBigInteger('id_comercial');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remesa_descargas');
    }
};
