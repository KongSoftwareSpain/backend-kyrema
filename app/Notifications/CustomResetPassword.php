<?php

namespace App\Notifications;


use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;

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
        return (new MailMessage)
            ->subject('Restablecimiento de contraseña')
            ->greeting('¡Hola!')
            ->line('Estás recibiendo este correo porque recibimos una solicitud de restablecimiento de contraseña para tu cuenta.')
            ->action('Restablecer contraseña', url(config('app.reset_pwd_url').route('password.reset', ['token' => $this->token, 'email' => $notifiable->email], false)))
            ->line('Este enlace para restablecer la contraseña expirará en 60 minutos.')
            ->line('Si no solicitaste un restablecimiento de contraseña, no se requiere ninguna acción adicional.')
            ->salutation('Saludos, Cánama')
            ->line('Si tienes problemas para hacer clic en el botón "Restablecer contraseña", copia y pega la URL de abajo en tu navegador:')
            ->line(url(config('app.reset_pwd_url').route('password.reset', ['token' => $this->token, 'email' => $notifiable->email], false)));
    }
}
