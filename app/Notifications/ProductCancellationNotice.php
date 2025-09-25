<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Comercial;
use App\Models\TipoProducto;

class ProductCancellationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  mixed  $sender    Comercial/User autenticado que realiza la anulación
     * @param  array  $product   Datos del producto (id, nombre, código, etc.)
     * @param  string $tipoProducto  Tipo de producto/seguro (ej.: "Hogar", "Auto"...)
     * @param  string $causa     Motivo de la anulación (>=16 chars)
     * @param  bool   $sendEmail Si true, envía email además de guardar en DB
     */
    public function __construct(
        public readonly Comercial $sender,
        public readonly array $product,
        public readonly TipoProducto $tipoProducto,
        public readonly string $causa,
    ) {}

    public function via($notifiable): array
    {
        // Si no quieres enviar correo (solo DB), pasa sendEmail=false en el constructor
        return ['mail', 'database'];
    }

        public function toMail($notifiable): MailMessage
        {
            $productName = $this->product['nombre'] ?? $this->product['name'] ?? 'Producto';
            $productCode = $this->product['codigo_producto'] ?? 'N/A';

            $senderName  = $this->sender->name ?? $this->sender->nombre ?? $this->sender->email ?? 'Comercial';

            return (new MailMessage)
                ->subject('Anulación de tu seguro — ' . (is_object($this->tipoProducto) ? $this->tipoProducto->nombre : (string)$this->tipoProducto))
                ->markdown('mail.product_cancellation_notice', [
                    'socio'        => $notifiable,
                    'senderName'   => $senderName,
                    'tipoProducto' => $this->tipoProducto,
                    'product'      => $this->product,
                    'productName'  => $productName,
                    'productCode'  => $productCode,
                    'causa'        => $this->causa,
                ]);
        }

    public function toDatabase($notifiable): array
    {
        return [
            'type'         => 'product_cancellation',
            'sender'       => [
                'id'    => $this->sender->id ?? null,
                'name'  => $this->sender->name ?? $this->sender->nombre ?? null,
                'email' => $this->sender->email ?? null,
            ],
            'tipoProducto' => $this->tipoProducto,
            'product'      => $this->product,
            'causa'        => $this->causa,
        ];
    }
}
