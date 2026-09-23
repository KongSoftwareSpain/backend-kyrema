<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerfilEnvioAviso extends Model
{
    protected $table = 'perfiles_envio_avisos';

    protected $fillable = [
        'nombre',
        'activo',
        'modo_tiempo',
        'dias_aviso',
        'dia_envio_mensual',
        'incluir_productos_activos',
        'incluir_productos_renovados',
        'fecha_actividad_socio_desde',
        'evitar_duplicados',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'dias_aviso' => 'array',
        'incluir_productos_activos' => 'boolean',
        'incluir_productos_renovados' => 'boolean',
        'fecha_actividad_socio_desde' => 'date:Y-m-d',
        'evitar_duplicados' => 'boolean',
    ];

    public function tiposProducto()
    {
        return $this->belongsToMany(
            TipoProducto::class,
            'perfil_avisos_tipo_producto',
            'perfil_id',
            'tipo_producto_id'
        )->withTimestamps();
    }

    public function sociedades()
    {
        return $this->belongsToMany(
            Sociedad::class,
            'perfil_avisos_sociedad',
            'perfil_id',
            'sociedad_id'
        )->withTimestamps();
    }

    public function comerciales()
    {
        return $this->belongsToMany(
            Comercial::class,
            'perfil_avisos_comercial',
            'perfil_id',
            'comercial_id'
        )->withTimestamps();
    }

    public function socios()
    {
        return $this->belongsToMany(
            Socio::class,
            'perfil_avisos_socio',
            'perfil_id',
            'socio_id'
        )->withTimestamps();
    }

    public function formasPago()
    {
        return $this->hasMany(PerfilAvisoFormaPago::class, 'perfil_id');
    }
}
