<?php

// app/Models/Navegacion.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Navegacion extends Model
{
    protected $table = 'navegacion';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'navegacion',
        'navegacion_padre_id',
        'ruta',
        'nivel',
    ];

    public function navegacionPadre()
    {
        return $this->belongsTo(Navegacion::class, 'navegacion_padre_id', 'id');
    }

    public function hijosNavegacion()
    {
        return $this->hasMany(Navegacion::class, 'navegacion_padre_id', 'id');
    }
}
