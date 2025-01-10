<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Socio extends Model
{
    use HasFactory;

    // Definir la tabla asociada al modelo
    protected $table = 'socios';
    public $timestamps = false;
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

    public static function getUltimoProducto($id_socio){
        return $producto = DB::table('socios_productos')
            ->where('id_socio', $id_socio)
            ->orderBy('created_at', 'desc')
            ->first();
    }
}
