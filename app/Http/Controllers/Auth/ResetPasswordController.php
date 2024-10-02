<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Support\Facades\Password;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\Comercial;

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

    /**
     * Where to redirect users after resetting their password.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:8',
        ]);

        // Buscar al usuario en la tabla 'comercial'
        $user = Comercial::where('email', $request->email)->first();

        // Comprobar si el usuario existe y si el token es válido
        if (!$user) {
            return response()->json(['msg' => 'El usuario no existe.'], 400);
        }

        if(!Password::tokenExists($user, $request->token)){
            return response()->json(['msg' => 'El token no es válido.'], 400);
        }

        // Restablecer la contraseña
        $user->contraseña = Hash::make($request->password);
        $user->save();

        return response()->json(['msg' => 'Tu contraseña ha sido restablecida.']);
    }

}
