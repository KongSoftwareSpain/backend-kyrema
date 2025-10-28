<?php

namespace App\Services\Payments;

use Creagia\Redsys\Enums\Currency;
use Creagia\Redsys\Enums\Environment;
use Creagia\Redsys\Enums\TransactionType;
use Creagia\Redsys\RedsysClient;
use Creagia\Redsys\RedsysRequest;
use Creagia\Redsys\Support\RequestParameters;
use DOMDocument;
use Illuminate\Support\Facades\Log;

class RedsysInsiteService
{
    private RedsysClient $client;

    public function __construct()
    {
        $env = match (config('redsys.environment')) {
            'production' => Environment::Production,
            'test'       => Environment::Test,
            'local'      => Environment::Custom, // (solo si usas el fake/local)
            default      => Environment::Test,
        };

        $this->client = new RedsysClient(
            merchantCode: config('redsys.tpv.merchantCode'),
            secretKey: config('redsys.tpv.key'),
            terminal: (int) config('redsys.tpv.terminal', 1),
            environment: $env,
            customBaseUrl: null,
        );
    }

    /**
     * Construye la petición de redirección y devuelve:
     *  - iframeAction: URL de Redsys (sis/sis-t)
     *  - inputs: [Ds_SignatureVersion, Ds_MerchantParameters, Ds_Signature]
     */
    public function buildIframePostPayload(int $amountCents, string $order, ?string $description = null): array
    {
        // 1) Parámetros de la petición
        $frontendBase = rtrim(config('redsys.frontend.base_url'), '/');
        $bridgePath   = '/' . ltrim(config('redsys.frontend.bridge_path'), '/');

        $urlOk = $frontendBase . $bridgePath . '?status=ok&order=' . urlencode($order);
        $urlKo = $frontendBase . $bridgePath . '?status=ko&order=' . urlencode($order);

        // merchantUrl = webhook del backend (route name del config)
        $merchantUrl = route('redsys.notify', [], true);

        Log::info('📡 merchantUrl', ['url' => $merchantUrl]);


        $parameters = new RequestParameters(
            amountInCents: $amountCents,
            order: $order,
            currency: Currency::EUR,
            transactionType: TransactionType::Autorizacion,
            merchantUrl: $merchantUrl,
            urlOk: $urlOk,
            urlKo: $urlKo,
            productDescription: $description,
        );

        // 2) Construir la request y obtener el formulario HTML de redirección
        //    (método documentado en redsys-php)
        $redsysRequest = RedsysRequest::create(
            redsysClient: $this->client,
            requestParameters: $parameters
        );

        $formHtml = $redsysRequest->getRedirectFormHtml(); // ← devuelve <form action="..."> con los 3 inputs
        // Doc: README de redsys-php muestra este método. :contentReference[oaicite:3]{index=3}

        // 3) Parsear el HTML para extraer action + hidden inputs
        [$action, $inputs] = $this->extractActionAndInputs($formHtml);

        return [
            'iframeAction' => $action,
            'inputs' => $inputs, // ['Ds_SignatureVersion'=>..., 'Ds_MerchantParameters'=>..., 'Ds_Signature'=>...]
        ];
    }

    private function extractActionAndInputs(string $html): array
    {
        $dom = new DOMDocument();
        // Evitar warnings por HTML minimalista
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $forms = $dom->getElementsByTagName('form');
        if ($forms->length === 0) {
            throw new \RuntimeException('No se encontró el formulario de redirección de Redsys.');
        }
        /** @var \DOMElement $form */
        $form = $forms->item(0);
        $action = $form->getAttribute('action');

        $inputs = [];
        foreach ($form->getElementsByTagName('input') as $input) {
            $name  = $input->getAttribute('name');
            $value = $input->getAttribute('value');
            if (in_array($name, ['Ds_SignatureVersion', 'Ds_MerchantParameters', 'Ds_Signature'], true)) {
                $inputs[$name] = $value;
            }
        }

        // Sanidad: asegurar que están los 3
        foreach (['Ds_SignatureVersion', 'Ds_MerchantParameters', 'Ds_Signature'] as $k) {
            if (!array_key_exists($k, $inputs)) {
                throw new \RuntimeException("Falta el campo {$k} en el formulario de Redsys.");
            }
        }

        return [$action, $inputs];
    }
}
