<?php

namespace App\Jobs;

use App\Services\Avisos\EnvioAvisosService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Envía un aviso de caducidad del "Forzar envío" y lo registra como enviado o
 * como fallido. Se encola un job por correo para que ninguna petición HTTP
 * tenga que esperar al SMTP (con adjuntos son lentos y un perfil puede tener
 * cientos de destinatarios).
 *
 * Sin SerializesModels ni propiedades readonly a propósito: solo lleva valores
 * simples, así que no hay nada que rehidratar al deserializar el job.
 */
class EnviarAvisoCaducidadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Sin reintentos automáticos: un fallo de SMTP después de haber entregado el
     * correo duplicaría el aviso. Los fallos quedan en avisos_caducidad_fallidos
     * y se pueden reenviar volviendo a forzar el perfil (solo se incluyen los
     * que no constan como enviados).
     */
    public int $tries = 1;

    /**
     * @param  array<int, array{ruta:string, nombre:string}>  $adjuntos  ruta relativa al disco 'local'
     */
    public function __construct(
        public int $perfilId,
        public string $tabla,
        public int $productoId,
        public $comercialId,
        public string $email,
        public string $texto,
        public ?int $diasAviso,
        public array $adjuntos = [],
    ) {
    }

    public function handle(EnvioAvisosService $servicio): void
    {
        try {
            $disco = Storage::disk('local');
            $adjuntos = [];

            foreach ($this->adjuntos as $adjunto) {
                if (!$disco->exists($adjunto['ruta'])) {
                    throw new \RuntimeException("El adjunto «{$adjunto['nombre']}» ya no está disponible en el servidor.");
                }

                $adjuntos[] = ['ruta' => $disco->path($adjunto['ruta']), 'nombre' => $adjunto['nombre']];
            }

            $servicio->enviarCorreo($this->email, $this->texto, $adjuntos);
        } catch (\Throwable $e) {
            $servicio->registrarFallido($this->perfilId, $this->tabla, $this->productoId, $this->comercialId, $this->email, $e->getMessage(), true);

            return;
        }

        $servicio->registrarEnviado($this->perfilId, $this->tabla, $this->productoId, $this->comercialId, $this->diasAviso, true);
    }
}
