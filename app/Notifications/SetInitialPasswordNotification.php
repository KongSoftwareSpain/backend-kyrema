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
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        // URL de tu frontend para el reset (Angular/NX/etc.)
        // Asegúrate de URL-encodear email si lo montas tú en el front.
        $url = config('app.front_reset_url')  // p.e. https://app.tudominio.com/reset-password
            . '?token='.$this->token.'&email='.urlencode($this->email);

        $name = $this->displayName ?: ($notifiable->name ?? ''); 

        return (new MailMessage)
            ->subject('Bienvenido — crea tu contraseña')
            ->greeting($name ? "Hola {$name}" : 'Hola')
            ->line('Tu cuenta ha sido creada por tu comercial.')
            ->line($this->productHint ?: 'Desde el siguiente enlace podrás crear tu contraseña y acceder a tus productos.')
            ->action('Crear contraseña', $url)
            ->line('Si no esperabas este correo, puedes ignorarlo.')
            ->salutation('Atentamente, Cánama Seguros');
    }
}
