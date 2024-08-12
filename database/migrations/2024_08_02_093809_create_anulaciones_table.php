<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAnulacionesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('anulaciones', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->date('fecha'); // Fecha de la anulación
            $table->unsignedBigInteger('sociedad_id'); 
            $table->unsignedBigInteger('comercial_id'); // ID del comercial
            $table->string('sociedad_nombre'); // Nombre de la sociedad
            $table->string('comercial_nombre'); // Nombre del comercial
            $table->string('causa'); // Causa de la anulación
            $table->string('letrasIdentificacion'); // Nombre de la tabla donde está el seguro anulado
            $table->unsignedBigInteger('producto_id'); // ID del producto
            $table->string('codigo_producto'); // Código del producto
            $table->timestamps(); // created_at y updated_at

            // Foreign keys
            $table->foreign('comercial_id')->references('id')->on('comercial')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('anulaciones');
    }
}

