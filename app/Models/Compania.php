<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Compania extends Model
{
    use HasFactory;

    // Definir la tabla asociada
    protected $table = 'companias';

    // Los campos que pueden ser rellenables masivamente
    protected $fillable = [
        'nombre',
        'CIF',
        'logo',
        'IBAN',
        'nombre_contacto_1', 'cargo_contacto_1', 'email_contacto_1', 'telefono_contacto_1',
        'nombre_contacto_2', 'cargo_contacto_2', 'email_contacto_2', 'telefono_contacto_2',
        'nombre_contacto_3', 'cargo_contacto_3', 'email_contacto_3', 'telefono_contacto_3',
        'nombre_contacto_4', 'cargo_contacto_4', 'email_contacto_4', 'telefono_contacto_4',
        'nombre_contacto_5', 'cargo_contacto_5', 'email_contacto_5', 'telefono_contacto_5',
        'comentarios'
    ];

    // Relación uno a muchos con el modelo Poliza
    public function polizas()
    {
        return $this->hasMany(Poliza::class);
    }
}
