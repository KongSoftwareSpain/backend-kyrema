<?php
// app/Notifications/SetInitialPasswordNotification.php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SetInitialPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly string $email,
        public readonly string $categoryName, // p.e Kyrema, Cánama, Amigos24...
        public readonly ?string $displayName = null, // opcional
        public readonly ?string $productHint = null   // opcional, p.e. “accede a tus productos…”
    ) {
        $this->afterCommit = true;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $frontend = rtrim(config('app.reset_pwd_url'), '/');
        $token = $this->token;
        $email = urlencode($notifiable->email);

        $path = "/{$notifiable->categoria_id}/password/reset/{$token}";

        $url = "{$frontend}{$path}?email={$email}";

        $name = $this->displayName ?: ($notifiable->nombre_socio ?? ''); 

        return (new MailMessage)
            ->subject('Bienvenido — crea tu contraseña')
            ->greeting($name ? "Buenas {$name}" : 'Buenas')
            ->line("Tu cuenta para acceder a {$this->categoryName} ha sido creada por tu comercial.")
            ->line($this->productHint ?: 'Desde el siguiente enlace podrás crear tu contraseña y acceder a tus productos.')
            ->action('Crear contraseña', $url)
            ->line('Si no esperabas este correo, puedes ignorarlo.')
            ->salutation('Atentamente, Cánama Seguros');
    }
}
