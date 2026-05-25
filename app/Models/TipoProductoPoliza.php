<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipoProductoPoliza extends Model
{
    use HasFactory;

    protected $table = 'tipo_producto_polizas';
    protected $dateFormat = 'Y-m-d\TH:i:s';

    // Si deseas permitir asignación masiva en ciertos campos
    protected $fillable = [
        'tipo_producto_id',
        'poliza_id',
        'fila',
        'columna',
        'page',
        'fila_logo',
        'columna_logo',
        'page_logo',
        'font_size',
        'copia',
        'salto_linea_x',
        'salto_linea_y'
    ];
}
