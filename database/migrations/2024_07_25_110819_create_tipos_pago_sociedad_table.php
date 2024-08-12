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
        Schema::create('tipos_pago_sociedad', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tipo_pago_id');
            $table->unsignedBigInteger('sociedad_id');
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('tipo_pago_id')->references('id')->on('tipos_pago')->onDelete('cascade');
            $table->foreign('sociedad_id')->references('id')->on('sociedad')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tipos_pago_sociedad');
    }
};
