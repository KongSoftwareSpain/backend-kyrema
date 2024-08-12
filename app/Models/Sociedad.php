<?php

// app/Models/Sociedad.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
//Carbon
use Carbon\Carbon;

class Sociedad extends Model
{
    protected $table = 'sociedad';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'nombre', 'cif', 'correo_electronico', 'tipo_sociedad', 'direccion', 
        'poblacion', 'pais', 'codigo_postal', 'codigo_sociedad', 'telefono', 
        'fax', 'movil', 'iban', 'banco', 'sucursal', 'dc', 'numero_cuenta', 
        'swift', 'dominio', 'observaciones', 'logo', 'sociedad_padre_id'
    ];
    
    public function getSociedadesHijasRecursivo($id, &$results = [])
    {
        $sociedades = Sociedad::where('sociedad_padre_id', $id)->get();

        foreach ($sociedades as $sociedad) {
            $results[] = $sociedad;
            $this->getSociedadesHijasRecursivo($sociedad->id, $results);
        }

        return $results;
    }   

    public function tipoProductoSociedades()
    {
        return $this->hasMany(TipoProductoSociedad::class, 'id_sociedad', 'id');
    }

    public function sociedadPadre()
    {
        return $this->belongsTo(Sociedad::class, 'sociedad_padre_id', 'id');
    }

    public function comerciales()
    {
        return $this->hasMany(Comercial::class, 'id_sociedad', 'id');
    }

    public function tarifasProductos()
    {
        return $this->hasMany(TarifasProducto::class, 'id_sociedad', 'id');
    }
}
