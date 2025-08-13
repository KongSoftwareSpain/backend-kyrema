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

     // Nos aseguramos que los timestamps estén habilitados
    public $timestamps = true;

    // Si necesitas formatear las fechas automáticamente
    protected $dates = ['created_at', 'updated_at'];

    // Personalizamos el formato de fechas para que SQL server lo pueda convertir de varchar a datetime
    protected $dateFormat = 'Y-m-d\TH:i:s';

    // Esto es para personalizar el formato de fechas a la hora de serializar (por ejemplo, para JSON)
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d\TH:i:s');
    }
}
