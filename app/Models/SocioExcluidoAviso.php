<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocioExcluidoAviso extends Model
{
    protected $table = 'avisos_socios_excluidos';

    public $timestamps = false;

    protected $fillable = [
        'socio_id',
        'motivo',
    ];

    protected $attributes = [
        'created_at' => null,
    ];

    protected static function booted()
    {
        static::creating(function (self $modelo) {
            // Cadena con 'T', no un Carbon: el DATEFORMAT español de SQL Server
            // rechaza 'Y-m-d H:i:s' (error 22007) en columnas datetime.
            $modelo->created_at = $modelo->created_at ?? now()->format('Y-m-d\TH:i:s');
        });
    }

    public function socio()
    {
        return $this->belongsTo(Socio::class, 'socio_id', 'id');
    }
}
