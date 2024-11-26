<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePolizasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('polizas', function (Blueprint $table) {
            $table->id();

            // Relación con la tabla companias
            $table->unsignedBigInteger('compania_id');
            $table->foreign('compania_id')->references('id')->on('companias')->onDelete('cascade');
            
            // Datos principales de la póliza
            $table->string('numero')->unique();
            $table->string('ramo');
            $table->text('descripcion')->nullable();
            $table->decimal('prima_neta', 10, 2);
            $table->decimal('impuestos', 10, 2);

            // Fechas
            $table->date('fecha_inicio');
            $table->date('fecha_fin_venta')->nullable();
            $table->date('fecha_fin_servicio')->nullable();

            // Estado de la póliza
            $table->string('estado');

            // Documentos adjuntos
            $table->string('doc_adjuntos_1')->nullable();
            $table->string('doc_adjuntos_2')->nullable();
            $table->string('doc_adjuntos_3')->nullable();
            $table->string('doc_adjuntos_4')->nullable();
            $table->string('doc_adjuntos_5')->nullable();
            $table->string('doc_adjuntos_6')->nullable();

            // Comentarios
            $table->text('comentarios')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('polizas');
    }
}
