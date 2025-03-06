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
        'precio_base',
        'extra_1',
        'extra_2',
        'extra_3',
        'precio_total',
    ];

    protected $casts = [
        'precio_base' => 'float',
        'extra_1' => 'float',
        'extra_2' => 'float',
        'extra_3' => 'float',
        'precio_total' => 'float'
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
