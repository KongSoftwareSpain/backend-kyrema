<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GiroBancario extends Model
{
    use HasFactory;

    protected $table = 'giros_bancarios';

    protected $fillable = [
        'pago_id',
        'referencia',
        'nombre_cliente',
        'dni',
        'importe',
        'fecha_firma_mandato',
        'iban_cliente',
        'auxiliar',
        'sociedad',
        'residente',
        'referencia_mandato',
        'fecha_cobro',
        'referencia_adeudo',
        'tipo_adeudo',
        'concepto',
    ];

    protected $casts = [
        'fecha_firma_mandato' => 'date',
    ];

    /**
     * Relación con el pago general
     */
    public function pago()
    {
        return $this->belongsTo(Pago::class);
    }

    // Nos aseguramos que los timestamps estén habilitados
    public $timestamps = true;

    // Si necesitas formatear las fechas automáticamente
    protected $dates = ['created_at', 'updated_at', 'fecha_cobro'];

    // Personalizamos el formato de fechas para que SQL server lo pueda convertir de varchar a datetime
    protected $dateFormat = 'Y-m-d\TH:i:s';

    // Esto es para personalizar el formato de fechas a la hora de serializar (por ejemplo, para JSON)
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d\TH:i:s');
    }
}
