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

    // Nos aseguramos que los timestamps estén habilitados
    public $timestamps = true;

    // Si necesitas formatear las fechas automáticamente
    protected $dates = ['created_at', 'updated_at', 'fecha_inicio', 'fecha_fin_venta', 'fecha_fin_servicio'];

    // Personalizamos el formato de fechas para que SQL server lo pueda convertir de varchar a datetime
    protected $dateFormat = 'Y-m-d\TH:i:s';
}
