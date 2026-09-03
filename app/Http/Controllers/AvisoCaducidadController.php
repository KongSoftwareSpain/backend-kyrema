<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionAvisoCaducidad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AvisoCaducidadController extends Controller
{
    /**
     * Solo la sociedad admin puede ver/editar esta configuración: los avisos
     * de caducidad afectan a todas las sociedades, no es algo por sociedad.
     */
    private function autorizarAdmin(Request $request): bool
    {
        $comercial = $request->user();
        return $comercial && $comercial->id_sociedad == env('SOCIEDAD_ADMIN_ID', 1);
    }

    public function show(Request $request)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        return response()->json(ConfiguracionAvisoCaducidad::actual());
    }

    public function update(Request $request)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $datos = $request->validate([
            'dias_aviso' => ['required', 'array', 'min:1'],
            'dias_aviso.*' => ['integer', 'min:1', 'max:365'],
            'activo' => ['required', 'boolean'],
        ]);

        // Tabla de una sola fila.
        $config = ConfiguracionAvisoCaducidad::first();

        if ($config) {
            $config->update($datos);
        } else {
            $config = ConfiguracionAvisoCaducidad::create($datos);
        }

        return response()->json($config);
    }

    /**
     * Histórico de avisos ya enviados (tabla avisos_caducidad), con el
     * nombre/email del comercial al que se le mandó cada uno.
     */
    public function historial(Request $request)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $porPagina = (int) $request->query('por_pagina', 25);

        $historial = DB::table('avisos_caducidad')
            ->leftJoin('comercial', 'avisos_caducidad.comercial_id', '=', 'comercial.id')
            ->select([
                'avisos_caducidad.id',
                'avisos_caducidad.letras_identificacion',
                'avisos_caducidad.producto_id',
                'avisos_caducidad.dias_aviso',
                'avisos_caducidad.fecha_aviso_enviado',
                'comercial.id as comercial_id',
                'comercial.nombre as comercial_nombre',
                'comercial.email as comercial_email',
            ])
            ->orderByDesc('avisos_caducidad.fecha_aviso_enviado')
            ->paginate($porPagina);

        return response()->json($historial);
    }

    /**
     * Envía un email de prueba con el mismo formato que EnviarAvisosVencimientoCommand,
     * para comprobar que el envío de correo funciona sin esperar a un vencimiento real.
     */
    public function enviarPrueba(Request $request)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $datos = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            Mail::raw(
                'Este es un correo de prueba de los avisos de caducidad de pólizas. Si lo has recibido, el envío de correo funciona correctamente.',
                function ($mail) use ($datos) {
                    $mail->to($datos['email'])->subject('Prueba de aviso de vencimiento de póliza');
                }
            );
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al enviar el correo de prueba: ' . $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Correo de prueba enviado correctamente.']);
    }
}
