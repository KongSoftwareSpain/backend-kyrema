<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payments\Pago;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Creagia\LaravelRedsys\RequestBuilder;
use Creagia\Redsys\Support\RequestParameters;
use Creagia\Redsys\Enums\{TransactionType, Currency, PayMethod};
use Symfony\Component\HttpFoundation\Response;
use App\Models\Payments\PaymentGatewayLink;
use Illuminate\Support\Facades\DB;
use Creagia\LaravelRedsys\Request as RedsysRequestModel;


class RedsysController extends Controller
{

    public function insiteStart(Request $request)
    {
        $data = $request->validate([
            'amount'                => 'required|integer|min:1', // céntimos
            'description'           => 'nullable|string|max:255',
            'producto_id'           => 'nullable|integer',
            'letras_identificacion' => 'nullable|string|max:50',
            'sociedad_id'           => 'nullable|integer',
        ]);

        // 1) Crear tu pago local
        $pago = Pago::create([
            'referencia'            => (string) Str::uuid(),
            'tipo_pago'             => 'tarjeta',
            'monto'                 => $data['amount'] / 100,
            'amount_cents'          => $data['amount'],
            'currency'              => '978',
            'fecha'                 => now(),
            'estado'                => Pago::STATUS_PENDING,
            'sociedad_id'           => $data['sociedad_id'] ?? null,
            'letras_identificacion' => $data['letras_identificacion'] ?? null,
        ]);

        // 2) Reservar el Ds_Order (sin crear aún la request)
        $merchantOrder = (string) RedsysRequestModel::getNextOrderNumber();

        // 3) (opcional) Guardar link preliminar con el order
        PaymentGatewayLink::create([
            'pago_id'           => $pago->id,
            'gateway'           => 'redsys',
            'redsys_request_id' => null,                // se completará tras redirect()/notificación
            'gateway_order_ref' => $merchantOrder,
            'gateway_status'    => 'created',
        ]);

        // 4) Devolver URL del iframe + order al front
        $iframeUrl = route('redsys.insite.iframe', [
            'pago'  => $pago->id,
            'order' => $merchantOrder,
        ]);

        return response()->json([
            'iframeUrl'     => $iframeUrl,
            'merchantOrder' => $merchantOrder,
            'pagoId'        => $pago->id,
        ]);
    }

    /**
     * 2) HTML con form auto-POST a Redsys (se carga dentro del iframe).
     *    No es Blade: devolvemos el HTML que genera el paquete.
     */
    public function insiteIframe(Request $request, Pago $pago)
    {
        $order = (string) $request->query('order', '');

        // 1) Construir el builder y asociarlo al modelo local
        $builder = $pago->createRedsysRequest(
            productDescription: 'Pago ' . $pago->id,
            payMethod: PayMethod::Card
        )->associateWithModel($pago);

        // 2) Forzar el mismo Ds_Order que reservaste en start
        if ($order !== '') {
            $builder->requestParameters->order = $order;
        }

        // 3) Devolver el HTML con el auto-POST a Redsys (esto crea redsys_requests)
        return $builder->redirect();
    }

    /**
     * 3) Autorización REST con el idOper que te devuelve Redsys (inSite).
     *    IMPORTANTE: usar el MISMO merchantOrder que generaste al iniciar el iframe.
     */
    public function insiteConfirm(Request $request)
    {
        $data = $request->validate([
            'pagoId'        => 'required|integer|exists:pagos,id',
            'merchantOrder' => 'required|string',
            'idOper'        => 'required|string',
        ]);

        $pago = Pago::findOrFail($data['pagoId']);

        // Construimos la operación REST con los parámetros REALES del paquete
        // (RequestBuilder + RequestParameters + ->post())
        $redsysRequest = RequestBuilder::newRequest(
            new RequestParameters(
                amountInCents: $pago->amount_cents ?? (int) round(($pago->monto ?? 0) * 100),
                currency: Currency::EUR,
                order: $data['merchantOrder'],              // Debe coincidir con el del iframe
                transactionType: TransactionType::Autorizacion,
                // Campo clave de InSite: OperID (idOper) para autorizar sin PAN/CVV
                idOper: $data['idOper'],
            )
        )->associateWithModel($pago);

        // Enviamos la petición como REST (POST). El paquete gestiona firma y endpoint REST.
        // Si Redsys autoriza, el paquete disparará la notificación y ejecutará paidWithRedsys().
        $response = $redsysRequest->post(); // Devuelve el objeto de respuesta Redsys

        // Actualiza el link intermedio con trazas mínimas
        $order = $data['merchantOrder'];
        $link = PaymentGatewayLink::where('pago_id', $pago->id)
            ->where('gateway', 'redsys')
            ->latest('id')->first();

        if ($link) {
            $link->gateway_order_ref = $order;
            $link->mergePayload([
                'insiteConfirm' => [
                    'idOper' => $data['idOper'],
                    'raw'    => $response?->checkResponse() ?? [],
                ],
            ]);
            // Estado técnico provisional: el definitivo te llegará con la notificación
            $link->gateway_status = 'posted';
            $link->save();
        }

        // Puedes devolver el estado inmediato (el paquete emitirá eventos al llegar la notificación)
        return response()->json([
            'status'  => 'posted',
            'message' => 'Operación enviada a Redsys (REST). Esperando confirmación.',
        ]);
    }

    public function start(Request $request)
    {
        $data = $request->validate([
            'amount'               => 'required|integer|min:1', // céntimos
            'description'          => 'nullable|string|max:255',
            'producto_id'          => 'nullable|integer',
            'letras_identificacion' => 'nullable|string|max:50',
            'sociedad_id'          => 'nullable|integer',
        ]);

        // 1) Pago de dominio (limpio, sin campos de tarjeta)
        $pago = Pago::create([
            'referencia'           => (string) Str::uuid(),
            'tipo_pago'            => 'tarjeta',
            'monto'                => $data['amount'] / 100,
            'amount_cents'         => $data['amount'],
            'currency'             => '978',
            'fecha'                => now(),
            'estado'               => 'pending',
            'sociedad_id'          => $data['sociedad_id'] ?? null,
            'producto_id'          => $data['producto_id'] ?? null,
            'letras_identificacion' => $data['letras_identificacion'] ?? null,
        ]);

        // 2) Crea la request Redsys asociada al Pago (el paquete registrará el morph en redsys_requests)
        $req = $pago->createRedsysRequest(
            productDescription: $data['description'] ?? ('Pago ' . $pago->id),
            payMethod: PayMethod::Card,
            transactionType: TransactionType::Autorizacion,
            currency: Currency::EUR
        );

        // 3) Localiza la fila en redsys_requests recién creada (por el morph model_type/model_id)
        $redsysRequest = DB::table('redsys_requests')
            ->where('model_type', Pago::class)
            ->where('model_id', $pago->id)
            ->latest('id')
            ->first();

        // 4) Enlace en tabla intermedia (mínimo imprescindible)
        PaymentGatewayLink::create([
            'pago_id'             => $pago->id,
            'gateway'             => 'redsys',
            'redsys_request_id'   => $redsysRequest?->id,
            'gateway_order_ref'   => $redsysRequest?->order_number, // Ds_Order
            'gateway_status'      => 'created',
        ]);

        // 5) Devuelves a Angular la URL que servirá el auto-POST a Redsys
        return response()->json([
            'redirectUrl' => route('redsys.redirect', ['pago' => $pago->id]),
            'orderId'     => $redsysRequest?->order_number,
            'pagoId'      => $pago->id,
        ]);
    }

    public function redirect(string $order)
    {
        $pago = Pago::query()
            ->where('gateway', 'redsys')
            ->where('ds_order', $order)
            ->first();

        abort_unless($pago, Response::HTTP_NOT_FOUND);

        $params = new RequestParameters(
            transactionType: TransactionType::Autorizacion,
            productDescription: $pago->referencia ?: ('Pedido ' . $order),
            amountInCents: (int) $pago->amount_cents,
            currency: Currency::EUR,
            payMethods: PayMethod::Card
        );

        return RequestBuilder::newRequest($params)->redirect();
    }

    public function status(Pago $pago)
    {
        $link = $pago->gatewayLinks()->forGateway('redsys')->latest('id')->first();

        return response()->json([
            'estado'          => $pago->estado,               // pending|paid|failed
            'gateway_status'  => $link?->gateway_status,      // created|paid|failed
            'orderRef'        => $link?->gateway_order_ref,
            'updatedAt'       => $pago->updated_at,
        ]);
    }
}
