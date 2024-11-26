<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Socio extends Model
{
    use HasFactory;

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
        'codigo_postal'
    ];
}
