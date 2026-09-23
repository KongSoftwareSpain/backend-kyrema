<?php

namespace App\Services\Avisos;

use App\Models\Comercial;
use App\Models\PerfilEnvioAviso;
use App\Models\SocioExcluidoAviso;
use App\Models\TipoProducto;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Construye y ejecuta el envío de avisos de caducidad de un perfil.
 *
 * Centralizado aquí porque tanto el comando programado
 * (caducidad:enviar-avisos) como el botón de "forzar envío" de un perfil
 * necesitan exactamente la misma lógica de filtros, para no duplicarla y que
 * ambos caminos se comporten igual.
 */
class EnvioAvisosService
{
    /**
     * Ejecuta un perfil. En modo 'dias_antes' recorre cada día configurado;
     * en modo 'dia_mes' solo actúa si hoy es el día configurado, salvo que
     * $forzado sea true (el botón de "forzar" ignora esa comprobación de
     * fecha, pero no los demás filtros).
     *
     * @return array{enviados:int, fallidos:int}
     */
    public function ejecutarPerfil(PerfilEnvioAviso $perfil, bool $forzado = false, bool $ignorarDuplicados = false): array
    {
        if (!$perfil->incluir_productos_activos && !$perfil->incluir_productos_renovados) {
            return ['enviados' => 0, 'fallidos' => 0];
        }

        $sociosExcluidos = SocioExcluidoAviso::pluck('socio_id')->all();
        $sociosActivosDesde = $perfil->fecha_actividad_socio_desde
            ? $this->resolverSociosActivosDesde($perfil->fecha_actividad_socio_desde->format('Y-m-d'))
            : null;

        $enviados = 0;
        $fallidos = 0;

        if ($perfil->modo_tiempo === 'dia_mes') {
            if (!$forzado && (int) now()->day !== (int) $perfil->dia_envio_mensual) {
                return ['enviados' => 0, 'fallidos' => 0];
            }

            [$e, $f] = $this->ejecutarVentana($perfil, null, $forzado, $ignorarDuplicados, $sociosExcluidos, $sociosActivosDesde);
            $enviados += $e;
            $fallidos += $f;
        } else {
            foreach (($perfil->dias_aviso ?: []) as $diasAntes) {
                [$e, $f] = $this->ejecutarVentana($perfil, (int) $diasAntes, $forzado, $ignorarDuplicados, $sociosExcluidos, $sociosActivosDesde);
                $enviados += $e;
                $fallidos += $f;
            }
        }

        return ['enviados' => $enviados, 'fallidos' => $fallidos];
    }

    /**
     * @return array{0:int,1:int} [enviados, fallidos]
     */
    private function ejecutarVentana(
        PerfilEnvioAviso $perfil,
        ?int $diasAntes,
        bool $forzado,
        bool $ignorarDuplicados,
        array $sociosExcluidos,
        ?array $sociosActivosDesde
    ): array {
        $enviados = 0;
        $fallidos = 0;

        $sociedadesMarcadas = $perfil->sociedades()->pluck('sociedad.id')->all();
        $comercialesMarcados = $perfil->comerciales()->pluck('comercial.id')->all();
        $sociosMarcados = $perfil->socios()->pluck('socios.id')->all();
        $formasPagoMarcadas = $perfil->formasPago()->pluck('valor')->all();

        foreach ($this->tablasCandidatas($perfil) as $tabla) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }

            $query = DB::table($tabla)
                ->where(function ($q) {
                    $q->whereNull('anulado')->orWhere('anulado', false);
                });

            $this->aplicarFiltroEstado($query, $perfil, $tabla);
            $this->aplicarFiltroFecha($query, $tabla, $diasAntes);

            if ($formasPagoMarcadas && Schema::hasColumn($tabla, 'tipo_de_pago')) {
                $query->whereIn('tipo_de_pago', $formasPagoMarcadas);
            }

            if ($sociedadesMarcadas && Schema::hasColumn($tabla, 'sociedad_id')) {
                $query->whereIn('sociedad_id', $sociedadesMarcadas);
            }

            if ($comercialesMarcados && Schema::hasColumn($tabla, 'comercial_creador_id')) {
                $query->whereIn('comercial_creador_id', $comercialesMarcados);
            }

            if (Schema::hasColumn($tabla, 'socio_id')) {
                if ($sociosMarcados) {
                    $query->whereIn('socio_id', $sociosMarcados);
                }
                if ($sociosExcluidos) {
                    $query->whereNotIn('socio_id', $sociosExcluidos);
                }
                if ($sociosActivosDesde !== null) {
                    $query->whereIn('socio_id', $sociosActivosDesde);
                }
            }

            foreach ($query->get() as $producto) {
                $resultado = $this->procesarProducto($perfil, $tabla, $producto, $diasAntes, $forzado, $ignorarDuplicados);

                if ($resultado === null) {
                    continue;
                }

                $resultado ? $enviados++ : $fallidos++;
            }
        }

        return [$enviados, $fallidos];
    }

    /**
     * @return bool|null true = enviado, false = fallido, null = omitido (duplicado)
     */
    private function procesarProducto(
        PerfilEnvioAviso $perfil,
        string $tabla,
        object $producto,
        ?int $diasAntes,
        bool $forzado,
        bool $ignorarDuplicados
    ): ?bool {
        $comercialId = $producto->comercial_creador_id ?? null;

        if ($perfil->evitar_duplicados && !$ignorarDuplicados
            && $this->yaFueEnviado($perfil, $tabla, $producto->id, $comercialId, $diasAntes, $forzado)) {
            return null;
        }

        $comercial = $comercialId ? Comercial::find($comercialId) : null;

        if (!$comercial || !$comercial->email) {
            DB::table('avisos_caducidad_fallidos')->insert([
                'perfil_id' => $perfil->id,
                'letras_identificacion' => $tabla,
                'producto_id' => $producto->id,
                'comercial_id' => $comercialId,
                'email_destino' => null,
                'motivo_error' => 'Comercial sin email registrado.',
                'forzado' => $forzado,
                'created_at' => now(),
            ]);

            return false;
        }

        try {
            $this->enviarEmail($comercial->email, $producto->codigo_producto ?? (string) $producto->id, $diasAntes);
        } catch (\Throwable $e) {
            DB::table('avisos_caducidad_fallidos')->insert([
                'perfil_id' => $perfil->id,
                'letras_identificacion' => $tabla,
                'producto_id' => $producto->id,
                'comercial_id' => $comercialId,
                'email_destino' => $comercial->email,
                'motivo_error' => $e->getMessage(),
                'forzado' => $forzado,
                'created_at' => now(),
            ]);

            return false;
        }

        DB::table('avisos_caducidad')->insert([
            'perfil_id' => $perfil->id,
            'letras_identificacion' => $tabla,
            'producto_id' => $producto->id,
            'comercial_id' => $comercialId,
            'dias_aviso' => $diasAntes,
            'forzado' => $forzado,
            'fecha_aviso_enviado' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function aplicarFiltroEstado($query, PerfilEnvioAviso $perfil, string $tabla): void
    {
        $tieneRenovado = Schema::hasColumn($tabla, 'renovado');
        $tieneCaducado = Schema::hasColumn($tabla, 'caducado');

        $query->where(function ($estado) use ($perfil, $tieneRenovado, $tieneCaducado) {
            $huboActivos = false;

            if ($perfil->incluir_productos_activos) {
                $estado->where(function ($activos) use ($tieneRenovado, $tieneCaducado) {
                    if ($tieneCaducado) {
                        $activos->where(function ($q) {
                            $q->whereNull('caducado')->orWhere('caducado', false);
                        });
                    }
                    if ($tieneRenovado) {
                        $activos->where(function ($q) {
                            $q->whereNull('renovado')->orWhere('renovado', false);
                        });
                    }
                });
                $huboActivos = true;
            }

            if ($perfil->incluir_productos_renovados && $tieneRenovado) {
                $huboActivos
                    ? $estado->orWhere('renovado', true)
                    : $estado->where('renovado', true);
            }
        });
    }

    private function aplicarFiltroFecha($query, string $tabla, ?int $diasAntes): void
    {
        if (!Schema::hasColumn($tabla, 'fecha_de_fin')) {
            $query->whereRaw('1 = 0');
            return;
        }

        if ($diasAntes !== null) {
            $query->whereDate('fecha_de_fin', Carbon::now()->addDays($diasAntes)->format('Y-m-d'));
        } else {
            $query->whereMonth('fecha_de_fin', now()->month)->whereYear('fecha_de_fin', now()->year);
        }
    }

    private function yaFueEnviado(
        PerfilEnvioAviso $perfil,
        string $tabla,
        $productoId,
        $comercialId,
        ?int $diasAntes,
        bool $forzado
    ): bool {
        $query = DB::table('avisos_caducidad')
            ->where('perfil_id', $perfil->id)
            ->where('letras_identificacion', $tabla)
            ->where('producto_id', $productoId)
            ->where('comercial_id', $comercialId)
            ->where('forzado', $forzado);

        if ($diasAntes !== null) {
            $query->where('dias_aviso', $diasAntes);
        } else {
            $query->whereNull('dias_aviso')
                ->whereMonth('fecha_aviso_enviado', now()->month)
                ->whereYear('fecha_aviso_enviado', now()->year);
        }

        return $query->exists();
    }

    private function enviarEmail(string $email, string $numeroPoliza, ?int $diasAntes): void
    {
        $asunto = 'Aviso de vencimiento de póliza';

        $mensaje = $diasAntes !== null
            ? "La póliza con número: $numeroPoliza está próxima a caducar. Tienes $diasAntes días para renovar tu póliza, de lo contrario acabará la cobertura."
            : "La póliza con número: $numeroPoliza está próxima a caducar este mes. Renueva tu póliza cuanto antes, de lo contrario acabará la cobertura.";

        Mail::raw($mensaje, function ($mail) use ($email, $asunto) {
            $mail->to($email)->subject($asunto);
        });
    }

    private function tablasCandidatas(PerfilEnvioAviso $perfil): Collection
    {
        $tipos = $perfil->tiposProducto()->whereNotNull('letras_identificacion')->get();

        if ($tipos->isEmpty()) {
            $tipos = TipoProducto::whereNotNull('letras_identificacion')->get();
        }

        return $tipos->pluck('letras_identificacion')->map(fn ($letras) => strtolower($letras))->unique()->values();
    }

    private function resolverSociosActivosDesde(string $fecha): array
    {
        $ids = collect();

        $tablas = TipoProducto::whereNotNull('letras_identificacion')
            ->pluck('letras_identificacion')
            ->map(fn ($letras) => strtolower($letras))
            ->unique();

        foreach ($tablas as $tabla) {
            if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, 'socio_id') || !Schema::hasColumn($tabla, 'fecha_de_inicio')) {
                continue;
            }

            $ids = $ids->merge(
                DB::table($tabla)->whereNotNull('socio_id')->where('fecha_de_inicio', '>=', $fecha)->pluck('socio_id')
            );
        }

        return $ids->unique()->values()->all();
    }
}
