<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerfilAvisoFormaPago extends Model
{
    protected $table = 'perfil_avisos_forma_pago';

    // Ver PerfilEnvioAviso: evita el error 22007 del DATEFORMAT español de SQL Server.
    protected $dateFormat = 'Y-m-d\TH:i:s';

    protected $fillable = [
        'perfil_id',
        'valor',
    ];
}
