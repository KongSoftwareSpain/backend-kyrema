<?php

namespace App\Listeners;

use App\Models\Payments\PaymentGatewayLink;
use Creagia\LaravelRedsys\Events\RedsysUnsuccessfulEvent;
use Illuminate\Support\Facades\DB;

class OnRedsysFail
{
    public function handle(RedsysUnsuccessfulEvent $event): void
    {
        $rp   = $event->redsysPayment;
        $data = $event->errorMessage;

        $link = null;

        $redsysRequestId = null;
        if (is_object($rp) && property_exists($rp, 'id')) {
            $redsysRequestId = $rp->id;
        } else {
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

        if (! $link && ! empty($data['Ds_Order'])) {
            $link = PaymentGatewayLink::where('gateway', 'redsys')
                ->where('gateway_order_ref', $data['Ds_Order'])
                ->latest('id')->first();
        }

        if (! $link) {
            return;
        }

        $link->gateway_status    = 'failed';
        $link->gateway_order_ref = $data['Ds_Order'] ?? $link->gateway_order_ref;

        $payload = $link->gateway_payload ?: [];
        $link->gateway_payload = array_merge($payload, $data);

        $link->save();
    }
}
