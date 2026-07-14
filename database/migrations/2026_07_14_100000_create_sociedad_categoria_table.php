<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivote sociedad-categoría: marca a qué categorías (marcas) pertenece cada sociedad.
     */
    public function up(): void
    {
        Schema::create('sociedad_categoria', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_sociedad');
            $table->unsignedBigInteger('id_categoria');
            $table->timestamps();

            $table->unique(['id_sociedad', 'id_categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sociedad_categoria');
    }
};
