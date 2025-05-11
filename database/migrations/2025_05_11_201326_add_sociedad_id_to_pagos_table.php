<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSociedadIdToPagosTable extends Migration
{
    public function up()
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->unsignedBigInteger('sociedad_id')->nullable()->after('producto_id');

            $table->foreign('sociedad_id')
                  ->references('id')
                  ->on('sociedad')
                  ->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropForeign(['sociedad_id']);
            $table->dropColumn('sociedad_id');
        });
    }
}
