<?php

namespace App\Listeners;

use App\Models\ConfiguracionEnvioCorreos;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * Interruptor maestro de correo: si está desactivado, cancela CUALQUIER envío
 * de la aplicación (Mail::raw, Mailables, Notifications por canal 'mail'),
 * porque todos pasan por el Mailer y disparan este evento antes de enviar.
 *
 * Devolver false desde un listener de MessageSending cancela el envío
 * (Illuminate\Mail\Mailer::shouldSendMessage()).
 *
 * Excepción: la recuperación de contraseña (App\Notifications\CustomResetPassword)
 * queda fuera del interruptor, para no dejar a nadie sin forma de recuperar
 * su cuenta mientras se desarrolla/prueba el resto del sistema de correo.
 */
class BloquearEnvioCorreoSiDesactivado
{
    public function handle(MessageSending $event): bool
    {
        if (ConfiguracionEnvioCorreos::actual()->activo) {
            return true;
        }

        if ($event->message->getHeaders()->has('X-Canama-Password-Reset')) {
            return true;
        }

        Log::info('Envío de correo bloqueado: interruptor maestro desactivado.', [
            'to' => $event->message->getTo(),
            'subject' => $event->message->getSubject(),
        ]);

        return false;
    }
}
