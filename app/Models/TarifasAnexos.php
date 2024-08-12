<?php

// app/Models/TarifasAnexos.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TarifasAnexos extends Model
{
    // Nombre de la tabla
    protected $table = 'tarifas_anexos';

    // Clave primaria
    protected $primaryKey = 'id';

    // Campos que se pueden llenar masivamente
    protected $fillable = [
        'id_tipo_anexo',
        'id_sociedad',
        'prima_seguro',
        'cuota_asociacion',
        'precio_total',
        'tiene_escalado',
        'created_at',
        'updated_at',
    ];

    public function tipoAnexo()
    {
        return $this->belongsTo(TiposAnexos::class, 'anexo', 'id');
    }

    public function escaladosAnexo()
    {
        return $this->hasMany(EscaladoAnexo::class, 'anexo_id', 'anexo');
    }
}
