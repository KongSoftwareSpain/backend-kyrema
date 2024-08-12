<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Comercial;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    /**
     * Método para iniciar sesión de comerciales.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $credentials = $request->only('usuario', 'contraseña');

        // Buscar al comercial por su usuario (puede ser un email)
        $comercial = Comercial::where('usuario', $credentials['usuario'])->first();

        if (!$comercial || !Hash::check($credentials['contraseña'], $comercial->contraseña)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Generar el token JWT para el comercial
        $token = JWTAuth::fromUser($comercial);

        // Retornar la información del comercial junto con el token
        return response()->json([
            'comercial' => $comercial,
            'token' => $token
        ], 200);
    }
}
