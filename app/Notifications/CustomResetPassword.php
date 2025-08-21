<?php

namespace App\Notifications;


use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use App\Models\Socio;

class CustomResetPassword extends ResetPasswordNotification
{
    /**
     * Build the mail representation of the notification.
     *
     * @param  string  $token
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $frontend = rtrim(config('app.reset_pwd_url'), '/');
        $token = $this->token;
        $email = urlencode($notifiable->email);

        // Si es Socio y tiene categoria_id => /login/{categoria}/password/reset/{token}
        // Si no => /login/password/reset/{token}
        $path = ($notifiable instanceof Socio && !empty($notifiable->categoria_id))
            ? "/{$notifiable->categoria_id}/password/reset/{$token}"
            : "/password/reset/{$token}";

        $url = "{$frontend}{$path}?email={$email}";

        return (new MailMessage)
            ->subject('Restablecimiento de contraseña')
            ->greeting('¡Hola!')
            ->line('Estás recibiendo este correo porque recibimos una solicitud de restablecimiento de contraseña para tu cuenta.')
            ->action('Restablecer contraseña', $url)
            ->line('Este enlace expirará en 60 minutos.')
            ->salutation('Saludos, Cánama')
            ->line('Si tienes problemas para hacer clic en el botón, copia y pega esta URL en tu navegador:')
            ->line($url);
    }
}
