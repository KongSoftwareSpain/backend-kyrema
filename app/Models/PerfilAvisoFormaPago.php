<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerfilAvisoFormaPago extends Model
{
    protected $table = 'perfil_avisos_forma_pago';

    protected $fillable = [
        'perfil_id',
        'valor',
    ];
}
