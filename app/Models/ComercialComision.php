<?php

// app/Models/ComercialComision.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComercialComision extends Model
{
    protected $table = 'comercial_comision';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id_comercial',
        'porcentual',
        'comision',
    ];

    public function comercial()
    {
        return $this->belongsTo(Comercial::class, 'id_comercial', 'id');
    }
}
