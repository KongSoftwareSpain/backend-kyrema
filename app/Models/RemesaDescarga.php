<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RemesaDescarga extends Model
{
    use HasFactory;

    protected $table = 'remesa_descargas';

    protected $fillable = [
        'ruta_xml',
        'fecha_inicio',
        'fecha_fin',
        'descargado_en',
        'id_comercial',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'descargado_en' => 'datetime',
    ];

    /**
     * Comercial que descargó esta remesa
     */
    public function comercial()
    {
        return $this->belongsTo(Comercial::class, 'id_comercial');
    }

}
