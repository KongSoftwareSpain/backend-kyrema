<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Borra los PDF adjuntos de los "Forzar envío" que ya no hacen falta.
 *
 * Cada envío guarda sus adjuntos en storage/app/avisos-adjuntos/{YmdHis}-{azar}/
 * hasta que la cola termina de mandar todos los correos. No se borran al
 * acabar cada job porque no se sabe cuál es el último, así que se purgan por
 * antigüedad (la fecha va en el nombre de la carpeta).
 */
class LimpiarAdjuntosAvisosCommand extends Command
{
    // Margen holgado: si el worker estuvo caído, los correos pendientes aún los encuentran.
    private const DIAS_DE_CONSERVACION = 7;

    protected $signature = 'avisos:limpiar-adjuntos';
    protected $description = 'Borra los PDF adjuntos de envíos forzados de avisos de caducidad con más de ' . self::DIAS_DE_CONSERVACION . ' días';

    public function handle(): void
    {
        $disco = Storage::disk('local');
        $limite = now()->subDays(self::DIAS_DE_CONSERVACION);
        $borradas = 0;

        foreach ($disco->directories('avisos-adjuntos') as $carpeta) {
            try {
                $creada = Carbon::createFromFormat('YmdHis', substr(basename($carpeta), 0, 14));
            } catch (\Throwable $e) {
                continue; // nombre que no es de un lote: no se toca
            }

            if ($creada && $creada->lt($limite)) {
                $disco->deleteDirectory($carpeta);
                $borradas++;
            }
        }

        $this->info("Carpetas de adjuntos borradas: {$borradas}");
    }
}
