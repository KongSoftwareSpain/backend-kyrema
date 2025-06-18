<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSociedadToGirosBancariosTable extends Migration
{
    public function up(): void
    {
        Schema::table('giros_bancarios', function (Blueprint $table) {
            $table->string('sociedad')->nullable()->after('pago_id');
        });
    }

    public function down(): void
    {
        Schema::table('giros_bancarios', function (Blueprint $table) {
            $table->dropColumn('sociedad');
        });
    }
}
