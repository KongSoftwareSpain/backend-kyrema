<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cp_tipo_producto', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_tipo_producto');
            $table->boolean('vetado');
            $table->string('cp');
            $table->foreign('id_tipo_producto')->references('id')->on('tipo_producto')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cp_tipo_producto');
    }
};
