<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comercial', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->unsignedBigInteger('id_sociedad');
            $table->string('usuario');
            $table->string('email');
            $table->string('contraseña');
            $table->foreign('id_sociedad')->references('id')->on('sociedad')->onDelete('cascade');
            // Además: DNI, Sexo, Fecha de nacimiento, Fecha de alta, Referido, Direccion, Población, Provincia, Cod. Postal , Telefono, Fax, path Licencia de cazador, path_DNI, path justificante IBAN, path_otros
            $table->string('dni');
            $table->string('sexo')->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->date('fecha_alta')->nullable();
            $table->string('referido')->nullable();
            $table->string('direccion')->nullable();
            $table->string('poblacion')->nullable();
            $table->string('provincia')->nullable();
            $table->string('cod_postal')->nullable();
            $table->string('telefono')->nullable();
            $table->string('fax')->nullable();
            $table->string('path_licencia_cazador')->nullable();
            $table->string('path_dni')->nullable();
            $table->string('path_justificante_iban')->nullable();
            $table->string('path_otros')->nullable();
            $table->string('path_foto')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comercial');
    }
};
