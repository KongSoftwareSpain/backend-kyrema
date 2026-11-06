<?php

// app/Models/Campos.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Campos extends Model
{
    protected $table = 'campos';
    protected $primaryKey = 'id';
    protected $keyType = 'string';

    /**
     * The storage format of the model's date columns.
     * SQL Server sometimes has issues with milliseconds in some configurations.
     * 
     * @var string
     */
    protected $dateFormat = 'Y-m-d\TH:i:s';

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
    // OJO: 'opciones' (nombre de la tabla de opciones de un selector) NO es fillable a propósito:
    // el front manda 'opciones' como array de opciones en muchos payloads y escribirlo en una
    // columna de texto fallaría. Se asigna siempre de forma explícita ($campo->opciones = ...;
    // $campo->save()) en CampoController::sincronizarOpciones. Si se usa Campos::create/update con
    // 'opciones', Eloquent lo descarta SIN avisar y el selector se queda sin opciones.
    protected $fillable = [
        'nombre',
        'nombre_codigo',
        'tipo_producto_id',
        'columna',
        'fila',
        'page',
        'font_size',
        'tipo_dato',
        'visible',
        'obligatorio',
        'grupo',
        'copia',
        'salto_linea_x',
        'salto_linea_y',
    ];

    /**
     * CampoController::getByTipoProducto cachea la lista de campos 5 min. Cualquier alta, cambio o
     * borrado Eloquent de un campo invalida esa caché para que el configurador no muestre datos
     * viejos tras guardar. (Las escrituras con DB::table no pasan por aquí; el controlador además
     * invalida explícitamente tras el commit, porque este hook se dispara antes de que termine la
     * transacción.)
     */
    protected static function booted(): void
    {
        $olvidar = fn (Campos $campo) => Cache::forget("campos_tipo_producto_{$campo->tipo_producto_id}");

        static::saved($olvidar);
        static::deleted($olvidar);
    }

    public function tipoProducto()
    {
        return $this->belongsTo(TipoProducto::class, 'tipo_producto_id', 'id');
    }

    public function valores()
    {
        return $this->hasMany(Valores::class, 'campo_id', 'id');
    }
}
