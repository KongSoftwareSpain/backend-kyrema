<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class SocioProducto extends Model
{
    use HasFactory;

    // Definir la tabla asociada al modelo
    protected $table = 'socios_productos';
    public $timestamps = false;
    // Especificar los campos que se pueden asignar de forma masiva
    protected $fillable = [
        'id_producto',
        'id_socio',
        'letras_identificacion'
    ];

    public static function connectSocioAndProducto($id_socio, $id_producto, $letras_identificacion){
        $socio_producto = new SocioProducto();
        $socio_producto->id_socio = $id_socio;
        $socio_producto->id_producto = $id_producto;
        $socio_producto->letras_identificacion = $letras_identificacion;
        $socio_producto->created_at = Carbon::now()->format('Y-m-d\TH:i:s');; // Fecha actual en formato correcto
        $socio_producto->updated_at = Carbon::now()->format('Y-m-d\TH:i:s');;
        $socio_producto->save();
    }
}
