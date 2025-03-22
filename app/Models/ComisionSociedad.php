<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ComisionSociedad extends Model {
    use HasFactory;

    protected $table = 'comisiones_sociedad';

    protected $fillable = [
        'id_sociedad',
        'valor',
        'tipo_producto_id',
        'tipo',
    ];

    protected $casts = [
        'id_sociedad' => 'integer',
        'valor' => 'float',
        'tipo_producto_id' => 'integer',
        'tipo' => 'string',
    ];  

    public function sociedad() {
        return $this->belongsTo(Comercial::class, 'id_sociedad');
    }

    public function tipoProducto() {
        return $this->belongsTo(TipoProducto::class, 'tipo_producto_id');
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
