<?php

// app/Models/TipoProductoSociedad.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoProductoSociedad extends Model
{
    protected $table = 'tipo_producto_sociedad';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'id_sociedad',
        'id_tipo_producto',
    ];

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'id_sociedad', 'id');
    }

    public function tipoProducto()
    {
        return $this->belongsTo(TipoProducto::class, 'id_tipo_producto', 'id');
    }
}
