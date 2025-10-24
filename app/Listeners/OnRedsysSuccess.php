<?php

namespace App\Listeners;

use App\Models\Payments\PaymentGatewayLink;
use Creagia\LaravelRedsys\Events\RedsysSuccessfulEvent;
use Illuminate\Support\Facades\DB;

class OnRedsysSuccess
{
    public function handle(RedsysSuccessfulEvent $event): void
    {
        $rp   = $event->redsysPayment;     // objeto Request del paquete
        $data = $event->notificationData;  // array Ds_*

        // Intentamos localizar el link por redsys_request_id si podemos inferirlo;
        // si no, por Ds_Order (gateway_order_ref)
        $link = null;

        // A) Lookup por morph en redsys_requests → link
        $redsysRequestId = null;
        // intentamos obtener id según estructura conocida
        if (is_object($rp) && property_exists($rp, 'id')) {
            $redsysRequestId = $rp->id;
        } else {
            // fallback: buscamos la fila por Ds_Order
            $order = $data['Ds_Order'] ?? null;
            if ($order) {
                $row = DB::table('redsys_requests')->where('order_number', $order)->latest('id')->first();
                $redsysRequestId = $row?->id;
            }
        }

        if ($redsysRequestId) {
            $link = PaymentGatewayLink::where('gateway', 'redsys')
                ->where('redsys_request_id', $redsysRequestId)
                ->latest('id')->first();
        }

        // B) Si no hubo suerte, probar por gateway_order_ref (Ds_Order)
        if (! $link && ! empty($data['Ds_Order'])) {
            $link = PaymentGatewayLink::where('gateway', 'redsys')
                ->where('gateway_order_ref', $data['Ds_Order'])
                ->latest('id')->first();
        }

        if (! $link) {
            return; // nada que actualizar
        }

        // Estado técnico + trazas; NO toques 'pagos' aquí (ya lo hizo paidWithRedsys)
        $link->gateway_status    = 'paid';
        $link->gateway_order_ref = $data['Ds_Order'] ?? $link->gateway_order_ref;

        $payload = $link->gateway_payload ?: [];
        $link->gateway_payload = array_merge($payload, $data);

        $link->save();
    }
}
