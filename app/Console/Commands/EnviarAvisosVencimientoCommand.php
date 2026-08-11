<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Comercial;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class EnviarAvisosVencimientoCommand extends Command
{
    protected $signature = 'caducidad:enviar-avisos';
    protected $description = 'Envía avisos de caducidad de pólizas a comerciales (30, 15 y 1 día antes)';

    public function handle()
    {
        $this->info('Iniciando envío de avisos de caducidad...');

        $diasAviso = [30, 15, 1];
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
                ->where('tipo_de_pago', '!=', 'Tarjeta de crédito')
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
