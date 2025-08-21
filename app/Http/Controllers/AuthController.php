<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Comercial;
use App\Models\Socio;
use Illuminate\Support\Facades\Hash;

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

        if(!$comercial) {
            return response()->json(['error' => 'El usuario no existe.'], 404);
        }

        if (!Hash::check($credentials['contraseña'], $comercial->contraseña)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Generar el token
        $token = $comercial->createToken('comercial')->plainTextToken;

        // Retornar la información del comercial junto con el token
        return response()->json([
            'comercial' => $comercial,
            'token' => $token
        ], 200);
    }

    /**
     * Método para iniciar sesión de comerciales.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function loginSocio(Request $request)
    {
        $credentials = $request->only('usuario', 'contraseña');
        $categoria = $request->input('categoria');

        // Buscar al socio por su usuario (email) y categoría
        $socio = Socio::where('email', $credentials['usuario'])
            ->where('categoria_id', $categoria)
            ->first();

        if (!$socio) {
            return response()->json(['error' => 'El usuario no existe o la categoría es incorrecta.'], 422);
        }


        if (!Hash::check($credentials['contraseña'], $socio->password)) {
            return response()->json(['error' => 'La contraseña es incorrecta.'], 422);
        }

        // Generar el token
        $token = $socio->createToken('socio')->plainTextToken;

        // Retornar la información del socio junto con el token
        return response()->json([
            'socio' => $socio,
            'token' => $token
        ], 200);
    }

    public function registerSocio(Request $request)
    {
        $messages = [
            'email.unique' => 'Este correo electrónico ya está registrado.',
            'phone.required' => 'El teléfono es obligatorio.',
            'phone.unique' => 'Este teléfono ya está registrado.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'categoria_id.exists' => 'La categoría seleccionada no es válida.',
        ];

        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'apellido_1' => 'nullable|string|max:255',
            'apellido_2' => 'nullable|string|max:255',
            'phone' => 'required|string|max:255|unique:socios,telefono',
            'birthDate' => 'required|date',
            'email' => 'required|string|email|max:255|unique:socios,email',
            'password' => 'required|string|min:8',
            'categoria_id' => 'required|integer|exists:categorias,id',
        ], $messages);

        $socio = new Socio();
        $socio->nombre_socio = $data['nombre'];
        $socio->apellido_1 = $data['apellido_1'] ?? null;
        $socio->apellido_2 = $data['apellido_2'] ?? null;
        $socio->telefono = $data['phone'];
        $socio->fecha_de_nacimiento = $data['birthDate'];
        $socio->email = $data['email'];
        $socio->password = Hash::make($data['password']);
        $socio->categoria_id = $data['categoria_id'];

        $socio->save();

        // Generar el token
        $token = $socio->createToken('socio')->plainTextToken;

        return response()->json([
            'socio' => $socio,
            'token' => $token
        ], 201);
    }
}
