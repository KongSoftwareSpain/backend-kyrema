<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AvisoCaducidadController extends Controller
{
    /**
     * Solo la sociedad admin puede ver esta información: los avisos de
     * caducidad afectan a todas las sociedades, no es algo por sociedad.
     */
    private function autorizarAdmin(Request $request): bool
    {
        $comercial = $request->user();
        return $comercial && $comercial->id_sociedad == env('SOCIEDAD_ADMIN_ID', 1);
    }

    /**
     * Histórico de avisos ya enviados (tabla avisos_caducidad), con el
     * nombre/email del comercial al que se le mandó cada uno y el perfil que
     * lo generó. Admite filtrar por perfil_id.
     */
    public function historial(Request $request)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $porPagina = (int) $request->query('por_pagina', 25);

        $query = DB::table('avisos_caducidad')
            ->leftJoin('comercial', 'avisos_caducidad.comercial_id', '=', 'comercial.id')
            ->leftJoin('perfiles_envio_avisos', 'avisos_caducidad.perfil_id', '=', 'perfiles_envio_avisos.id')
            ->select([
                'avisos_caducidad.id',
                'avisos_caducidad.letras_identificacion',
                'avisos_caducidad.producto_id',
                'avisos_caducidad.dias_aviso',
                'avisos_caducidad.forzado',
                'avisos_caducidad.fecha_aviso_enviado',
                'comercial.id as comercial_id',
                'comercial.nombre as comercial_nombre',
                'comercial.email as comercial_email',
                'perfiles_envio_avisos.id as perfil_id',
                'perfiles_envio_avisos.nombre as perfil_nombre',
            ])
            ->orderByDesc('avisos_caducidad.fecha_aviso_enviado');

        if ($request->filled('perfil_id')) {
            $query->where('avisos_caducidad.perfil_id', $request->query('perfil_id'));
        }

        return response()->json($query->paginate($porPagina));
    }

    /**
     * Log de envíos fallidos (tabla avisos_caducidad_fallidos): comercial sin
     * email, o excepción al enviar el correo.
     */
    public function fallidos(Request $request)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $porPagina = (int) $request->query('por_pagina', 25);

        $query = DB::table('avisos_caducidad_fallidos')
            ->leftJoin('perfiles_envio_avisos', 'avisos_caducidad_fallidos.perfil_id', '=', 'perfiles_envio_avisos.id')
            ->select([
                'avisos_caducidad_fallidos.id',
                'avisos_caducidad_fallidos.letras_identificacion',
                'avisos_caducidad_fallidos.producto_id',
                'avisos_caducidad_fallidos.comercial_id',
                'avisos_caducidad_fallidos.email_destino',
                'avisos_caducidad_fallidos.motivo_error',
                'avisos_caducidad_fallidos.forzado',
                'avisos_caducidad_fallidos.created_at',
                'perfiles_envio_avisos.id as perfil_id',
                'perfiles_envio_avisos.nombre as perfil_nombre',
            ])
            ->orderByDesc('avisos_caducidad_fallidos.created_at');

        if ($request->filled('perfil_id')) {
            $query->where('avisos_caducidad_fallidos.perfil_id', $request->query('perfil_id'));
        }

        return response()->json($query->paginate($porPagina));
    }

    /**
     * Envía un email de prueba con el mismo formato que los avisos de
     * caducidad, para comprobar que el envío de correo funciona (y que el
     * interruptor maestro se respeta) sin esperar a un vencimiento real.
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
