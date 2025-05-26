<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('referencia_secuencias', function (Blueprint $table) {
            $table->string('letras_identificacion')->primary();
            $table->unsignedInteger('ultimo_producto')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referencia_secuencias');
    }
};
