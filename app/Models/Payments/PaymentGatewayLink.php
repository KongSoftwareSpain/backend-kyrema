<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Creagia\LaravelRedsys\Request;

class PaymentGatewayLink extends Model
{
    protected $table = 'payment_gateway_links';

    protected $fillable = [
        'pago_id',
        'gateway',              // p.ej. 'redsys'
        'redsys_request_id',    // FK a tabla del paquete
        'gateway_order_ref',    // Ds_Order u otra ref del gateway
        'gateway_status',       // created|paid|failed|...
        'gateway_payload',      // JSON con trazas/params
    ];

    protected $casts = [
        'gateway_payload' => 'array',
    ];

    public $timestamps = true;

    protected $dates = ['created_at', 'updated_at'];

    // Formato compatible con SQL Server que ya usabas
    protected $dateFormat = 'Y-m-d\TH:i:s';

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d\TH:i:s');
    }

    /* -------------------------
     | Relaciones
     * ------------------------*/

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    /**
     * Relación con la request del paquete Redsys.
     * Si tu versión usa otro namespace/modelo, ajusta la clase aquí.
     */
    public function redsysRequest(): BelongsTo
    {
        // Suele ser este FQN en el paquete:
        return $this->belongsTo(Request::class, 'redsys_request_id');
    }

    /* -------------------------
     | Scopes útiles
     * ------------------------*/

    public function scopeForGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    public function scopeForOrderRef($query, string $orderRef)
    {
        return $query->where('gateway_order_ref', $orderRef);
    }

    /* -------------------------
     | Helpers
     * ------------------------*/

    /**
     * Fusiona el payload actual con nuevos datos del gateway.
     */
    public function mergePayload(array $data): void
    {
        $current = $this->gateway_payload ?? [];
        $this->gateway_payload = array_merge($current, $data);
    }
}
