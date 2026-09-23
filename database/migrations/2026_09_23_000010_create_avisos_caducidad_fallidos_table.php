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
        Schema::create('avisos_caducidad_fallidos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('perfil_id')->nullable();
            $table->string('letras_identificacion')->nullable();
            $table->unsignedBigInteger('producto_id')->nullable();
            $table->string('comercial_id')->nullable();
            $table->string('email_destino')->nullable();
            $table->text('motivo_error');
            $table->boolean('forzado')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('perfil_id')->references('id')->on('perfiles_envio_avisos')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('avisos_caducidad_fallidos');
    }
};
