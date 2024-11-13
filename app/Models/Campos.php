<?php

// app/Models/Campos.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campos extends Model
{
    protected $table = 'campos';
    protected $primaryKey = 'id';
    protected $keyType = 'string';

    // 'nombre' => $campo['nombre'],
    // 'nombre_codigo' => strtolower(str_replace(' ', '_', $campo['nombre'])),
    // 'tipo_producto_id' => $tipoProductoId,
    // 'columna' => $campo['columna'] ?? null,
    // 'fila' => $campo['fila'] ?? null,
    // 'tipo_dato' => $campo['tipoDato'],
    // 'visible' => $campo['visible'] ?? false,
    // 'obligatorio' => $campo['obligatorio'] ?? false,
    // 'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
    // 'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
    // 'grupo' => $campo['grupo'] ?? null,
    protected $fillable = [
        'nombre',
        'nombre_codigo',
        'tipo_producto_id',
        'columna',
        'fila',
        'page',
        'tipo_dato',
        'visible',
        'obligatorio',
        'grupo',
    ];

    public function tipoProducto()
    {
        return $this->belongsTo(TipoProducto::class, 'tipo_producto_id', 'id');
    }

    public function valores()
    {
        return $this->hasMany(Valores::class, 'campo_id', 'id');
    }
}
