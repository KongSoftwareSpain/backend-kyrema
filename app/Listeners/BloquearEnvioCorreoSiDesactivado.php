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
 */
class BloquearEnvioCorreoSiDesactivado
{
    public function handle(MessageSending $event): bool
    {
        if (ConfiguracionEnvioCorreos::actual()->activo) {
            return true;
        }

        Log::info('Envío de correo bloqueado: interruptor maestro desactivado.', [
            'to' => $event->message->getTo(),
            'subject' => $event->message->getSubject(),
        ]);

        return false;
    }
}
