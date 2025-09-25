<?php

// app/Notifications/ProductChangeRequested.php

namespace App\Notifications;

use App\Models\Categoria;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Comercial;
use App\Models\Sociedad;

class ProductChangeRequest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Comercial $sender,     // User (comercial que solicita)
        public readonly Categoria $categoria,  // Categoria
        public readonly array $product, // Datos del producto a cambiar
        public readonly string $productName,
        public readonly string $note // Nota del comercial
    ) {}

    public function via($notifiable): array
    {
        // Mail + Database (si tienes la tabla notifications migrada)
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $productCode   = $this->product['codigo_producto']   ?? null;

        $mail = (new MailMessage)
            ->subject('Solicitud de cambio de producto — ' . ($this->categoria->nombre ?? 'Categoría'))
            ->greeting('Hola,')
            ->line('Has recibido una solicitud de cambio de producto.')
            ->line('**Categoría:** ' . ($this->categoria->nombre ?? $this->categoria->id))
            ->line('**Solicitado por:** ')
            ->line('- Nombre: ' . ($this->sender->nombre ?? ''))
            ->line('- Email: ' . ($this->sender->email ?? ''))
            ->line('- Sociedad: ' . (Sociedad::find($this->sender->id_sociedad)->nombre ?? ''))
            ->line('- Usuario: ' . ($this->sender->usuario ?? ''))
            ->line('**Nota:** ' . ($this->note ?? ''))
            ->line(' ')
            ->line('**Detalle del producto:**')
            ->line('- Nombre: ' . $this->productName)
            ->line('- Código producto: ' . ($productCode ?? 'N/A'));

        return $mail;
    }

    public function toDatabase($notifiable): array
    {
        // Payload guardado en notifications (opcional)
        return [
            'type'      => 'product_change_requested',
            'categoria' => [
                'id'     => $this->categoria->id,
                'nombre' => $this->categoria->nombre ?? null,
            ],
            'sender'    => [
                'id'    => $this->sender->id ?? null,
                'name'  => $this->sender->name ?? null,
                'email' => $this->sender->email ?? null,
            ],
            'product'   => $this->product,
            'note'      => $this->note,
        ];
    }
}
