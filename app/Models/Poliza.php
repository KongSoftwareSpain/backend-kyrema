<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Poliza extends Model
{
    use HasFactory;

    // Definir la tabla asociada
    protected $table = 'polizas';

    // Los campos que pueden ser rellenables masivamente
    protected $fillable = [
        'compania_id',
        'numero',
        'ramo',
        'descripcion',
        'prima_neta',
        'impuestos',
        'fecha_inicio',
        'fecha_fin_venta',
        'fecha_fin_servicio',
        'estado',
        'doc_adjuntos_1', 'doc_adjuntos_2', 'doc_adjuntos_3',
        'doc_adjuntos_4', 'doc_adjuntos_5', 'doc_adjuntos_6',
        'comentarios'
    ];

    // Relación muchos a uno con el modelo Compania
    public function compania()
    {
        return $this->belongsTo(Compania::class);
    }
}
