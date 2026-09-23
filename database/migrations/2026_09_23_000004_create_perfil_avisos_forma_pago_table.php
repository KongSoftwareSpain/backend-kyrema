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
        Schema::create('perfil_avisos_forma_pago', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('perfil_id');
            // Valor libre tal cual se guarda en tipo_de_pago de las tablas de
            // producto (no es un id del catálogo tipos_pago).
            $table->string('valor');
            $table->timestamps();

            $table->foreign('perfil_id')->references('id')->on('perfiles_envio_avisos')->onDelete('cascade');
            $table->unique(['perfil_id', 'valor']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('perfil_avisos_forma_pago');
    }
};
