<?php

namespace App\Http\Controllers\Payments;

use Illuminate\Http\Response;
use Creagia\LaravelRedsys\Request as RedsysRequest;

class RedsysBridgeController
{
    public function ok(string $uuid): Response
    {
        $req = RedsysRequest::where('uuid', $uuid)->first(); // puede ser null si estás en Local Gateway
        $payload = [
            'type'   => 'REDSYS_BRIDGE',
            'status' => 'ok',
            'order'  => $req?->order_number ?? null,
            'pagoId' => $req?->model_id ?? null, // si asociaste el Pago con associateWithModel
        ];

        return $this->scriptResponse($payload);
    }

    public function ko(string $uuid): Response
    {
        $req = RedsysRequest::where('uuid', $uuid)->first();
        $payload = [
            'type'   => 'REDSYS_BRIDGE',
            'status' => 'ko',
            'order'  => $req?->order_number ?? null,
            'pagoId' => $req?->model_id ?? null,
        ];

        return $this->scriptResponse($payload);
    }

    private function scriptResponse(array $payload): Response
    {
        // JSON seguro para incrustar
        $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        // Enviamos un postMessage al padre y no mostramos nada en pantalla
        $html = <<<HTML
            <!doctype html>
            <meta charset="utf-8">
            <script>
            try {
                // Enviamos al parent (tu Angular)
                window.parent.postMessage($json, "*");
            } catch (e) {}
            </script>
            HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
