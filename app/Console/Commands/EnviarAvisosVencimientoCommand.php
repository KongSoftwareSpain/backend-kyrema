<?php

namespace App\Console\Commands;

use App\Models\ConfiguracionEnvioCorreos;
use App\Models\PerfilEnvioAviso;
use App\Services\Avisos\EnvioAvisosService;
use Illuminate\Console\Command;

class EnviarAvisosVencimientoCommand extends Command
{
    protected $signature = 'caducidad:enviar-avisos';
    protected $description = 'Envía avisos de caducidad de pólizas a comerciales según los perfiles de envío activos (perfiles_envio_avisos)';

    public function handle(EnvioAvisosService $envioAvisosService)
    {
        if (!ConfiguracionEnvioCorreos::actual()->activo) {
            $this->info('Envío de correo desactivado por el interruptor maestro. No se envía nada.');
            return;
        }

        $perfiles = PerfilEnvioAviso::where('activo', true)->get();

        if ($perfiles->isEmpty()) {
            $this->info('No hay perfiles de envío activos.');
            return;
        }

        $totalEnviados = 0;
        $totalFallidos = 0;

        foreach ($perfiles as $perfil) {
            $resultado = $envioAvisosService->ejecutarPerfil($perfil, forzado: false);

            $this->info("Perfil \"{$perfil->nombre}\": {$resultado['enviados']} enviados, {$resultado['fallidos']} fallidos.");

            $totalEnviados += $resultado['enviados'];
            $totalFallidos += $resultado['fallidos'];
        }

        $this->info("✅ Total enviados: $totalEnviados — Fallidos: $totalFallidos");
    }
}
