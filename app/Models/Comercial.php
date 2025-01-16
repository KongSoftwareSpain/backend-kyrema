<?php

// app/Models/Comercial.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use App\Notifications\CustomResetPassword;
use Illuminate\Support\Facades\DB;

class Comercial extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $table = 'comercial';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'nombre',
        'id_sociedad',
        'usuario',
        'email',
        'responsable',
        'pagina_web',
        'contraseña',
        'dni',
        'sexo',
        'fecha_nacimiento',
        'fecha_alta',
        'referido',
        'direccion',
        'poblacion',
        'provincia',
        'cod_postal',
        'telefono',
        'fax',
        'path_licencia_cazador',
        'path_dni',
        'path_justificante_iban',
        'path_otros',
        'path_foto',
    ];
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new CustomResetPassword($token));
    }

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'id_sociedad', 'id');
    }

    public function comisiones()
    {
        return $this->hasMany(ComercialComision::class, 'id_comercial', 'id');
    }

    public static function create(array $data)
    {
        $comercial = new static();

        $comercial->fill($data);
        $comercial->save();

        return $comercial;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public static function getComercialByProducto($letras_identificacion, $id) {
        $result = DB::table($letras_identificacion)
            ->select('comercial_id') // Especifica las columnas que quieres obtener
            ->where('id', $id) // Aplica el filtro por ID
            ->first(); // Obtén un único registro

        return $result ? $result->comercial_id : null; // Devuelve el valor de comercial_id o null si no hay resultados
    }

    
}

