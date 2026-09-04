<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_gateway_links', function (Blueprint $t) {
            // Token de un solo uso que kyrema.org canjea para pedir los campos Ds_* firmados.
            $t->string('access_token', 64)->nullable()->unique()->after('gateway_payload');
            $t->timestamp('token_expires_at')->nullable()->after('access_token');
            $t->timestamp('token_used_at')->nullable()->after('token_expires_at');

            // URL de canamaseguros.com a la que kyrema.org debe devolver al cliente tras pagar.
            $t->string('return_url', 2048)->nullable()->after('token_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_gateway_links', function (Blueprint $t) {
            $t->dropColumn(['access_token', 'token_expires_at', 'token_used_at', 'return_url']);
        });
    }
};
