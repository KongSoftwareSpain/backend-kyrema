<?php

namespace App\Console\Commands;

use App\Models\Categoria;
use App\Models\Comercial;
use App\Models\Socio;
use App\Models\TipoProducto;
use App\Notifications\ProductExpiringNotice;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

class AvisarCaducidadProductos extends Command
{
    protected $signature = 'productos:avisar-caducidad
        {--dias= : Días de antelación (por defecto config(avisos.caducidad_dias))}
        {--dry-run : Solo mostrar qué se enviaría, sin despachar notificaciones}';

    protected $description = 'Avisa por email al comercial responsable de los productos próximos a caducar (fecha_de_fin).';

    public function handle(): int
    {
        $dias = (int) ($this->option('dias') ?? config('avisos.caducidad_dias'));
        $dryRun = (bool) $this->option('dry-run');

        $hoy = Carbon::now()->format('Y-m-d\TH:i:s');
        $limite = Carbon::now()->addDays($dias)->format('Y-m-d\TH:i:s');

        // Solo tipos "producto padre": tienen tabla física propia (los subproductos
        // reutilizan la tabla del padre, los anexos se excluyen por decisión de negocio).
        $tiposProducto = TipoProducto::activos()
            ->whereNull('padre_id')
            ->whereNull('tipo_producto_asociado')
            ->get();

        $porComercial = []; // [comercial_id => ['comercial' => Comercial, 'productos' => []]]

        foreach ($tiposProducto as $tipoProducto) {
            $nombreTabla = strtolower($tipoProducto->letras_identificacion);

            if (!Schema::hasTable($nombreTabla)) {
                continue;
            }

            $productos = DB::table($nombreTabla)
                ->whereNotNull('socio_id')
                ->where('anulado', false)
                ->whereBetween('fecha_de_fin', [$hoy, $limite])
                ->get();

            foreach ($productos as $producto) {
                $socio = Socio::find($producto->socio_id);
                if (!$socio || !$socio->categoria_id) {
                    continue;
                }

                $categoria = Categoria::with('comercialResponsable')->find($socio->categoria_id);
                $responsable = $categoria?->comercialResponsable;

                if (!$responsable || empty($responsable->email)) {
                    continue;
                }

                $porComercial[$responsable->id]['comercial'] ??= $responsable;
                $porComercial[$responsable->id]['productos'][] = [
                    'codigo_producto' => $producto->codigo_producto ?? null,
                    'nombre_producto' => $tipoProducto->nombre,
                    'fecha_de_fin' => $producto->fecha_de_fin,
                    'socio_nombre' => trim(($socio->nombre_socio ?? '') . ' ' . ($socio->apellido_1 ?? '')),
                ];
            }
        }

        if (empty($porComercial)) {
            $this->info("Sin productos próximos a caducar en los próximos {$dias} días.");
            return self::SUCCESS;
        }

        foreach ($porComercial as $data) {
            /** @var Comercial $comercial */
            $comercial = $data['comercial'];
            $productos = collect($data['productos']);

            $this->line("Comercial #{$comercial->id} ({$comercial->email}): {$productos->count()} producto(s).");

            if (!$dryRun) {
                Notification::send($comercial, new ProductExpiringNotice($comercial, $productos));
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . 'Aviso de caducidad procesado para ' . count($porComercial) . ' comercial(es).');

        return self::SUCCESS;
    }
}
