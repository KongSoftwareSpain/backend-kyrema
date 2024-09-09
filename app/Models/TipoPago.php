<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipoPago extends Model
{
    use HasFactory;

    protected $table = 'tipos_pago';

    protected $fillable = [
        'nombre',
        'codigo',
    ];

    // Metodo find para buscar por id:
    public static function find($id)
    {
        return TipoPago::where('id', $id)->first();
    }
}
