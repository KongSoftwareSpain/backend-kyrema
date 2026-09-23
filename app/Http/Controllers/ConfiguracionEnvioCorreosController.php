<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionEnvioCorreos;
use Illuminate\Http\Request;

class ConfiguracionEnvioCorreosController extends Controller
{
    /**
     * Interruptor maestro de correo: afecta a toda la aplicación, no a una
     * sociedad concreta, así que solo la sociedad admin puede verlo/tocarlo.
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

        return response()->json(ConfiguracionEnvioCorreos::actual());
    }

    public function update(Request $request)
    {
        if (!$this->autorizarAdmin($request)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $datos = $request->validate([
            'activo' => ['required', 'boolean'],
        ]);

        $config = ConfiguracionEnvioCorreos::first();

        if ($config) {
            $config->update($datos);
        } else {
            $config = ConfiguracionEnvioCorreos::create($datos);
        }

        return response()->json($config);
    }
}
