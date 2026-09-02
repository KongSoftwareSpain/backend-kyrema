<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Comercial;
use App\Models\ConfiguracionAvisoCaducidad;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class EnviarAvisosVencimientoCommand extends Command
{
    protected $signature = 'caducidad:enviar-avisos';
    protected $description = 'Envía avisos de caducidad de pólizas a comerciales (días configurables desde configuracion_avisos_caducidad)';

    public function handle()
    {
        $config = ConfiguracionAvisoCaducidad::actual();

        if (!$config->activo) {
            $this->info('Avisos de caducidad desactivados en configuración. No se envía nada.');
            return;
        }

        $this->info('Iniciando envío de avisos de caducidad...');

        $diasAviso = $config->dias_aviso ?: [30, 15, 1];
        $totalEnviados = 0;

        foreach ($diasAviso as $dias) {
            $totalEnviados += $this->enviarAvisosPorDias($dias);
        }

        $this->info("✅ Avisos enviados: $totalEnviados");
    }

    private function enviarAvisosPorDias(int $dias): int
    {
        $fechaObjetivo = Carbon::now()->addDays($dias)->format('Y-m-d');
        $enviados = 0;

        $tiposProducto = DB::table('tipo_producto')->get();

        foreach ($tiposProducto as $tipo) {
            $tabla = strtolower($tipo->letras_identificacion);

            if (!Schema::hasTable($tabla)) {
                continue;
            }

            $seguros = DB::table($tabla)
                ->whereDate('fecha_de_fin', $fechaObjetivo)
                ->where('anulado', false)
                // Excluir del aviso a quien paga con tarjeta.
                //
                // 'Tarjeta de crédito' era el nombre del seeder original de 2024,
                // pero el valor que se guarda en las tablas de producto es
                // 'Tarjeta' (ver mapearTipoPago() en los comandos de migración),
                // así que la exclusión no descartaba a nadie y los clientes de
                // tarjeta recibían el aviso igual. Se comprueban las dos formas
                // para cubrir también cualquier registro antiguo.
                //
                // El whereNull es necesario: en SQL, NULL NOT IN (...) no es
                // cierto sino desconocido, así que una póliza sin forma de pago
                // quedaría descartada del aviso en silencio. Sin forma de pago
                // no es pago con tarjeta, y debe avisarse.
                ->where(function ($q) {
                    $q->whereNull('tipo_de_pago')
                        ->orWhereNotIn('tipo_de_pago', ['Tarjeta', 'Tarjeta de crédito']);
                })
                ->get();

            foreach ($seguros as $seguro) {
                if ($this->yaFueEnviado($tabla, $seguro->id, $seguro->comercial_creador_id, $dias)) {
                    continue;
                }

                $comercial = Comercial::find($seguro->comercial_creador_id);

                if (!$comercial || !$comercial->email) {
                    $this->warn("Comercial sin email: ID {$seguro->comercial_creador_id}");
                    continue;
                }

                $this->enviarEmail($comercial->email, $seguro->codigo_producto, $dias);

                DB::table('avisos_caducidad')->insert([
                    'letras_identificacion' => $tabla,
                    'producto_id' => $seguro->id,
                    'comercial_id' => $seguro->comercial_creador_id,
                    'dias_aviso' => $dias,
                    'fecha_aviso_enviado' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->info("✓ Aviso enviado: Póliza {$seguro->codigo_producto} - {$dias} días - {$comercial->email}");
                $enviados++;
            }
        }

        return $enviados;
    }

    private function yaFueEnviado(string $tabla, int $productoId, int $comercialId, int $dias): bool
    {
        return DB::table('avisos_caducidad')
            ->where('letras_identificacion', $tabla)
            ->where('producto_id', $productoId)
            ->where('comercial_id', $comercialId)
            ->where('dias_aviso', $dias)
            ->exists();
    }

    private function enviarEmail(string $email, string $numeroPóliza, int $días): void
    {
        $asunto = "Aviso de vencimiento de póliza";
        $mensaje = "La póliza con número: $numeroPóliza está próxima a caducar. Tienes $días días para renovar tu póliza, de lo contrario acabará la cobertura.";

        try {
            Mail::raw($mensaje, function ($mail) use ($email, $asunto) {
                $mail->to($email)->subject($asunto);
            });
        } catch (\Exception $e) {
            $this->error("Error enviando email a $email: " . $e->getMessage());
        }
    }
}
