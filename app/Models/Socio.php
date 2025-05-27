<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use Illuminate\Support\Str;
use Illuminate\Notifications\Notifiable;

class Socio extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable;

    // Definir la tabla asociada al modelo
    protected $table = 'socios';
    
    // Especificar los campos que se pueden asignar de forma masiva
    protected $fillable = [
        'dni',
        'nombre_socio',
        'apellido_1',
        'apellido_2',
        'email',
        'telefono',
        'fecha_de_nacimiento',
        'sexo',
        'direccion',
        'poblacion',
        'provincia',
        'codigo_postal',
        'categoria_id'
    ];

    protected $hidden = ['password'];

    public static function getUltimoProducto($id_socio){
        return DB::table('socios_productos')
            ->where('id_socio', $id_socio)
            ->orderBy('created_at', 'desc')
            ->first();
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
