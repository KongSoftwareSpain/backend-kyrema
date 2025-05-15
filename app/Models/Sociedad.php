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
        'nombre',
        'cif',
        'correo_electronico',
        'tipo_sociedad',
        'direccion',
        'poblacion',
        'pais',
        'codigo_postal',
        'codigo_sociedad',
        'telefono',
        'fax',
        'movil',
        'iban',
        'banco',
        'sucursal',
        'dc',
        'numero_cuenta',
        'swift',
        'dominio',
        'observaciones',
        'logo',
        'sociedad_padre_id'
    ];

    public function getSociedadesHijasDesde($idBase)
    {
        $todas = Sociedad::all(); // Una sola consulta
        return $this->filtrarHijasRecursivo($idBase, $todas);
    }

    private function filtrarHijasRecursivo($padreId, $todas, &$resultado = [])
    {
        foreach ($todas as $sociedad) {
            if ($sociedad->sociedad_padre_id === $padreId) {
                $resultado[] = $sociedad;
                $this->filtrarHijasRecursivo($sociedad->id, $todas, $resultado);
            }
        }

        return $resultado;
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

    public function getLogoBase64()
    {
        if (!$this->logo) {
            return null; // Retorna null si no hay logo
        }

        $path = storage_path('app/public/logos/' . $this->logo);

        if (!file_exists($path)) {
            return null;
        }

        $imageData = file_get_contents($path);
        return 'data:image/png;base64,' . base64_encode($imageData);
    }
}
