<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CampoAnexo extends Model
{
    use HasFactory;

    protected $table = 'campos_anexos'; // Nombre de la tabla en la base de datos

    protected $fillable = [
        'nombre',
        'nombre_codigo',
        'columna',
        'fila',
        'tipo_dato',
        'tipo_anexo',
        'obligatorio',
        'created_at',
        'updated_at',
    ];
}
