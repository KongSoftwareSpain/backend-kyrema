<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use App\Models\Sociedad;
use Creagia\LaravelRedsys\Contracts\RedsysPayable;
use App\Models\Payments\PaymentGatewayLink;
use Creagia\LaravelRedsys\Concerns\CanCreateRedsysRequests;

class Pago extends Model implements RedsysPayable
{
    use HasFactory;
    use CanCreateRedsysRequests;

    protected $table = 'pagos';

    // Añadimos los nuevos campos “fillable”
    protected $fillable = [
        'referencia',
        'tipo_pago',
        'monto',
        'amount_cents',
        'currency',
        'fecha',
        'estado',
        'letras_identificacion',
        'producto_id',
        'sociedad_id',
        'auth_code',
        'response_code',
        'response_message',
    ];

    protected $casts = [
        'fecha'           => 'datetime',
    ];

    public $timestamps = true;

    protected $dates = ['created_at', 'updated_at'];

    // Formato compatible con SQL Server que ya usabas
    protected $dateFormat = 'Y-m-d\TH:i:s';

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d\TH:i:s');
    }

    public function getTotalAmount(): int
    {
        return (int)$this->amount_cents; // céntimos
    }

    public function paidWithRedsys(): void
    {
        $this->estado = self::STATUS_PAID;
        $this->save();
        // aquí puedes disparar tu propia lógica post-pago (factura, etc.)
    }

    // Estados tipificados
    public const STATUS_PENDING = 'pendiente';
    public const STATUS_PAID    = 'pagado';
    public const STATUS_FAILED  = 'fallido';

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class);
    }

    public function gatewayLinks()
    {
        return $this->hasMany(PaymentGatewayLink::class, 'pago_id');
    }

    /**
     * Devuelve el registro relacionado desde la tabla dinámica.
     */
    public function obtenerProductoRelacionado()
    {
        if (!$this->letras_identificacion || !$this->producto_id) {
            return null;
        }
        return DB::table($this->letras_identificacion)->find($this->producto_id);
    }

    /** Helpers para céntimos ↔ euros */
    public function setMontoAttribute($value): void
    {
        $this->attributes['monto'] = $value;
        // si viene en euros, rellena amount_cents si está vacío
        if (!isset($this->attributes['amount_cents']) && is_numeric($value)) {
            $this->attributes['amount_cents'] = (int) round($value * 100);
        }
    }

    public function setAmountCentsAttribute($value): void
    {
        $this->attributes['amount_cents'] = (int) $value;
        // espejo a monto si es decimal
        if (!isset($this->attributes['monto']) || $this->attributes['monto'] === null) {
            $this->attributes['monto'] = $value / 100;
        }
    }
}
