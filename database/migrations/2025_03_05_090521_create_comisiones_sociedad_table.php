<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('comisiones_sociedad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_sociedad')->constrained('sociedad')->onDelete('cascade'); // Asegúrate de que 'comerciales' sea la tabla correcta
            $table->decimal('valor', 10, 2);
            $table->foreignId('tipo_producto_id')->constrained('tipo_producto')->onDelete('cascade'); // Asegúrate de que 'tipos_producto' sea la tabla correcta
            $table->string('tipo', 50);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('comisiones_sociedad');
    }
};

