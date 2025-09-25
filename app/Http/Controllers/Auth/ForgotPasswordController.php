<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Support\Facades\Password;
use Illuminate\Http\Request;
use App\Models\Comercial;
use App\Models\Socio;

class ForgotPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    use SendsPasswordResetEmails;


    public function sendResetLinkEmail(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email:rfc,dns'],
            // 'categoria' puede venir o no; no validamos valor, solo presencia
            'categoria' => ['nullable'],
        ]);

        $email = $request->string('email')->toString();

        if ($request->filled('categoria')) {
            // MODO SOCIO (si categoria viene no-nula)
            if (!Socio::where('email', $email)->exists()) {
                return response()->json(['msg' => 'El email no está registrado como socio.'], 404);
            }

            $response = Password::broker('socios')->sendResetLink(['email' => $email]);
        } else {
            // MODO COMERCIAL (si categoria es null)
            if (!Comercial::where('email', $email)->exists()) {
                return response()->json(['msg' => 'El email no está registrado como comercial.'], 404);
            }

            $response = Password::broker('comerciales')->sendResetLink(['email' => $email]);
        }

        return $response === Password::RESET_LINK_SENT
            ? response()->json(['msg' => 'Se ha enviado el enlace de restablecimiento de contraseña.'], 200)
            : response()->json(['msg' => 'No se pudo enviar el enlace de restablecimiento de contraseña.'], 422);
    }
}
