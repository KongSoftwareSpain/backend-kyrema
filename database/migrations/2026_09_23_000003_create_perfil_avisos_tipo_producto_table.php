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
        Schema::create('perfil_avisos_tipo_producto', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('perfil_id');
            // tipo_producto.id es string (ver App\Models\TipoProducto).
            $table->string('tipo_producto_id');
            $table->timestamps();

            $table->foreign('perfil_id')->references('id')->on('perfiles_envio_avisos')->onDelete('cascade');
            $table->unique(['perfil_id', 'tipo_producto_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('perfil_avisos_tipo_producto');
    }
};
