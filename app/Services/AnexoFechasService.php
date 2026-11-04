<?php

namespace App\Services;

use App\Services\Payments\ProductoTableResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regla de negocio (cliente): un anexo NO puede empezar antes que su seguro padre
 * (misma fecha o posterior) y SIEMPRE termina cuando termina el seguro padre.
 *
 * Los errores de regla se lanzan como \DomainException: el mensaje es apto para
 * mostrarse al usuario y el controlador lo devuelve como 422.
 */
class AnexoFechasService
{
    private const ISO = 'Y-m-d\TH:i:s';

    /** Columna "Fecha adhesión" de los anexos que la tienen (ANEXOS_Ap, ANEXOS_APORt, ANEXOS_KA...). */
    private const CAMPO_ADHESION = 'fecha_adhesión';

    public function __construct(private ProductoTableResolver $resolver)
    {
    }

    /**
     * Fechas definitivas de los anexos que se van a guardar, indexadas por su posición
     * en $anexos. Solo incluye los anexos cuyas fechas hay que (re)escribir:
     *  - altas: siempre;
     *  - ediciones: solo si el usuario ha cambiado alguna fecha. Así un anexo antiguo
     *    (anterior a esta regla) sigue siendo editable en el resto de campos.
     *
     * @param  array $anexos  Mismo formato que recibe AnexosController::conectarAnexosConProducto
     * @return array<int, array{fecha_de_inicio: string, fecha_de_fin: string, duracion: int}>
     * @throws \DomainException si alguna fecha incumple la regla
     */
    public function calcularFechasAnexos($idProducto, array $anexos): array
    {
        if (empty($anexos)) {
            return [];
        }

        $letras = $anexos[array_key_first($anexos)]['tipo_anexo']['letras_identificacion'] ?? null;
        $padre = $letras ? $this->fechasSeguroPadre($idProducto, $letras) : null;

        if (!$padre) {
            throw new \DomainException(
                "No se pudo determinar el seguro padre (producto {$idProducto}) para validar las fechas del anexo."
            );
        }

        $hoy = Carbon::today();
        $resultado = [];
        $tablasConAdhesion = [];

        foreach ($anexos as $i => $anexo) {
            $anexoId = $anexo['id'] ?? null;
            $formato = $anexo['formato'] ?? [];
            $tabla = strtolower($anexo['tipo_anexo']['letras_identificacion'] ?? '');

            $guardado = null;
            if ($anexoId && $tabla !== '' && Schema::hasTable($tabla)) {
                $guardado = DB::table($tabla)->where('id', $anexoId)->first() ?: null;
            }

            // Se valida SIEMPRE (altas y ediciones), antes de los "continue" de más abajo
            // que saltan los anexos sin cambio de fechas de inicio/fin.
            if (!isset($tablasConAdhesion[$tabla])) {
                $tablasConAdhesion[$tabla] = $tabla !== ''
                    && Schema::hasTable($tabla)
                    && Schema::hasColumn($tabla, self::CAMPO_ADHESION);
            }
            if ($tablasConAdhesion[$tabla]) {
                $this->validarFechaAdhesion(
                    $formato[self::CAMPO_ADHESION] ?? null,
                    $guardado,
                    $padre,
                    count($anexos) > 1 ? $i + 1 : null
                );
            }

            $inicioSolicitado = $formato['fecha_de_inicio'] ?? null;
            $finSolicitado = $formato['fecha_de_fin'] ?? null;
            $inicio = null;

            if ($anexoId) {
                if (!$guardado) {
                    continue; // tabla inexistente (el controlador lo reporta) o anexo que ya no existe
                }

                $cambiaInicio = !empty($inicioSolicitado)
                    && (empty($guardado->fecha_de_inicio) || !$this->mismoDia($inicioSolicitado, $guardado->fecha_de_inicio));
                $cambiaFin = !empty($finSolicitado)
                    && (empty($guardado->fecha_de_fin) || !$this->mismoDia($finSolicitado, $guardado->fecha_de_fin));

                if (!$cambiaInicio && !$cambiaFin) {
                    continue;
                }

                if (!$cambiaInicio) {
                    // Solo se ha intentado mover el fin, que es siempre el del padre:
                    // el inicio guardado no se toca.
                    if (empty($guardado->fecha_de_inicio)) {
                        continue;
                    }
                    $inicio = Carbon::parse($guardado->fecha_de_inicio);
                }
            }

            if ($inicio === null) {
                $inicio = $this->inicioEfectivo($inicioSolicitado, $padre, $hoy);
            }

            $resultado[$i] = [
                'fecha_de_inicio' => $inicio->format(self::ISO),
                'fecha_de_fin' => $padre['fin']->format(self::ISO),
                'duracion' => $this->diasEntre($inicio, $padre['fin']),
            ];
        }

        return $resultado;
    }

    /**
     * Comprueba que unas nuevas fechas del seguro padre dejan a todos sus anexos
     * activos dentro del periodo (ninguno antes del nuevo inicio ni después del nuevo fin).
     *
     * @throws \DomainException
     */
    public function validarNuevasFechasPadre(int $tipoProductoPadreId, $idProducto, Carbon $nuevoInicio, Carbon $nuevoFin): void
    {
        $minimo = $nuevoInicio->copy()->startOfDay()->format(self::ISO);
        $fin = $nuevoFin->format(self::ISO);
        $fuera = 0;

        foreach ($this->tablasAnexo($tipoProductoPadreId) as $tabla) {
            $query = DB::table($tabla)
                ->where('producto_id', $idProducto)
                ->where(function ($q) use ($minimo, $fin) {
                    $q->where('fecha_de_inicio', '<', $minimo)
                        ->orWhere('fecha_de_inicio', '>=', $fin);
                });

            if (Schema::hasColumn($tabla, 'anulado')) {
                $query->where('anulado', 0);
            }

            $fuera += $query->count();
        }

        if ($fuera > 0) {
            throw new \DomainException(
                "No se pueden cambiar las fechas del seguro: {$fuera} anexo(s) quedarían fuera de su periodo. "
                . 'Un anexo no puede empezar antes que su seguro padre ni empezar cuando éste ya ha terminado.'
            );
        }
    }

    /**
     * Alinea el fin de los anexos activos con el nuevo fin del seguro padre y vacía su
     * blob_name para que el certificado se regenere (comparten PDF con el padre).
     */
    public function ajustarFinAnexos(int $tipoProductoPadreId, $idProducto, Carbon $nuevoFin): void
    {
        $ahora = Carbon::now()->format(self::ISO);

        foreach ($this->tablasAnexo($tipoProductoPadreId) as $tabla) {
            if (!Schema::hasColumn($tabla, 'fecha_de_fin')) {
                continue;
            }

            $tieneDuracion = Schema::hasColumn($tabla, 'duracion');
            $tieneBlob = Schema::hasColumn($tabla, 'blob_name');
            $tieneUpdatedAt = Schema::hasColumn($tabla, 'updated_at');

            $query = DB::table($tabla)->where('producto_id', $idProducto);
            if (Schema::hasColumn($tabla, 'anulado')) {
                $query->where('anulado', 0);
            }

            foreach ($query->get(['id', 'fecha_de_inicio']) as $fila) {
                $cambios = ['fecha_de_fin' => $nuevoFin->format(self::ISO)];

                if ($tieneDuracion && !empty($fila->fecha_de_inicio)) {
                    $cambios['duracion'] = $this->diasEntre(Carbon::parse($fila->fecha_de_inicio), $nuevoFin);
                }
                if ($tieneBlob) {
                    $cambios['blob_name'] = '';
                }
                if ($tieneUpdatedAt) {
                    $cambios['updated_at'] = $ahora;
                }

                DB::table($tabla)->where('id', $fila->id)->update($cambios);
            }
        }
    }

    /**
     * Inicio y fin del seguro padre al que cuelga el anexo, o null si no se resuelve.
     *
     * @return array{inicio: Carbon, fin: Carbon}|null
     */
    private function fechasSeguroPadre($idProducto, string $letrasAnexo): ?array
    {
        $tabla = $this->resolver->parentTableNameForAnexo($letrasAnexo);
        if (!$tabla || !Schema::hasTable($tabla)) {
            return null;
        }

        $padre = DB::table($tabla)->where('id', $idProducto)->first();
        if (!$padre || empty($padre->fecha_de_inicio) || empty($padre->fecha_de_fin)) {
            return null;
        }

        return [
            'inicio' => Carbon::parse($padre->fecha_de_inicio),
            'fin' => Carbon::parse($padre->fecha_de_fin),
        ];
    }

    /**
     * Inicio definitivo (fecha y hora) de un anexo nuevo o con la fecha cambiada.
     * Misma convención de hora que los productos: fecha futura => 00:00:00, hoy o
     * pasada => hora actual. Sin fecha, hoy o el inicio del padre si éste es futuro.
     *
     * @throws \DomainException
     */
    private function inicioEfectivo($solicitado, array $padre, Carbon $hoy): Carbon
    {
        $inicioPadreDia = $padre['inicio']->copy()->startOfDay();

        if (empty($solicitado)) {
            $fecha = $hoy->copy()->max($inicioPadreDia);
        } else {
            try {
                $fecha = Carbon::parse($solicitado);
            } catch (\Throwable $e) {
                throw new \DomainException('La fecha de inicio del anexo no es válida.');
            }
        }

        if ($fecha->copy()->startOfDay()->lt($inicioPadreDia)) {
            throw new \DomainException(
                'La fecha de inicio del anexo (' . $fecha->format('d/m/Y') . ') no puede ser anterior a la fecha de inicio '
                . 'del seguro padre (' . $padre['inicio']->format('d/m/Y') . ').'
            );
        }

        $inicio = $fecha->toDateString() > $hoy->toDateString()
            ? $fecha->copy()->setTime(0, 0, 0)
            : $fecha->copy()->setTimeFromTimeString(Carbon::now()->format('H:i:s'));

        // Mismo día que el padre pero hora anterior a la suya: se iguala al instante del padre
        if ($inicio->lt($padre['inicio'])) {
            $inicio = $padre['inicio']->copy();
        }

        if ($inicio->gte($padre['fin'])) {
            throw new \DomainException(
                'El anexo no puede empezar el ' . $inicio->format('d/m/Y') . ': el seguro padre finaliza el '
                . $padre['fin']->format('d/m/Y') . '.'
            );
        }

        return $inicio;
    }

    /**
     * La fecha de adhesión es obligatoria y no puede ser anterior al inicio del seguro
     * padre (el mismo día vale). Se compara por día, sin hora.
     *
     * En un anexo ya guardado solo se exige si se toca este campo: quien no puede editarlo
     * (el front bloquea los anexos guardados a las sociedades no administradoras) reenvía
     * el anexo tal cual, y un anexo antiguo con la fecha en blanco o anterior al padre no
     * debe impedir guardar el resto del seguro.
     *
     * @param  object|null $guardado     fila actual del anexo, o null si es un alta
     * @param  int|null    $numeroAnexo  posición (1..n) para identificar el anexo en el mensaje cuando se envían varios
     * @throws \DomainException
     */
    private function validarFechaAdhesion($valor, $guardado, array $padre, ?int $numeroAnexo): void
    {
        $prefijo = $numeroAnexo ? "Anexo {$numeroAnexo}: " : '';

        if ($guardado !== null) {
            $anterior = $guardado->{self::CAMPO_ADHESION} ?? null;

            if (empty($valor) && empty($anterior)) {
                return;
            }
            try {
                if (!empty($valor) && !empty($anterior) && $this->mismoDia($valor, $anterior)) {
                    return;
                }
            } catch (\Throwable $e) {
                // valor ilegible: se deja pasar a la validación de abajo, que lo reporta
            }
        }

        if (empty($valor)) {
            throw new \DomainException($prefijo . 'La fecha de adhesión es obligatoria.');
        }

        try {
            $fecha = Carbon::parse($valor);
        } catch (\Throwable $e) {
            throw new \DomainException($prefijo . 'La fecha de adhesión no es válida.');
        }

        if ($fecha->toDateString() < $padre['inicio']->toDateString()) {
            throw new \DomainException(
                $prefijo . 'La fecha de adhesión (' . $fecha->format('d/m/Y') . ') no puede ser anterior a la fecha de '
                . 'incorporación al seguro (' . $padre['inicio']->format('d/m/Y') . ').'
            );
        }
    }

    /** Tablas ANEXOS_* del seguro padre que tienen las columnas necesarias para validar fechas. */
    private function tablasAnexo(int $tipoProductoPadreId): array
    {
        return DB::table('tipo_producto')
            ->where('tipo_producto_asociado', $tipoProductoPadreId)
            ->pluck('letras_identificacion')
            ->map(fn ($letras) => strtolower($letras))
            ->filter(fn ($tabla) => Schema::hasTable($tabla)
                && Schema::hasColumn($tabla, 'producto_id')
                && Schema::hasColumn($tabla, 'fecha_de_inicio'))
            ->values()
            ->all();
    }

    private function mismoDia($a, $b): bool
    {
        return Carbon::parse($a)->toDateString() === Carbon::parse($b)->toDateString();
    }

    private function diasEntre(Carbon $inicio, Carbon $fin): int
    {
        return (int) $inicio->copy()->startOfDay()->diffInDays($fin->copy()->startOfDay());
    }
}
