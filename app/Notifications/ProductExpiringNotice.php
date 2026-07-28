<?php

namespace App\Notifications;

use App\Models\Comercial;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;

class ProductExpiringNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Comercial $comercial,
        public readonly Collection $productos // cada item: codigo_producto, nombre_producto, fecha_de_fin, socio_nombre
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->mailer('noreply')
            ->subject('Productos próximos a caducar (' . $this->productos->count() . ')')
            ->greeting('Hola ' . ($this->comercial->nombre ?? '') . ',')
            ->line('Los siguientes productos de tu cartera están próximos a caducar:');

        foreach ($this->productos as $producto) {
            $mail->line(
                '- ' . ($producto['nombre_producto'] ?? 'Producto')
                . ' (código: ' . ($producto['codigo_producto'] ?? 'N/A') . ')'
                . ' — socio: ' . ($producto['socio_nombre'] ?? 'N/A')
                . ' — caduca: ' . ($producto['fecha_de_fin'] ?? 'N/A')
            );
        }

        return $mail->line(' ')->line('Gestiona la renovación a tiempo.');
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'product_expiring',
            'productos' => $this->productos->values()->all(),
        ];
    }
}
