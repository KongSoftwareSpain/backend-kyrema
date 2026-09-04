<?php

namespace App\Console\Commands;

use App\Models\Payments\Pago;
use App\Models\Payments\PaymentGatewayLink;
use App\Services\Payments\ProductoTableResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purga los productos/anexos "pendiente_pago" cuyo token de bridge caducó sin que
 * Redsys llegara a notificar el resultado (el cliente cerró la pestaña, se le fue
 * la conexión en kyrema.org, etc.). Sin esto quedarían huérfanos para siempre.
 */
class LimpiarPagosRedsysPendientesCommand extends Command
{
    protected $signature = 'redsys:limpiar-pendientes';
    protected $description = 'Borra productos/anexos pendiente_pago cuyo token de pago con tarjeta caducó sin confirmación de Redsys';

    public function handle(ProductoTableResolver $resolver)
    {
        $links = PaymentGatewayLink::forGateway('redsys')
            ->whereNotNull('access_token')
            ->whereNull('token_used_at')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<', now())
            ->get();

        $borrados = 0;

        foreach ($links as $link) {
            $pago = Pago::find($link->pago_id);

            if (!$pago || $pago->estado !== Pago::STATUS_PENDING) {
                continue;
            }

            if ($pago->producto_id && $pago->letras_identificacion) {
                $tabla = $resolver->tableName($pago->letras_identificacion);

                if ($tabla && Schema::hasTable($tabla)) {
                    foreach ($resolver->anexoTableNames($pago->letras_identificacion) as $anexoTabla) {
                        DB::table($anexoTabla)->where('producto_id', $pago->producto_id)->delete();
                    }
                    DB::table($tabla)->where('id', $pago->producto_id)->delete();
                }
            }

            $pago->update(['estado' => Pago::STATUS_FAILED, 'response_message' => 'Token de pago caducado sin confirmación']);
            $link->update(['gateway_status' => 'expired']);
            $borrados++;
        }

        $this->info("Pagos con tarjeta abandonados limpiados: {$borrados}");
    }
}
