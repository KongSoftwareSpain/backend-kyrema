<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('companias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('CIF')->unique();
            $table->string('IBAN')->unique();

            // Contactos
            $table->string('nombre_contacto_1')->nullable();
            $table->string('cargo_contacto_1')->nullable();
            $table->string('email_contacto_1')->nullable();
            $table->string('telefono_contacto_1')->nullable();

            $table->string('nombre_contacto_2')->nullable();
            $table->string('cargo_contacto_2')->nullable();
            $table->string('email_contacto_2')->nullable();
            $table->string('telefono_contacto_2')->nullable();

            $table->string('nombre_contacto_3')->nullable();
            $table->string('cargo_contacto_3')->nullable();
            $table->string('email_contacto_3')->nullable();
            $table->string('telefono_contacto_3')->nullable();

            $table->string('nombre_contacto_4')->nullable();
            $table->string('cargo_contacto_4')->nullable();
            $table->string('email_contacto_4')->nullable();
            $table->string('telefono_contacto_4')->nullable();

            $table->string('nombre_contacto_5')->nullable();
            $table->string('cargo_contacto_5')->nullable();
            $table->string('email_contacto_5')->nullable();
            $table->string('telefono_contacto_5')->nullable();

            $table->text('comentarios')->nullable();

            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companias');
    }
};
