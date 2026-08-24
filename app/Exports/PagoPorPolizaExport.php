<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exportador para las formas de pago que NO generan registro en la tabla pagos.
 *
 * Solo el giro bancario (RemesaController) y la tarjeta cobrada por la pasarela
 * (RedsysInsiteController) escriben en `pagos`. Una póliza pagada en efectivo,
 * por transferencia o domiciliada se guarda con su `tipo_de_pago` en la propia
 * tabla del producto y con `pago_id` a null, sin dejar rastro en `pagos`. Buscar
 * esos cobros en `pagos` devolvía siempre cero filas y el informe salía vacío.
 *
 * Aquí se recorren las tablas de producto y se filtra por el nombre visible del
 * tipo de pago, que es lo que se almacena en esa columna ('Domiciliación',
 * 'Efectivo'...), no el código de tipos_pago.
 */
class PagoPorPolizaExport implements PagoExportInterface
{
    /**
     * Columnas que el informe usa si existen. Las tablas de producto se generan
     * a medida por tipo, así que ninguna se da por supuesta: se comprueban
     * contra el esquema antes de seleccionarlas.
     */
    private const COLUMNAS_OPCIONALES = [
        'codigo_producto', 'dni', 'nombre_socio', 'apellido_1', 'apellido_2',
        'precio_total', 'fecha_de_emision', 'fecha_de_inicio', 'fecha_de_fin',
        'tipo_de_pago', 'anulado', 'sociedad_id', 'comercial_id', 'pago_id',
    ];

    public function __construct(private string $nombreTipoPago)
    {
    }

    public function cabeceras(): array
    {
        return [
            'Código', 'Producto', 'Tipo de pago', 'Importe', 'Cobro registrado',
            'Fecha de emisión', 'Fecha de inicio', 'Fecha de fin',
            'DNI', 'Nombre', 'Sociedad', 'Comercial', 'Anulada',
        ];
    }

    public function getPagos(int $sociedadId, ?string $desde = null, ?string $hasta = null): Collection
    {
        $filas = new Collection();

        foreach ($this->tablasDeProducto() as $tipoProducto) {
            $tabla = strtolower($tipoProducto->letras_identificacion);

            if (!Schema::hasTable($tabla)) {
                continue;
            }

            $columnas = Cache::remember(
                "schema_cols_{$tabla}",
                3600,
                fn () => Schema::getColumnListing($tabla)
            );

            // Sin esta columna la tabla no sabe distinguir formas de pago: no
            // tiene nada que aportar al informe.
            if (!in_array('tipo_de_pago', $columnas)) {
                continue;
            }

            $filas = $filas->concat(
                $this->filasDeTabla($tabla, $tipoProducto, $columnas, $sociedadId, $desde, $hasta)
            );
        }

        return $filas->sortByDesc('_orden')->values()->map(function (array $fila) {
            unset($fila['_orden']);

            return $fila;
        });
    }

    /**
     * Tipos de producto con tabla propia.
     *
     * Se excluyen los subproductos (padre_id) porque sus datos viven en la tabla
     * del padre y se contarían dos veces, y los anexos
     * (tipo_producto_asociado) porque no son pólizas y no llevan forma de pago.
     */
    private function tablasDeProducto(): Collection
    {
        return DB::table('tipo_producto')
            ->whereNull('padre_id')
            ->whereNull('tipo_producto_asociado')
            ->get();
    }

    private function filasDeTabla(
        string $tabla,
        $tipoProducto,
        array $columnas,
        int $sociedadId,
        ?string $desde,
        ?string $hasta
    ): Collection {
        $tiene = fn (string $columna) => in_array($columna, $columnas);

        // Si se pide una sociedad concreta y la tabla no sabe a qué sociedad
        // pertenece cada póliza, no se puede filtrar: se descarta entera antes
        // que arriesgarse a colar pólizas de otra sociedad en el informe.
        if ($sociedadId !== 0 && !$tiene('sociedad_id')) {
            return new Collection();
        }

        $select = ["{$tabla}.id"];
        foreach (self::COLUMNAS_OPCIONALES as $columna) {
            if ($tiene($columna)) {
                $select[] = "{$tabla}.{$columna}";
            }
        }

        $query = DB::table($tabla)->where("{$tabla}.tipo_de_pago", $this->nombreTipoPago);

        if ($tiene('sociedad_id')) {
            $select[] = 'sociedad.nombre as sociedad_nombre';
            $query->leftJoin('sociedad', 'sociedad.id', '=', "{$tabla}.sociedad_id");

            if ($sociedadId !== 0) {
                $query->where("{$tabla}.sociedad_id", $sociedadId);
            }
        }

        if ($tiene('comercial_id')) {
            $select[] = 'comercial.nombre as comercial_nombre';
            $query->leftJoin('comercial', 'comercial.id', '=', "{$tabla}.comercial_id");
        }

        // pago_id solo se rellena cuando el cobro dejó registro real: la pasarela
        // (RedsysInsiteController) o el mandato de giro (RemesaController). En el
        // resto de pólizas tipo_de_pago es la forma de pago declarada en el alta,
        // sin constancia de que el dinero entrara. Traer el estado del pago es lo
        // que permite distinguir una cosa de la otra en el informe.
        if ($tiene('pago_id')) {
            $select[] = 'pagos.estado as pago_estado';
            $query->leftJoin('pagos', 'pagos.id', '=', "{$tabla}.pago_id");
        }

        // La fecha del informe es la de emisión, que es cuando se cobró la
        // póliza. Si el producto no la tiene se cae a la de inicio de cobertura.
        $columnaFecha = $tiene('fecha_de_emision')
            ? 'fecha_de_emision'
            : ($tiene('fecha_de_inicio') ? 'fecha_de_inicio' : null);

        if ($columnaFecha) {
            if ($desde) {
                $query->whereDate("{$tabla}.{$columnaFecha}", '>=', $desde);
            }
            if ($hasta) {
                $query->whereDate("{$tabla}.{$columnaFecha}", '<=', $hasta);
            }
        }

        return collect($query->select($select)->get())
            ->map(fn ($poliza) => $this->formatear($poliza, $tipoProducto, $columnaFecha));
    }

    private function formatear($poliza, $tipoProducto, ?string $columnaFecha): array
    {
        $fechaOrden = $columnaFecha ? ($poliza->{$columnaFecha} ?? null) : null;

        return [
            'Código' => $poliza->codigo_producto ?? '',
            'Producto' => $tipoProducto->nombre ?? $tipoProducto->letras_identificacion,
            'Tipo de pago' => $this->nombreTipoPago,
            'Importe' => isset($poliza->precio_total)
                ? number_format((float) $poliza->precio_total, 2, ',', '.')
                : '',
            'Cobro registrado' => $this->estadoDelCobro($poliza),
            'Fecha de emisión' => $this->fecha($poliza->fecha_de_emision ?? null),
            'Fecha de inicio' => $this->fecha($poliza->fecha_de_inicio ?? null),
            'Fecha de fin' => $this->fecha($poliza->fecha_de_fin ?? null),
            'DNI' => $poliza->dni ?? '',
            'Nombre' => $this->nombreCompleto($poliza),
            'Sociedad' => $poliza->sociedad_nombre ?? '',
            'Comercial' => $poliza->comercial_nombre ?? '',
            'Anulada' => $this->siNo($poliza->anulado ?? null),
            // Auxiliar para ordenar el conjunto de todas las tablas; se descarta
            // antes de escribir el CSV.
            '_orden' => $fechaOrden ? Carbon::parse($fechaOrden)->format('Y-m-d') : '',
        ];
    }

    /**
     * Distingue el cobro con registro real del que solo consta como forma de
     * pago declarada en la póliza.
     *
     * "Sin registro" no significa impagado: puede ser un cobro por datáfono o
     * una póliza importada del sistema antiguo. Significa que la aplicación no
     * tiene constancia de la transacción, y por tanto que ese importe no debe
     * darse por cobrado sin comprobarlo aparte.
     */
    private function estadoDelCobro($poliza): string
    {
        if (empty($poliza->pago_id ?? null)) {
            return 'Sin registro';
        }

        $estado = $poliza->pago_estado ?? null;

        return $estado ? ucfirst($estado) : 'Registrado';
    }

    private function nombreCompleto($poliza): string
    {
        $partes = array_filter([
            $poliza->nombre_socio ?? null,
            $poliza->apellido_1 ?? null,
            $poliza->apellido_2 ?? null,
        ]);

        return trim(implode(' ', $partes));
    }

    private function fecha($valor): string
    {
        return $valor ? Carbon::parse($valor)->format('d/m/Y') : '';
    }

    private function siNo($valor): string
    {
        return (!empty($valor) && $valor != '0') ? 'Sí' : 'No';
    }
}
