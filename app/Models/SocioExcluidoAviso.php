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
            $modelo->created_at = $modelo->created_at ?? now();
        });
    }

    public function socio()
    {
        return $this->belongsTo(Socio::class, 'socio_id', 'id');
    }
}
