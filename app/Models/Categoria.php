<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    use HasFactory;

    // Tabla asociada
    protected $table = 'categorias';

    // Campos que pueden ser asignados de forma masiva
    protected $fillable = ['nombre'];

    // Desactivar timestamps
    public $timestamps = false;
}
