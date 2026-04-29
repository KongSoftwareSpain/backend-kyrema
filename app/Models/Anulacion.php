<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Anulacion extends Model
{
    use HasFactory;

    // Especificar la tabla asociada con el modelo (opcional si el nombre sigue la convención)
    protected $table = 'anulaciones';

    // Especificar la clave primaria (opcional si es 'id')
    protected $primaryKey = 'id';

    // Indicar si la tabla tiene timestamps (created_at y updated_at)
    public $timestamps = true;

    // Personalizamos el formato de fechas para que SQL server lo pueda convertir de varchar a datetime
    protected $dateFormat = 'Y-m-d\TH:i:s';

    // Los atributos que se pueden asignar masivamente
    protected $fillable = [
        'fecha',
        'sociedad_id',
        'comercial_id',
        'sociedad_nombre',
        'comercial_nombre',
        'causa',
        'letrasIdentificacion',
        'producto_id',
        'codigo_producto',
    ];

    // Definir las relaciones
    public function comercial()
    {
        return $this->belongsTo(Comercial::class, 'comercial_id');
    }

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'sociedad_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}