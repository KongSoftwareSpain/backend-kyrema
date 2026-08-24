<?php

namespace App\Http\Controllers\Payments;

use App\Models\Payments\GiroBancario;
use App\Models\Payments\Pago;
use App\Models\Sociedad;
use Illuminate\Http\Request;
use App\Models\RemesaDescarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use App\Services\Remesas\Q19Generator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;


class RemesaController extends Controller
{

    public function storeGiroBancario(Request $request)
    {
        $validated = $request->validate([
            'referencia'            => 'required|string',

            'nombre_cliente'        => 'required|string',
            'dni'                   => 'required|string',
            'importe'               => 'required|numeric',
            'fecha_firma_mandato'   => 'required|date',
            'iban_cliente'          => 'required|string',
            'auxiliar'              => 'nullable|string',
            'sociedad'              => 'nullable|string',
            'residente'             => 'nullable|string|in:S,N',
            'referencia_mandato'    => 'required|string',
            'referencia_adeudo'     => 'required|string',
            'tipo_adeudo'           => 'required|in:FRST,RCUR,OOFF,FNAL',
            'concepto'              => 'required|string',

            'letras_identificacion' => 'required|string',
            'fecha'                 => 'required|date',

            'sociedad_id'           => 'nullable|integer|exists:sociedad,id',
        ]);

        // Prefijo de fecha (mes+año). Ej: 062026
        $tableDatePrefix = Carbon::now()->format('mY');

        // La referencia recibida ya incluye las siglas del producto (la genera
        // ReferenceService::generarReferencia como "SIGLAS + número", p.ej. "SJK0000005"),
        // por lo que aquí solo anteponemos el prefijo de fecha. Antes se volvían a
        // concatenar las siglas y quedaban duplicadas (062026SJKSJK0000005).
        $newCodigoProducto = $tableDatePrefix . strtoupper($validated['referencia']);

        $validated['referencia'] = $newCodigoProducto;

        // Crear registro en la tabla general de pagos
        $pago = Pago::create([
            'referencia'            => $validated['referencia'],
            'letras_identificacion' => $validated['letras_identificacion'],

            'tipo_pago'             => 'giro_bancario',
            'monto'                 => $validated['importe'],
            'fecha'                 => $validated['fecha'],
            'estado'                => 'pendiente', // 'mandado' cuando se descarga el XML
            'sociedad_id'           => $validated['sociedad_id'] ?? null,
        ]);

        // Crear giro bancario asociado
        $giro = GiroBancario::create([
            'pago_id'               => $pago->id,
            'referencia'            => $validated['referencia'],
            'nombre_cliente'        => $validated['nombre_cliente'],
            'dni'                   => $validated['dni'] ?? null,
            'importe'               => $validated['importe'],
            'fecha_firma_mandato'   => $validated['fecha_firma_mandato'],
            'iban_cliente'          => $validated['iban_cliente'],
            'auxiliar'              => $validated['auxiliar'] ?? null,
            'sociedad'              => $validated['sociedad'] ?? null,
            'residente'             => $validated['residente'] ?? 'S',
            'referencia_mandato'    => $validated['referencia_mandato'],
            'referencia_adeudo'     => $validated['referencia_adeudo'],
            'tipo_adeudo'           => $validated['tipo_adeudo'],
            'concepto'              => $validated['concepto'],
        ]);

        return response()->json([
            'message' => 'Pago por giro bancario registrado correctamente',
            'giro'    => $giro,
            'pago'    => $pago,
        ]);
    }

    public function generarQ19(Request $request)
    {
        $validated = $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
            // Una remesa tiene un único acreedor, así que aquí no vale el 0
            // ("todas las sociedades") que sí admiten los informes: mezclaría
            // deudores de varias sociedades bajo un solo IBAN de cargo.
            'sociedad_id' => 'required|integer|min:1|exists:sociedad,id',
            'tipo_pago_id' => 'required|exists:tipos_pago,id',
            'comercial_id' => 'required|exists:comercial,id',
        ]);

        // Buscar giros relacionados a pagos filtrados por sociedad
        $giros = GiroBancario::with('pago')
            ->whereHas('pago', function ($query) use ($validated) {
                $query->where('sociedad_id', $validated['sociedad_id']);
            })
            ->get();

        // Filtrar por la fecha del pago (pagos.fecha), que siempre está poblada
        $giros = $giros->filter(function ($giro) use ($validated) {
            if (!$giro->pago || !$giro->pago->fecha) {
                return false;
            }
            $fecha = \Carbon\Carbon::parse($giro->pago->fecha);
            $desde = \Carbon\Carbon::parse($validated['desde'])->startOfDay();
            $hasta = \Carbon\Carbon::parse($validated['hasta'])->endOfDay();

            return $fecha->between($desde, $hasta);
        });

        if ($giros->isEmpty()) {
            return response()->json(['message' => 'No hay giros en ese rango'], 404);
        }

        // Datos del acreedor: salen de la sociedad (columnas añadidas en la
        // migración 2025_05_21_131103_add_datos_remesas_to_sociedad_table).
        // Hasta ahora iban fijos a un IBAN de ejemplo y el banco rechazaba el
        // fichero.
        $sociedad = Sociedad::find($validated['sociedad_id']);

        $empresa = [
            'nombre' => $sociedad->razon_social ?: $sociedad->nombre,
            'iban' => $sociedad->iban,
            'bic' => $sociedad->bic,
            'identificador_sepa' => $sociedad->id_acreedor_remesas,
        ];

        $camposVacios = array_keys(array_filter($empresa, fn ($valor) => blank($valor)));

        if ($camposVacios) {
            return response()->json([
                'message' => 'La sociedad no tiene completos los datos de remesa. '
                    . 'Rellena IBAN, BIC e identificador de acreedor SEPA en su ficha.',
                'campos_incompletos' => $camposVacios,
            ], 422);
        }

        $referencia = 'REM_' . now()->format('YmdHis');

        // fecha_cobro está casteada a date en el modelo: sin format() explícito
        // llegaba como "2026-09-01 00:00:00" y ReqdColltnDt sólo admite la fecha.
        $fechaCobro = $giros->first()->fecha_cobro;

        if (!$fechaCobro) {
            return response()->json([
                'message' => 'Los giros de este rango no tienen fecha de cobro asignada.',
            ], 422);
        }

        // Generar XML
        $xml = app(Q19Generator::class)->generar(
            $giros,
            $empresa,
            $referencia,
            Carbon::parse($fechaCobro)->format('Y-m-d')
        );
        $filename = "remesas/{$referencia}.xml";
        Storage::put($filename, $xml);

        // Guardar registro de descarga
        RemesaDescarga::create([
            'ruta_xml' => $filename,
            'fecha_inicio' => $validated['desde'],
            'fecha_fin' => $validated['hasta'],
            'descargado_en' => now()->format('Y-m-d\TH:i:s'),
            'id_comercial' => $validated['comercial_id'],
        ]);

        return response()->download(storage_path("app/{$filename}"), "{$referencia}.xml", [
            'Content-Type' => 'application/xml',
        ])->deleteFileAfterSend();
    }


    public function guardarFechaCobro(Request $request)
    {
        $validated = $request->validate([
            'fechaCobro' => 'required|date|after:today',
            'filtro' => 'required|array',
            'filtro.desde' => 'required|date',
            'filtro.hasta' => 'required|date|after_or_equal:filtro.desde',
            'filtro.sociedad_id' => 'required',
            'filtro.tipo_pago_id' => 'required|exists:tipos_pago,id',
        ]);

        Log::info('Guardar fecha de cobro', [
            'fechaCobro' => $validated['fechaCobro'],
            'filtro' => $validated['filtro'],
        ]);

        $desde = Carbon::parse($validated['filtro']['desde'])->format('Y-m-d\TH:i:s');
        $hasta = Carbon::parse($validated['filtro']['hasta'])->format('Y-m-d\TH:i:s');
        $sociedadId = $validated['filtro']['sociedad_id'];

        $giros = GiroBancario::with('pago')
            ->whereHas('pago', function ($query) use ($sociedadId) {
                if ($sociedadId != 0) {
                    $query->where('sociedad_id', $sociedadId);
                }
            })
            ->get();

        $idsToUpdate = $giros->filter(function ($giro) use ($desde, $hasta) {
            if (!$giro->pago || !$giro->pago->fecha) {
                return false;
            }
            $fecha = \Carbon\Carbon::parse($giro->pago->fecha);
            return $fecha->between(
                \Carbon\Carbon::parse($desde)->startOfDay(),
                \Carbon\Carbon::parse($hasta)->endOfDay()
            );
        })->pluck('id');

        $updated = GiroBancario::whereIn('id', $idsToUpdate)
            ->update(['fecha_cobro' => Carbon::parse($validated['fechaCobro'])]);


        return response()->json([
            'message' => 'Fecha de cobro actualizada correctamente.',
            'registros_actualizados' => $updated,
        ]);
    }


    public function addReferenceToPago(Request $request)
    {
        $validated = $request->validate([
            'pago_id' => 'required|integer',
            'referencia' => 'required|string',
        ]);

        // Actualizar el pago con la referencia
        $pago = Pago::find($validated['pago_id']);
        if (!$pago) {
            return response()->json(['message' => 'Pago no encontrado'], 404);
        }

        $pago->update(['referencia' => $validated['referencia']]);

        // Encontrar el GiroBancario asociado al pago
        $giro = GiroBancario::where('pago_id', $pago->id)->first();
        if (!$giro) {
            return response()->json(['message' => 'Giro bancario no encontrado'], 404);
        }

        // Actualizar el giro bancario con la referencia
        $giro->update(['referencia' => $validated['referencia']]);

        return response()->json([
            'message' => 'Referencia añadida correctamente al pago',
            'pago'    => $pago,
            'giro'    => $giro,
        ]);
    }

    public function getDescargas()
    {
        $descargas = RemesaDescarga::with('comercial')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($descargas, 200);
    }
}
