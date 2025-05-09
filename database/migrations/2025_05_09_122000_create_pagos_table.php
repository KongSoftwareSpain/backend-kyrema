<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();

            $table->string('referencia');
            $table->string('letras_identificacion');
            $table->unsignedBigInteger('producto_id');

            $table->enum('tipo_pago', ['giro', 'transferencia', 'tarjeta', 'otros']); // más tipos en el futuro
            $table->decimal('monto', 10, 2);
            $table->date('fecha'); // fecha de operación o de cobro prevista

            $table->enum('estado', ['pending', 'confirmado', 'fallido'])->default('pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
