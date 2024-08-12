<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comercial_comision', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_comercial');
            $table->boolean('porcentual');
            $table->decimal('comision', 10, 2);
            $table->foreign('id_comercial')->references('id')->on('comercial')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comercial_comision');
    }
};
