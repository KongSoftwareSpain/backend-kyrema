<?php

// app/Models/CPTipoProducto.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CPTipoProducto extends Model
{
    protected $table = 'cp_familia';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'id_familia',
        'vetado',
        'cp',
    ];

    public function tipoAnexo()
    {
        return $this->belongsTo(TiposAnexos::class, 'id_familia', 'id');
    }
}
