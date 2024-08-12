<?php

// app/Models/TiposAnexos.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TiposAnexos extends Model
{
    protected $table = 'tipos_anexos';
    protected $primaryKey = 'id';

    // ,[nombre]
    //   ,[letras_identificacion]
    //   ,[id_tipo_producto]
    //   ,[created_at]
    //   ,[updated_at]
    protected $fillable = [
        'nombre',
        'letras_identificacion',
        'id_tipo_producto',
    ];

    public function camposAnexos()
    {
        return $this->hasMany(CamposAnexos::class, 'tipo_anexo', 'id');
    }
}
