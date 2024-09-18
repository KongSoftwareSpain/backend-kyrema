<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTipoPagoProductoSociedadTable extends Migration
{
    public function up()
    {
        Schema::create('tipo_pago_producto_sociedad', function (Blueprint $table) {
            $table->id(); // Crea una columna 'id' autoincremental
            $table->foreignId('tipo_pago_id')
                ->constrained('tipos_pago') // Referencia a la tabla 'tipos_pago'
                ->onDelete('cascade'); // Elimina registros si se elimina el tipo de pago
            $table->foreignId('tipo_producto_id')
                ->constrained('tipo_producto') // Referencia a la tabla 'tipo_producto'
                ->onDelete('cascade'); // Elimina registros si se elimina el producto
            $table->foreignId('sociedad_id')
                ->constrained('sociedad') // Referencia a la tabla 'sociedades'
                ->onDelete('cascade'); // Elimina registros si se elimina la sociedad
            $table->timestamps(); // Añade las columnas 'created_at' y 'updated_at'
        });
    }

    public function down()
    {
        Schema::dropIfExists('tipo_pago_producto_sociedad');
    }
}
