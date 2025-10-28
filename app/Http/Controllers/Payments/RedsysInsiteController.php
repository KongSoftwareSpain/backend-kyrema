<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payments\Pago;
use App\Models\Payments\PaymentGatewayLink;
use App\Models\TipoProducto;
use Creagia\LaravelRedsys\Request as RedsysRequestModel;
use App\Services\Payments\RedsysInsiteService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Creagia\Redsys\Exceptions\DeniedRedsysPaymentResponseException;
use Creagia\Redsys\RedsysClient;
use Creagia\Redsys\RedsysResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RedsysInsiteController extends Controller
{
    public function __construct(private RedsysInsiteService $insite) {}

    public function start(Request $request)
    {
        $data = $request->validate([
            'amount'      => 'required|integer|min:1',  // céntimos
            'description' => 'nullable|string|max:255',
            'letras_identificacion' => 'nullable|string|max:32',
            'sociedad_id' => 'nullable|integer',
            'referencia'  => 'nullable|string|max:64',
            'fecha_inicio' => 'nullable|string',
            'fecha_fin'   => 'nullable|string',
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

        $descripcion = "Pago con tarjeta de " . $nombreProducto . " con cobertura de " . $data['fecha_inicio'] . " a " . $data['fecha_fin'];

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
            'letras_identificacion' => $data['letras_identificacion'] ?? null,
            'sociedad_id'  => $data['sociedad_id'] ?? null,
            'descripcion'  => $descripcion,
        ]);


        PaymentGatewayLink::create([
            'pago_id'           => $pago->id,
            'gateway'           => 'redsys',
            'redsys_request_id' => null,
            'gateway_order_ref' => $order,
            'gateway_status'    => 'created',
        ]);

        // 3) Construir payload “InSite” con redsys-php
        $payload = $this->insite->buildIframePostPayload(
            amountCents: $data['amount'],
            order: $order,
            description: $data['description'] ?? null
        );

        return response()->json([
            'iframeAction'  => $payload['iframeAction'],  // URL de Redsys (sis/sis-t)
            'inputs'        => $payload['inputs'],         // 3 campos Ds_* para el <form>
            'merchantOrder' => $order,
            'pagoId'        => $pago->id,
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
        $response->setParametersFromResponse($request->post());

        try {
            $notification = $response->checkResponse(); // ✔ firma válida
            $params = $notification->toArray();         // datos Redsys

            Log::info('Redsys OK: ', $params);

            DB::transaction(function () use ($params, $request) {
                $order = $params['Ds_Order'] ?? null;
                if (!$order) return;

                $link = PaymentGatewayLink::where('gateway', 'redsys')
                    ->where('gateway_order_ref', $order)
                    ->lockForUpdate()
                    ->first();

                if (!$link) return;

                $pago = Pago::lockForUpdate()->find($link->pago_id);
                if (!$pago) return;

                $ok = isset($params['Ds_Response']) && (int)$params['Ds_Response'] < 101;

                $pago->update([
                    'estado'          => $ok ? Pago::STATUS_PAID : Pago::STATUS_FAILED,
                    'auth_code'       => $params['Ds_AuthorisationCode'] ?? null,
                    'response_code'   => $params['Ds_Response'] ?? null,
                    'response_message' => $ok ? 'OK' : 'KO',
                ]);

                $link->update(['gateway_status' => $ok ? 'ok' : 'ko']);
                RedsysRequestModel::create([
                    'uuid'               => (string) Str::uuid(),
                    'order_number'       => $order,
                    'response_code'      => $params['Ds_Response'] ?? null,
                    'auth_code'          => $params['Ds_AuthorisationCode'] ?? null,
                    'raw_parameters_b64' => $request->input('Ds_MerchantParameters'),
                    'signature'          => $request->input('Ds_Signature'),
                    'valid_signature'    => true,
                    'status'             => 'received',
                ]);
            });
        } catch (DeniedRedsysPaymentResponseException $e) {
            // firma incorrecta o pago rechazado
            logger()->warning('Redsys KO: ' . $e->getMessage(), $request->post());
        }

        // Redsys sólo necesita un 200 OK para dejar de reenviar
        return response('OK', 200);
    }
}
