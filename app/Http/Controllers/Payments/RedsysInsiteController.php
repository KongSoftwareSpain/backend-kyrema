<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payments\Pago;
use App\Models\Payments\PaymentGatewayLink;
use App\Models\TipoProducto;
use Creagia\LaravelRedsys\Request as RedsysRequestModel;
use App\Services\Payments\RedsysInsiteService;
use App\Services\Payments\ProductoTableResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Creagia\Redsys\Exceptions\DeniedRedsysPaymentResponseException;
use Creagia\Redsys\Exceptions\ErrorRedsysResponseException;
use Creagia\Redsys\Exceptions\InvalidRedsysResponseException;
use Creagia\Redsys\RedsysClient;
use Creagia\Redsys\RedsysResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class RedsysInsiteController extends Controller
{
    public function __construct(
        private RedsysInsiteService $insite,
        private ProductoTableResolver $productoTableResolver,
    ) {}

    private function formatearFechaCorta(?string $fecha): string
    {
        if (!$fecha) {
            return '';
        }

        try {
            return Carbon::parse($fecha)->format('d-m-Y');
        } catch (\Exception $e) {
            return $fecha;
        }
    }

    public function start(Request $request)
    {
        $data = $request->validate([
            'amount'      => 'required|integer|min:1',  // céntimos
            'description' => 'nullable|string|max:255',
            // Necesaria para que notify() sepa en qué tabla activar/borrar el producto pendiente.
            'letras_identificacion' => 'required|string|max:32',
            'sociedad_id' => 'nullable|integer',
            'referencia'  => 'nullable|string|max:64',
            'fecha_inicio' => 'nullable|string',
            'fecha_fin'   => 'nullable|string',
            'subproducto_nombre' => 'nullable|string|max:255',
            // Solo llega en ediciones de un producto ya existente; en altas nuevas
            // el código de certificado todavía no se ha generado (ver ProductoController::crearProducto).
            'codigo_producto' => 'nullable|string|max:64',
            // Página de canamaseguros.com a la que kyrema.org debe devolver al cliente tras pagar.
            'return_url'  => 'required|string|max:2048',
            // Producto ya creado como pendiente_pago=true (ver ProductoController::crearProducto);
            // notify() lo activa o lo borra según el resultado del cobro.
            'producto_id' => 'required|integer',
        ]);

        $cfg = [
            'env'     => config('redsys.environment'),
            'code'    => config('redsys.tpv.merchantCode'),
            'term'    => config('redsys.tpv.terminal'),
            'has_key' => !!config('redsys.tpv.key'),
        ];
        Log::info('🔧 Redsys cfg', $cfg);

        $nombreProducto = TipoProducto::query()
            ->where('letras_identificacion', $data['letras_identificacion'] ?? '')
            ->value('nombre');

        // El nombre puede llevar el sufijo "- Acuerdo Kyrema" (product-configurator.component.ts),
        // no se muestra en el recibo del pago.
        if ($nombreProducto) {
            $nombreProducto = trim(str_replace(' - Acuerdo Kyrema', '', $nombreProducto));
        }

        $partes = array_filter([
            $nombreProducto,
            $data['subproducto_nombre'] ?? null,
            $data['codigo_producto'] ?? null,
        ]);
        $partesTexto = implode(' ', array_map(fn ($p) => '"' . $p . '"', $partes));

        $descripcion = "Recibo del certificado de {$partesTexto} con cobertura de "
            . $this->formatearFechaCorta($data['fecha_inicio'] ?? null) . " a "
            . $this->formatearFechaCorta($data['fecha_fin'] ?? null);

        Log::info($descripcion);

        // 1) Order (único). Usa tu helper/modelo actual.
        $order = substr(now()->format('ymdHis') . rand(10,99), 0, 12);

        // 2) Crea tu pago local (usa tus modelos/estados)
        $pago = Pago::create([
            'referencia'   => $data['referencia'] ?? null,
            'tipo_pago'    => 'tarjeta',
            'monto'        => $data['amount'] / 100,
            'amount_cents' => $data['amount'],
            'currency'     => '978',
            'fecha'        => now(),
            'estado'       => Pago::STATUS_PENDING,
            'letras_identificacion' => $data['letras_identificacion'],
            'producto_id'  => $data['producto_id'],
            'sociedad_id'  => $data['sociedad_id'] ?? null,
        ]);


        // Token de un solo uso que kyrema.org canjeará para pedir los campos Ds_* firmados.
        $token = Str::random(48);
        $ttlMinutes = (int) config('redsys.bridge_token_ttl_minutes', 15);

        PaymentGatewayLink::create([
            'pago_id'           => $pago->id,
            'gateway'           => 'redsys',
            'redsys_request_id' => null,
            'gateway_order_ref' => $order,
            'gateway_status'    => 'created',
            'access_token'      => $token,
            'token_expires_at'  => now()->addMinutes($ttlMinutes),
            'return_url'        => $data['return_url'],
            // 'descripcion' no existe como columna en pagos; se guarda aquí porque
            // form() (request posterior, la hace kyrema.org) ya no tiene la variable local.
            'gateway_payload'   => ['descripcion' => $descripcion],
        ]);

        $kyremaBase = rtrim(config('redsys.kyrema.base_url'), '/');
        $payPath    = '/' . ltrim(config('redsys.kyrema.pay_path'), '/');

        return response()->json([
            'redirectUrl'   => $kyremaBase . $payPath . '/' . $token,
            'merchantOrder' => $order,
            'pagoId'        => $pago->id,
        ]);
    }

    /**
     * Canjea el token de un solo uso y devuelve los campos Ds_* firmados.
     * La llama la página puente de kyrema.org (servidor a servidor), nunca el navegador del cliente.
     */
    public function form(Request $request, string $token)
    {
        $allowedIps = config('redsys.form_allowed_ips', []);
        if (!empty($allowedIps) && !in_array($request->ip(), $allowedIps, true)) {
            Log::warning('Redsys form: IP no autorizada', ['ip' => $request->ip()]);
            abort(403, 'Origen no autorizado.');
        }

        $link = PaymentGatewayLink::forGateway('redsys')->forAccessToken($token)->first();

        if (!$link || !$link->isAccessTokenUsable()) {
            return response()->json(['message' => 'Token inválido, caducado o ya utilizado.'], 410);
        }

        $order = $link->gateway_order_ref;
        $pago  = Pago::find($link->pago_id);

        if (!$pago) {
            return response()->json(['message' => 'Pago no encontrado.'], 404);
        }

        $kyremaBase   = rtrim(config('redsys.kyrema.base_url'), '/');
        $landingOkUrl = $kyremaBase . '/' . ltrim(config('redsys.kyrema.landing_ok_path'), '/')
            . '?' . http_build_query(['order' => $order, 'return_url' => $link->return_url]);
        $landingKoUrl = $kyremaBase . '/' . ltrim(config('redsys.kyrema.landing_ko_path'), '/')
            . '?' . http_build_query(['order' => $order, 'return_url' => $link->return_url]);

        $payload = $this->insite->buildIframePostPayload(
            amountCents: $pago->amount_cents,
            order: $order,
            description: $link->gateway_payload['descripcion'] ?? null,
            urlOk: $landingOkUrl,
            urlKo: $landingKoUrl,
        );

        $link->update(['token_used_at' => now()]);

        return response()->json([
            'action' => $payload['iframeAction'],  // action="..." del <form> hacia Redsys
            'inputs' => $payload['inputs'],         // 3 campos Ds_* para el <form>
        ]);
    }

    public function notify(Request $request)
    {
        // Crear client igual que en RedsysInsiteService
        $client = new RedsysClient(
            merchantCode: config('redsys.tpv.merchantCode'),
            secretKey: config('redsys.tpv.key'),
            terminal: (int) config('redsys.tpv.terminal', 1),
            environment: config('redsys.environment') === 'production'
                ? \Creagia\Redsys\Enums\Environment::Production
                : \Creagia\Redsys\Enums\Environment::Test,
        );

        $response = new RedsysResponse($client);

        try {
            // setParametersFromResponse() también puede lanzar (Ds_ErrorCode del banco,
            // o faltan campos Ds_*), así que tiene que quedar DENTRO del try: si no, una
            // notificación con esos casos provoca un 500 sin capturar y el pago se queda
            // en 'pendiente' para siempre porque nunca se llega a actualizar nada.
            $response->setParametersFromResponse($request->post());
            $notification = $response->checkResponse(); // ✔ firma válida
            $params = $notification->toArray();         // datos Redsys

            Log::info('Redsys OK: ', $params);

            DB::transaction(function () use ($params, $request) {
                // $params viene de NotificationParameters::toArray() (creagia/redsys-php),
                // que mapea los campos DS_* de Redsys a propiedades en camelCase
                // (DS_ORDER -> order, DS_RESPONSE -> responseCode, DS_AUTHORISATIONCODE ->
                // responseAuthorisationCode) — no conserva los nombres Ds_* originales.
                $order = $params['order'] ?? null;
                if (!$order) return;

                $link = PaymentGatewayLink::where('gateway', 'redsys')
                    ->where('gateway_order_ref', $order)
                    ->lockForUpdate()
                    ->first();

                if (!$link) return;

                $pago = Pago::lockForUpdate()->find($link->pago_id);
                if (!$pago) return;

                $ok = isset($params['responseCode']) && (int)$params['responseCode'] < 101;

                $pago->update([
                    'estado'          => $ok ? Pago::STATUS_PAID : Pago::STATUS_FAILED,
                    'auth_code'       => $params['responseAuthorisationCode'] ?? null,
                    'response_code'   => $params['responseCode'] ?? null,
                    'response_message' => $ok ? 'OK' : 'KO',
                ]);

                $link->update(['gateway_status' => $ok ? 'ok' : 'ko']);

                $this->resolverProductoPendiente($pago, $ok);
                RedsysRequestModel::create([
                    'uuid'               => (string) Str::uuid(),
                    'order_number'       => $order,
                    'response_code'      => $params['responseCode'] ?? null,
                    'auth_code'          => $params['responseAuthorisationCode'] ?? null,
                    'raw_parameters_b64' => $request->input('Ds_MerchantParameters'),
                    'signature'          => $request->input('Ds_Signature'),
                    'valid_signature'    => true,
                    'status'             => 'received',
                ]);
            });
        } catch (DeniedRedsysPaymentResponseException $e) {
            // La firma SÍ es válida (esta excepción la lanza checkResponse() después de
            // comprobarla): el banco ha rechazado el pago. Nos podemos fiar del pedido,
            // así que lo marcamos como fallido en vez de dejarlo colgado en 'pendiente'.
            logger()->warning('Redsys KO (rechazado por el banco): ' . $e->getMessage(), $request->post());
            $this->marcarPagoRechazado($response, $request);
        } catch (ErrorRedsysResponseException | InvalidRedsysResponseException $e) {
            // Ds_ErrorCode del banco, o firma/campos inválidos (p.ej. Signatures does not
            // match). Aquí NO nos fiamos de los datos para tocar el pago, pero dejamos
            // registrado el detalle completo: esta es la excepción que antes tumbaba la
            // petición con un 500 y dejaba el pago en 'pendiente' para siempre sin dejar
            // ni rastro en logs ni en redsys_requests.
            logger()->error('Redsys notify: respuesta inválida - ' . $e->getMessage(), [
                'exception' => get_class($e),
                'post'      => $request->post(),
            ]);
        } catch (\Throwable $e) {
            // Red de seguridad: cualquier otro fallo no debe volver a devolver un 500 a
            // Redsys (eso hace que el pago se quede huérfano). Se registra para investigar.
            logger()->error('Redsys notify: excepción no controlada - ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace'     => $e->getTraceAsString(),
                'post'      => $request->post(),
            ]);
        }

        // Redsys sólo necesita un 200 OK para dejar de reenviar
        return response('OK', 200);
    }

    /**
     * checkResponse() lanza DeniedRedsysPaymentResponseException DESPUÉS de validar la
     * firma, así que $response->parameters ya contiene datos verificados aunque el pago
     * haya sido rechazado. Los usamos para marcar el pago como fallido en vez de dejarlo
     * en 'pendiente' indefinidamente.
     */
    private function marcarPagoRechazado(RedsysResponse $response, Request $request): void
    {
        $order = $response->parameters->order ?? null;
        if (!$order) return;

        DB::transaction(function () use ($order, $response, $request) {
            $link = PaymentGatewayLink::where('gateway', 'redsys')
                ->where('gateway_order_ref', $order)
                ->lockForUpdate()
                ->first();
            if (!$link) return;

            $pago = Pago::lockForUpdate()->find($link->pago_id);
            if (!$pago) return;

            $pago->update([
                'estado'           => Pago::STATUS_FAILED,
                'auth_code'        => $response->parameters->responseAuthorisationCode ?? null,
                'response_code'    => $response->parameters->responseCode ?? null,
                'response_message' => 'KO',
            ]);

            $link->update(['gateway_status' => 'ko']);

            $this->resolverProductoPendiente($pago, false);
            RedsysRequestModel::create([
                'uuid'               => (string) Str::uuid(),
                'order_number'       => $order,
                'response_code'      => $response->parameters->responseCode ?? null,
                'auth_code'          => $response->parameters->responseAuthorisationCode ?? null,
                'raw_parameters_b64' => $request->input('Ds_MerchantParameters'),
                'signature'          => $request->input('Ds_Signature'),
                'valid_signature'    => true,
                'status'             => 'received',
            ]);
        });
    }

    /**
     * El producto y sus anexos se crearon con pendiente_pago=true ANTES de ir a Redsys
     * (ver client-product-form: iniciarPagoConTarjeta), porque el certificado no se puede
     * generar hasta que el pago esté confirmado y el navegador ya no está en esta sesión.
     * Si el cobro fue OK, se activan; si fue KO, nunca llegaron a ser reales y se borran.
     */
    private function resolverProductoPendiente(Pago $pago, bool $ok): void
    {
        if (!$pago->producto_id || !$pago->letras_identificacion) {
            return;
        }

        $tabla = $this->productoTableResolver->tableName($pago->letras_identificacion);
        if (!$tabla) {
            Log::warning('Redsys notify: no se pudo resolver la tabla de producto', [
                'pago_id' => $pago->id,
                'letras_identificacion' => $pago->letras_identificacion,
            ]);
            return;
        }

        $anexoTablas = $this->productoTableResolver->anexoTableNames($pago->letras_identificacion);

        if ($ok) {
            if (Schema::hasColumn($tabla, 'pendiente_pago')) {
                DB::table($tabla)->where('id', $pago->producto_id)->update(['pendiente_pago' => false]);
            }
            foreach ($anexoTablas as $anexoTabla) {
                if (Schema::hasColumn($anexoTabla, 'pendiente_pago')) {
                    DB::table($anexoTabla)->where('producto_id', $pago->producto_id)->update(['pendiente_pago' => false]);
                }
            }
        } else {
            foreach ($anexoTablas as $anexoTabla) {
                DB::table($anexoTabla)->where('producto_id', $pago->producto_id)->delete();
            }
            DB::table($tabla)->where('id', $pago->producto_id)->delete();
        }
    }

    /**
     * Consultada por la página /pago/resultado en Angular al volver de kyrema.org.
     * Nunca se fía del ?status= de la URL: la fuente de verdad es notify(), ya aplicada aquí.
     */
    public function orderStatus(Request $request, string $order)
    {
        $link = PaymentGatewayLink::forGateway('redsys')->forOrderRef($order)->first();

        if (!$link) {
            return response()->json(['message' => 'Pago no encontrado.'], 404);
        }

        $pago = Pago::find($link->pago_id);
        if (!$pago) {
            return response()->json(['message' => 'Pago no encontrado.'], 404);
        }

        return response()->json([
            'estado'      => $pago->estado,
            'producto_id' => $pago->producto_id,
            'letras_identificacion' => $pago->letras_identificacion,
        ]);
    }
}
