<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function createPayment(Request $request)
    {
        try{
            $orderId = uniqid();
            $amount = 1000;


            $merchantCode = env('REDSYS_MERCHANT_CODE');
            $secretKey = env('REDSYS_SECRET_KEY');
            $terminal = env('REDSYS_TERMINAL');
            $currency = '978'; // Código ISO para euros
            $transactionType = '0'; // Para compra normal

            // Datos que vas a enviar a Redsys
            $params = [
                'Ds_Merchant_Amount' => $amount,
                'Ds_Merchant_Order' => $orderId,
                'Ds_Merchant_MerchantCode' => $merchantCode,
                'Ds_Merchant_Currency' => $currency,
                'Ds_Merchant_TransactionType' => $transactionType,
                'Ds_Merchant_Terminal' => $terminal,
                'Ds_Merchant_UrlOK' => env('FRONTEND_SUCCESS_URL'),
                'Ds_Merchant_UrlKO' => env('FRONTEND_FAILED_URL'),
            ];

            
            // Generar la firma
            $signature = $this->generateSignature($params, $secretKey);

            // URL del servicio Redsys
            $redsysUrl = 'https://sis-t.redsys.es:25443/sis/realizarPago'; // Sandbox URL

            // Devuelves los datos para redirigir al formulario de Redsys
            return response()->json([
                'redsysUrl' => $redsysUrl,
                'params' => $params,
                'signature' => $signature,
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function generateSignature($params, $secretKey)
    {
        $payload = base64_encode(json_encode($params));
        $key = base64_decode($secretKey);
        $signature = hash_hmac('sha256', $payload, $key, true);
        return base64_encode($signature);
    }
}