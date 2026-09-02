<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionAvisoCaducidad extends Model
{
    protected $table = 'configuracion_avisos_caducidad';

    protected $fillable = [
        'dias_aviso',
        'activo',
    ];

    protected $casts = [
        'dias_aviso' => 'array',
        'activo' => 'boolean',
    ];

    /**
     * Configuración vigente. Es una tabla de una sola fila: si por lo que
     * sea no existe (entorno sin migrar el seed inicial), se devuelven los
     * valores por defecto sin persistirlos.
     */
    public static function actual(): self
    {
        return static::first() ?? new static([
            'dias_aviso' => [30, 15, 1],
            'activo' => true,
        ]);
    }
}
