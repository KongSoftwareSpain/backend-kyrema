<?php

namespace App\Http\Controllers;

use App\Models\Payments\GiroBancario;
use App\Models\Payments\Pago;
use Illuminate\Http\Request;
use App\Models\RemesaDescarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use App\Services\Remesas\Q19Generator;
use Illuminate\Support\Facades\Config;


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
            'residente'             => 'nullable|string|in:S,N',
            'referencia_mandato'    => 'required|string',
            'referencia_adeudo'     => 'required|string',
            'tipo_adeudo'           => 'required|in:FRST,RCUR,OOFF,FNAL',
            'concepto'              => 'required|string',

            'letras_identificacion' => 'required|string',
            'fecha'                 => 'required|date',

            'sociedad_id'           => 'nullable|integer|exists:sociedad,id',
        ]);

        // Obtener el último código de producto generado
        $tableDatePrefix = Carbon::now()->format('mY');

        // Obtén el prefijo desde la configuración
        $prefijo = strtolower(Config::get('app.prefijo_tipo_producto'));

        // Elimina el prefijo del código
        $codigoPorTipoProducto = str_replace($prefijo, '', strtolower($validated['letras_identificacion']));

        // Construir el nuevo código de producto
        $newCodigoProducto = $tableDatePrefix . strtoupper($codigoPorTipoProducto) . $validated['referencia'];

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
            'sociedad_id' => 'required|exists:sociedad,id',
            'tipo_pago_id' => 'required|exists:tipos_pago,id',
            'comercial_id' => 'required|exists:comercial,id',
        ]);

        // Buscar giros relacionados a pagos filtrados por sociedad y tipo
        $giros = GiroBancario::whereBetween('created_at', [
            Carbon::parse($validated['desde'])->format('Y-m-d\TH:i:s'),
            Carbon::parse($validated['hasta'])->addDay()->format('Y-m-d\TH:i:s')
        ])
            ->whereHas('pago', function ($query) use ($validated) {
                $query->where('sociedad_id', $validated['sociedad_id']);
            })
            ->get();

        if ($giros->isEmpty()) {
            return response()->json(['message' => 'No hay giros en ese rango'], 404);
        }

        // Datos del acreedor (empresa)
        $empresa = [
            'nombre' => 'Nombre SL',
            'iban' => 'ES9121000418450200051332',
            'bic' => 'CAIXESBBXXX',
            'identificador_sepa' => 'ES21ZZZB12345678',
        ];

        $referencia = 'REM_' . now()->format('YmdHis');
        $fechaCobro = $giros->first()->fecha_cobro;

        // Generar XML
        $xml = app(Q19Generator::class)->generar($giros, $empresa, $referencia, $fechaCobro);
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
            'filtro.sociedad_id' => 'required|exists:sociedad,id',
            'filtro.tipo_pago_id' => 'required|exists:tipos_pago,id',
        ]);

        $updated = GiroBancario::whereBetween('created_at', [
            Carbon::parse($validated['filtro']['desde'])->format('Y-m-d\TH:i:s'),
            Carbon::parse($validated['filtro']['hasta'])->format('Y-m-d\TH:i:s'),
        ])
            ->whereHas('pago', function ($query) use ($validated) {
                $query->where('sociedad_id', $validated['filtro']['sociedad_id']);
            })
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
}
