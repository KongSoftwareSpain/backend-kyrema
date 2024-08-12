<?php

// app/Models/TarifasProducto.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TarifasProducto extends Model
{
    protected $table = 'tarifas_producto';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'tipo_producto_id',
        'id_sociedad',
        'prima_seguro',
        'cuota_asociacion',
        'precio_total',
    ];

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'id_sociedad', 'id');
    }

    public function tarifasAnexos()
    {
        return $this->hasMany(TarifasAnexos::class, 'id_producto', 'id');
    }
}
