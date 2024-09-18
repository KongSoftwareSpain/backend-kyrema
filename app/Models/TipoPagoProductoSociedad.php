<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipoPagoProductoSociedad extends Model
{
    use HasFactory;

    protected $table = 'tipo_pago_producto_sociedad';

    protected $fillable = [
        'tipo_pago_id',
        'tipo_producto_id',
        'sociedad_id',
    ];

    // Define las relaciones si es necesario
    public function tipoPago()
    {
        return $this->belongsTo(TipoPago::class, 'tipo_pago_id');
    }

    public function tipoProducto()
    {
        return $this->belongsTo(TipoProducto::class, 'tipo_producto_id');
    }

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'sociedad_id');
    }
}
