<?php

// app/Models/TipoProducto.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\TarifasProducto;
use App\Models\Campos;

class TipoProducto extends Model
{
    protected $table = 'tipo_producto';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'nombre',
        'padre_id',
        'tipo_producto_asociado',
        'acuerdo_kyrema',
        'nombre_unificado',
        'estado',
        'renovable',
    ];

    protected $casts = [
        'estado' => 'boolean',
        'renovable' => 'boolean',
    ];

    /**
     * Flag 'renovable' de un producto padre y de sus subproductos, por id.
     */
    public static function mapaRenovable($padreId): array
    {
        return static::where('id', $padreId)
            ->orWhere('padre_id', $padreId)
            ->get(['id', 'renovable'])
            ->mapWithKeys(fn ($tipo) => [(string) $tipo->id => (bool) ($tipo->renovable ?? true)])
            ->all();
    }

    /**
     * Si un registro de producto se renueva: manda el flag de su subproducto y,
     * si no tiene o no se resuelve, el del padre. Ante la duda, renovable.
     */
    public static function registroRenovable(array $mapa, $padreId, $subproducto): bool
    {
        $subproducto = $subproducto === null ? '' : (string) $subproducto;

        if ($subproducto !== '' && array_key_exists($subproducto, $mapa)) {
            return $mapa[$subproducto];
        }

        return $mapa[(string) $padreId] ?? true;
    }

    public function tarifas()
    {
        return $this->hasOne(TarifasProducto::class, 'tipo_producto_id');
    }

    public function campos()
    {
        return $this->hasMany(Campos::class, 'tipo_producto_id');
    }

    public function sociedades()
    {
        return $this->belongsToMany(Sociedad::class, 'tipo_producto_sociedad', 'id_tipo_producto', 'id_sociedad');
    }

    public function toggleEstado()
    {
        $this->estado = !$this->estado;
        return $this->save();
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', true); // funciona porque 'estado' está casteado a boolean
    }

    public function scopeInactivos($query)
    {
        return $query->where('estado', false); // funciona porque 'estado' está casteado a boolean
    }


}
