<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use App\Models\TipoProducto;
use App\Mail\PreRenewalMail;
use App\Mail\RenewalSuccessMail;
use App\Services\InsuranceRenewalService;
use Carbon\Carbon;

class ProcessInsuranceRenewals extends Command
{
    protected $signature = 'insurances:process-renewals {--date= : The date to run the process for (Y-m-d)} {--test-email= : Send all emails to this address instead of the real clients (Safe mode)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process automatic insurance renewals, sending pre-notifications and generating new policies.';

    protected $renewalService;

    /**
     * Create a new command instance.
     */
    public function __construct(InsuranceRenewalService $renewalService)
    {
        parent::__construct();
        $this->renewalService = $renewalService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dateStr = $this->option('date');
        $today = $dateStr ? Carbon::parse($dateStr) : Carbon::today();
        $t30 = $today->copy()->addDays(30);

        $this->info("Procesando renovaciones para la fecha: " . $today->format('Y-m-d'));

        // Obtener todos los tipos de productos (tablas)
        $tiposProductos = TipoProducto::activos()
            ->whereNotNull('letras_identificacion')
            ->get();

        foreach ($tiposProductos as $tipo) {
            $tableName = strtolower($tipo->letras_identificacion);

            if (!\Illuminate\Support\Facades\Schema::hasTable($tableName)) {
                $this->warn("La tabla {$tableName} no existe, saltando...");
                continue;
            }

            $this->info("Analizando {$tableName}...");

            try {
                // 1. NOTIFICACIÓN 30 DÍAS
                $this->processPreRenewals($tableName, $tipo, $t30);

                // 2. RENOVACIÓN DÍA CERO
                $this->processRenewals($tableName, $tipo, $today);
            } catch (\Exception $e) {
                Log::error("Error procesando renovaciones en tabla {$tableName}: " . $e->getMessage());
                $this->error("Error en {$tableName}: " . $e->getMessage());
            }
        }

        $this->info("Proceso completado.");
    }

    private function processPreRenewals($tableName, $tipo, $dateTarget)
    {
        // OBTENEMOS LOS PRODUCTOS QUE CADUCAN DENTRO DE 30 DIAS
        $productsToNotify = DB::table($tableName)
            ->whereDate('fecha_de_fin', $dateTarget->format('Y-m-d'))
            ->where(function ($q) {
                // Omitir los ya anulados
                $q->whereNull('anulado')
                    ->orWhere('anulado', 0)
                    ->orWhere('anulado', false);
            })
            ->get();

        $testEmail = $this->option('test-email');

        foreach ($productsToNotify as $product) {
            $emailOriginal = property_exists($product, 'email') ? $product->email : null;
            $destino = $testEmail ? $testEmail : $emailOriginal;

            if (!empty($destino)) {
                $mensajeAviso = $testEmail ? " (MODO PRUEBA: Redirigido desde {$emailOriginal})" : "";
                $this->info("Enviando aviso de renovación a: {$destino}{$mensajeAviso} (ID original: {$product->id})");
                try {
                    Mail::to($destino)->send(new PreRenewalMail($product, $tipo->letras_identificacion));
                } catch (\Exception $e) {
                    Log::error("Error enviando PreRenewalMail a {$destino}: " . $e->getMessage());
                }
            } else {
                $this->warn("Producto ID {$product->id} en {$tableName} no tiene email para notificar.");
            }
        }
    }

    private function processRenewals($tableName, $tipo, $dateTarget)
    {
        // OBTENEMOS LOS PRODUCTOS QUE CADUCAN HOY
        $query = DB::table($tableName)
            ->whereDate('fecha_de_fin', $dateTarget->format('Y-m-d'))
            ->where(function ($q) {
                // Omitir los ya anulados
                $q->whereNull('anulado')
                    ->orWhere('anulado', 0)
                    ->orWhere('anulado', false);
            });

        // Omitir los ya renovados: si no, una segunda pasada del comando duplicaría
        // la póliza hija y, en giro bancario, el adeudo recurrente. Se filtra aquí
        // en vez de reventar en el servicio para que sea un salto limpio y no un
        // error en el log.
        if (Schema::hasColumn($tableName, 'renovado')) {
            $query->where(function ($q) {
                $q->whereNull('renovado')
                    ->orWhere('renovado', 0)
                    ->orWhere('renovado', false);
            });
        }

        $productsToRenew = $query->get();

        $testEmail = $this->option('test-email');

        foreach ($productsToRenew as $oldProduct) {
            $this->info("Renovando producto ID: {$oldProduct->id}");
            try {
                // Generar nuevo registro y PDF
                $newProduct = $this->renewalService->renewInsurance($tipo->letras_identificacion, $oldProduct->id);

                $emailOriginal = property_exists($newProduct, 'email') ? $newProduct->email : null;
                $destino = $testEmail ? $testEmail : $emailOriginal;

                // Enviar correo de éxito
                if (!empty($destino)) {
                    $mensajeAviso = $testEmail ? " (MODO PRUEBA: Redirigido desde {$emailOriginal})" : "";
                    $this->info("Enviando correo de éxito a: {$destino}{$mensajeAviso}");
                    Mail::to($destino)->send(new RenewalSuccessMail($newProduct, $tipo->letras_identificacion));
                }
            } catch (\Exception $e) {
                Log::error("Error renovando producto ID {$oldProduct->id} en tabla {$tableName}: " . $e->getMessage());
                $this->error("Fallo la renovación del ID: {$oldProduct->id}");
            }
        }
    }
}
