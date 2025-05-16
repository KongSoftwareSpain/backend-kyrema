<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('giros_bancarios', function (Blueprint $table) {
            $table->id();

            // Relación general
            $table->foreignId('pago_id')->constrained('pagos')->onDelete('cascade');

            // Campos específicos del pago por giro bancario
            $table->string('referencia')->nullable(); // Codigo del producto*
            $table->string('nombre_cliente');     // NOMBRE*
            $table->string('dni')->nullable();    // DNI
            $table->decimal('importe', 10, 2);    // IMPORTE

            $table->date('fecha_firma_mandato');  // FECHAFIRMAMANDATO*
            $table->string('iban_cliente');       // IBAN*
            $table->string('auxiliar')->nullable(); // Comercial/Auxiliar (nombre o id según modelo)
            $table->string('residente')->default('S'); // S por defecto
            $table->string('referencia_mandato'); // REFMANDATO*
            $table->date('fecha_cobro')->nullable();          // FECHACOBRO*
            $table->string('referencia_adeudo');  // REFADEUDO*
            $table->string('tipo_adeudo')->default('FRST'); // TIPOADEUDO*

            $table->string('concepto');           // CONCEPTO*

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giros_bancarios');
    }
};
