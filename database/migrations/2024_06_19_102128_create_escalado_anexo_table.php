<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalado_anexo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('anexo_id');
            $table->decimal('desde', 10, 2);
            $table->decimal('hasta', 10, 2);
            $table->decimal('precio', 10, 2);
            $table->foreign('anexo_id')->references('id')->on('tarifas_anexos')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalado_anexo');
    }
};
