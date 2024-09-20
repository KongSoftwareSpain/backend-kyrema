<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\PaymentIntent;

class PaymentController extends Controller
{
    public function handlePayment(Request $request)
    {
        // Validar la entrada
        $request->validate([
            'paymentMethodId' => 'required|string',
            'amount' => 'required|integer', // Asegúrate de recibir el monto
        ]);

        // Establecer la clave secreta de Stripe
        Stripe::setApiKey(env('STRIPE_SECRET'));

        // Crear un PaymentIntent
        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $request->input('amount'), // Monto en centavos
                'currency' => 'usd', // Cambia esto según tus necesidades
                'payment_method' => $request->input('paymentMethodId'),
                'confirmation_method' => 'manual',
                'confirm' => true,
            ]);

            return response()->json($paymentIntent);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}