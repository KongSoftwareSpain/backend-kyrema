<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class Pago extends Model
{
    use HasFactory;

    protected $fillable = [
        'referencia',
        'letras_identificacion',
        'producto_id',
        'tipo_pago',
        'monto',
        'fecha',
        'estado',
        'sociedad_id',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    /**
     * Devuelve el registro relacionado desde la tabla dinámica.
     */
    public function obtenerProductoRelacionado()
    {
        if (!$this->letras_identificacion || !$this->producto_id) {
            return null;
        }

        return DB::table($this->letras_identificacion)->find($this->producto_id);
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

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class);
    }
}
