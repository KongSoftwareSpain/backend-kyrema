<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferenciaSecuencia extends Model
{
    protected $table = 'referencia_secuencias';
    protected $primaryKey = 'letras_identificacion';
    public $incrementing = false; // porque la PK no es numérica
    protected $keyType = 'string';
    protected $fillable = ['letras_identificacion', 'ultimo_producto'];

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
