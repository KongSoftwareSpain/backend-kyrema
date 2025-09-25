<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Support\Facades\Password;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;    
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;

    public function reset(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|confirmed|min:8',
            'token' => 'required',
            'categoria' => 'nullable|string' // o integer si esperas un id numérico
        ]);

        $credentials = $request->only('email', 'password', 'password_confirmation', 'token');

        // elegir broker en función de la categoria
        if ($request->filled('categoria')) {
            // MODO SOCIO
            $response = Password::broker('socios')->reset(
                $credentials,
                function ($user, $password) {
                    $user->forceFill([
                        'password' => Hash::make($password),
                        'remember_token' => Str::random(60),
                    ])->save();
                }
            );
        } else {
            // MODO COMERCIAL
            $response = Password::broker('comerciales')->reset(
                $credentials,
                function ($user, $password) {
                    $user->forceFill([
                        'password' => Hash::make($password),
                        'remember_token' => Str::random(60),
                    ])->save();
                }
            );
        }

        return $response === Password::PASSWORD_RESET
            ? response()->json(['msg' => 'Contraseña restablecida correctamente.'])
            : response()->json(['msg' => 'El restablecimiento de contraseña ha fallado.'], 422);
    }
}
