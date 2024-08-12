<?php

// app/Models/EscaladoAnexo.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EscaladoAnexo extends Model
{
    protected $table = 'escalado_anexo';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'anexo_id',
        'desde',
        'hasta',
        'precio',
    ];

    public function tipoAnexo()
    {
        return $this->belongsTo(TiposAnexos::class, 'anexo_id', 'id');
    }
}
