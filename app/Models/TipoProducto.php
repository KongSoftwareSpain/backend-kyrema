<?php

// app/Models/TipoProducto.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoProducto extends Model
{
    protected $table = 'tipo_producto';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'nombre',
    ];

    public function sociedades()
    {
        return $this->belongsToMany(Sociedad::class, 'tipo_producto_sociedad', 'id_tipo_producto', 'id_sociedad');
    }
}
