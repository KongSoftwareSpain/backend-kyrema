<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionEnvioCorreos extends Model
{
    protected $table = 'configuracion_envio_correos';

    // Ver PerfilEnvioAviso: evita el error 22007 del DATEFORMAT español de SQL Server.
    protected $dateFormat = 'Y-m-d\TH:i:s';

    protected $fillable = [
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * Configuración vigente. Es una tabla de una sola fila: si por lo que
     * sea no existe (entorno sin migrar el seed inicial), se asume activo
     * para no bloquear el envío de correo por defecto.
     */
    public static function actual(): self
    {
        return static::first() ?? new static([
            'activo' => true,
        ]);
    }
}
