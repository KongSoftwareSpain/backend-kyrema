<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerfilEnvioAviso extends Model
{
    protected $table = 'perfiles_envio_avisos';

    // SQL Server va con DATEFORMAT español (dmy): 'Y-m-d H:i:s' en una columna
    // datetime se lee como año-día-mes (error 22007 si el día pasa de 12, y fecha
    // intercambiada en silencio si no). El formato con 'T' es el único seguro.
    protected $dateFormat = 'Y-m-d\TH:i:s';

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

    // Los pivotes van sin withTimestamps(): sin un modelo pivote propio, Eloquent
    // escribe sus created_at/updated_at con 'Y-m-d H:i:s', que el DATEFORMAT español
    // de SQL Server rechaza (22007). Las columnas son nullable y nadie las lee
    // (mismo criterio que Sociedad::categorias()).
    public function tiposProducto()
    {
        return $this->belongsToMany(
            TipoProducto::class,
            'perfil_avisos_tipo_producto',
            'perfil_id',
            'tipo_producto_id'
        );
    }

    public function sociedades()
    {
        return $this->belongsToMany(
            Sociedad::class,
            'perfil_avisos_sociedad',
            'perfil_id',
            'sociedad_id'
        );
    }

    public function comerciales()
    {
        return $this->belongsToMany(
            Comercial::class,
            'perfil_avisos_comercial',
            'perfil_id',
            'comercial_id'
        );
    }

    public function socios()
    {
        return $this->belongsToMany(
            Socio::class,
            'perfil_avisos_socio',
            'perfil_id',
            'socio_id'
        );
    }

    public function formasPago()
    {
        return $this->hasMany(PerfilAvisoFormaPago::class, 'perfil_id');
    }
}
