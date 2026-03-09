<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class RenewalSuccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public $producto;
    public $letrasIdentificacion;

    /**
     * Create a new message instance.
     */
    public function __construct($producto, $letrasIdentificacion)
    {
        $this->producto = $producto;
        $this->letrasIdentificacion = $letrasIdentificacion;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Su seguro ha sido renovado correctamente - Kyrema',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.renewal_success',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        if (!empty($this->producto->blob_name)) {
            $path = $this->letrasIdentificacion . '/' . $this->producto->codigo_producto . '/' . $this->producto->blob_name;

            // Intentar buscar en Azure si está configurado
            try {
                if (config('filesystems.disks.azure') && Storage::disk('azure')->exists($path)) {
                    $attachments[] = Attachment::fromStorageDisk('azure', $path)
                        ->as('certificado_renovacion.pdf')
                        ->withMime('application/pdf');
                    return $attachments;
                }
            } catch (\Exception $e) {
                // El disco azure no existe o falló la conexión
            }

            // Fallback al disco por defecto (local)
            if (Storage::exists($path)) {
                $attachments[] = Attachment::fromPath(storage_path('app/' . $path))
                    ->as('certificado_renovacion.pdf')
                    ->withMime('application/pdf');
            }
        }

        return $attachments;
    }
}
