<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_gateway_links', function (Blueprint $t) {
            $t->id();

            // FK a tu tabla general de pagos
            $t->foreignId('pago_id')
              ->constrained('pagos')
              ->cascadeOnDelete();

            // Identifica el gateway (te permitirá añadir otros en el futuro)
            $t->string('gateway', 40)->default('redsys');

            // Enlace técnico a la operación en Redsys (tabla del paquete)
            $t->foreignId('redsys_request_id')
              ->nullable()
              ->constrained('redsys_requests') // ajusta si el nombre difiere
              ->nullOnDelete();

            // Referencias y estado técnico (opcional pero útil)
            $t->string('gateway_order_ref', 32)->nullable();  // p.ej. Ds_Order
            $t->string('gateway_status', 32)->nullable();     // created|paid|failed|...
            $t->json('gateway_payload')->nullable();          // trazas/params (JSON)

            $t->timestamps();

            // Índices habituales
            $t->unique(['pago_id', 'gateway']);                // un enlace por gateway
            $t->index(['gateway', 'redsys_request_id']);
            $t->index(['gateway', 'gateway_order_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_links');
    }
};
