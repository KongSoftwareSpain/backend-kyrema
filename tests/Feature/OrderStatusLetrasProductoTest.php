<?php

namespace Tests\Feature;

use App\Http\Controllers\Payments\RedsysInsiteController;
use App\Models\Payments\Pago;
use App\Models\Payments\PaymentGatewayLink;
use App\Services\Payments\RedsysInsiteService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regresión del bug "tras pagar con tarjeta un subproducto, Volver lleva a una lista vacía": el pago
 * guarda las letras del SUBPRODUCTO (k1) y orderStatus las devolvía tal cual, pero las rutas de
 * listado del front (/mis-productos/producto_k, /operaciones/producto_k) usan las del PADRE.
 * orderStatus ahora devuelve además letras_producto, con las del padre en minúsculas.
 *
 * Corre SIEMPRE sobre SQLite en memoria. No usa RefreshDatabase a propósito: el .env apunta a la
 * BD real, y migrate:fresh sobre ella sería un desastre.
 */
class OrderStatusLetrasProductoTest extends TestCase
{
    private RedsysInsiteController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        // Freno de seguridad: si por lo que sea no estamos en SQLite, no se toca NADA.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->fail('Estos tests solo pueden correr sobre SQLite en memoria (revisa phpunit.xml).');
        }

        Schema::create('tipo_producto', function (Blueprint $t) {
            $t->id();
            $t->string('nombre')->nullable();
            $t->string('letras_identificacion');
            $t->unsignedBigInteger('padre_id')->nullable();
        });

        Schema::create('pagos', function (Blueprint $t) {
            $t->id();
            $t->string('referencia')->nullable();
            $t->string('tipo_pago')->nullable();
            $t->decimal('monto', 10, 2)->nullable();
            $t->integer('amount_cents')->nullable();
            $t->string('currency')->nullable();
            $t->dateTime('fecha')->nullable();
            $t->string('estado')->nullable();
            $t->string('letras_identificacion')->nullable();
            $t->unsignedBigInteger('producto_id')->nullable();
            $t->unsignedBigInteger('sociedad_id')->nullable();
            $t->string('auth_code')->nullable();
            $t->string('response_code')->nullable();
            $t->string('response_message')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });

        Schema::create('payment_gateway_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('pago_id');
            $t->string('gateway');
            $t->unsignedBigInteger('redsys_request_id')->nullable();
            $t->string('gateway_order_ref')->nullable();
            $t->string('gateway_status')->nullable();
            $t->text('gateway_payload')->nullable();
            $t->string('access_token')->nullable();
            $t->dateTime('token_expires_at')->nullable();
            $t->dateTime('token_used_at')->nullable();
            $t->string('return_url')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });

        // Padre (tabla física producto_k) y su subproducto k1
        DB::table('tipo_producto')->insert([
            ['id' => 1, 'nombre' => 'Cazador', 'letras_identificacion' => 'PRODUCTO_K', 'padre_id' => null],
            ['id' => 2, 'nombre' => 'K1', 'letras_identificacion' => 'K1', 'padre_id' => 1],
        ]);

        // El servicio de Redsys monta un cliente con la configuración real; aquí no se usa.
        $this->mock(RedsysInsiteService::class);
        $this->controller = app(RedsysInsiteController::class);
    }

    /** Crea un pago con esas letras y su enlace de pasarela; devuelve la referencia del pedido. */
    private function pagoConLetras(?string $letras, string $order): string
    {
        $pago = Pago::create([
            'tipo_pago' => 'tarjeta',
            'monto' => 50,
            'amount_cents' => 5000,
            'estado' => Pago::STATUS_PAID,
            'letras_identificacion' => $letras,
            'producto_id' => 77,
        ]);

        PaymentGatewayLink::create([
            'pago_id' => $pago->id,
            'gateway' => 'redsys',
            'gateway_order_ref' => $order,
        ]);

        return $order;
    }

    private function estado(string $order): array
    {
        return $this->controller->orderStatus(Request::create('/'), $order)->getData(true);
    }

    public function test_un_subproducto_devuelve_las_letras_del_padre_en_minusculas(): void
    {
        $datos = $this->estado($this->pagoConLetras('K1', 'ORD000000001'));

        $this->assertSame('K1', $datos['letras_identificacion']);
        $this->assertSame('producto_k', $datos['letras_producto']);
        $this->assertSame(77, $datos['producto_id']);
    }

    public function test_un_producto_sin_padre_devuelve_sus_propias_letras_en_minusculas(): void
    {
        $datos = $this->estado($this->pagoConLetras('PRODUCTO_K', 'ORD000000002'));

        $this->assertSame('producto_k', $datos['letras_producto']);
    }

    public function test_sin_letras_o_con_letras_desconocidas_devuelve_null(): void
    {
        $this->assertNull($this->estado($this->pagoConLetras(null, 'ORD000000003'))['letras_producto']);
        $this->assertNull($this->estado($this->pagoConLetras('NO_EXISTE', 'ORD000000004'))['letras_producto']);
    }
}
