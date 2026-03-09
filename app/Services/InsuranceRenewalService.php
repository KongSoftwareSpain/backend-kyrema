<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Exception;

class InsuranceRenewalService
{
    protected $pdfBuilderService;

    public function __construct(PdfBuilderService $pdfBuilderService)
    {
        $this->pdfBuilderService = $pdfBuilderService;
    }

    /**
     * Realiza la renovación de un seguro y genera el nuevo certificado PDF.
     * Retorna el nuevo registro clonado.
     */
    public function renewInsurance($letrasIdentificacion, $idToRenew)
    {
        DB::beginTransaction();
        try {
            $tableName = strtolower($letrasIdentificacion);

            // Obtener el registro original
            $oldRecord = DB::table($tableName)->where('id', $idToRenew)->first();

            if (!$oldRecord) {
                throw new Exception("No se encontró el seguro ID {$idToRenew} en la tabla {$tableName}");
            }

            // Clonar los datos en un array asociativo
            $newData = (array) $oldRecord;

            // Eliminar la ID original para que genere una nueva
            unset($newData['id']);

            // Parsear fechas y asegurar el formato ISO-8601 en cualquier fecha heredada del objeto anterior
            foreach ($newData as $key => $value) {
                if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}(?:\.\d+)?$/', $value)) {
                    $newData[$key] = Carbon::parse($value)->format('Y-m-d\TH:i:s.000');
                }
            }

            // Actualizar fechas principales
            $oldFechaInicio = Carbon::parse($oldRecord->fecha_de_inicio);
            $oldFechaFin = Carbon::parse($oldRecord->fecha_de_fin);

            // Calcular diferencia en meses (normalmente 12 meses)
            $diffInMonths = $oldFechaInicio->diffInMonths($oldFechaFin);
            if ($diffInMonths <= 0) {
                $diffInMonths = 12; // fallback a 1 año
            }

            // Nueva fecha de inicio = antigua fecha fin + 1 día (o la mantenemos consecutiva, depende del negocio)
            // Según instrucciones: "sumando ese valor a la fecha actual"
            // Normalmente la nueva es: a partir de la fecha fin vieja, o si la renueva hoy, desde hoy. 
            // Si el cron se ejecuta en el día 0, fecha_de_fin == hoy.
            $newFechaInicio = Carbon::today();
            $newFechaFin = Carbon::today()->addMonths($diffInMonths);

            $newData['fecha_de_inicio'] = $newFechaInicio->format('Y-m-d\TH:i:s.000');
            $newData['fecha_de_fin'] = $newFechaFin->format('Y-m-d\TH:i:s.000');

            // Tiempos de creación
            $newData['created_at'] = Carbon::now()->format('Y-m-d\TH:i:s.000');
            $newData['updated_at'] = Carbon::now()->format('Y-m-d\TH:i:s.000');

            // Limpiar blob_name viejo, ya que vamos a generar uno nuevo
            $newData['blob_name'] = null;

            // Obtener el tipo de producto
            $tipoProducto = DB::table('tipo_producto')->where('letras_identificacion', $letrasIdentificacion)->first();
            if (property_exists($oldRecord, 'subproducto') && $oldRecord->subproducto !== null) {
                $tipoProducto = DB::table('tipo_producto')->where('id', $oldRecord->subproducto)->first();
            }

            // Nueva referencia (código de producto)
            $tableDatePrefix = Carbon::now()->format('mY');
            $referenciaService = new ReferenceService();
            $referencia = $referenciaService->generarReferencia($letrasIdentificacion);
            $newData['codigo_producto'] = $tableDatePrefix . $referencia;

            // Insertar el nuevo valor
            $newId = DB::table($tableName)->insertGetId($newData);

            // Relacionar Socio - Producto (si es necesario)
            if (isset($newData['socio_id'])) {
                $socioProductoExists = DB::table('socios_productos')
                    ->where('id_socio', $newData['socio_id'])
                    ->where('id_producto', $newId)
                    ->where('letras_identificacion', $letrasIdentificacion)
                    ->exists();

                if (!$socioProductoExists) {
                    \App\Models\SocioProducto::connectSocioAndProducto($newData['socio_id'], $newId, $letrasIdentificacion);
                }
            }

            // Llamar al servicio de PDF para generar el certificado en binario
            $pdfContent = $this->pdfBuilderService->generatePdf($letrasIdentificacion, $newId);

            // Generar un nombre aleatorio o estructurado para el blob
            $nombreSocio = $newData['nombre_socio'] ?? '';
            $apellido1 = $newData['apellido_1'] ?? '';
            $uuid = \Illuminate\Support\Str::uuid();
            $fileName = "{$newData['codigo_producto']}{$nombreSocio}{$apellido1}-{$uuid}.pdf";

            // Limpiar nombre archivo
            $fileName = preg_replace('/[^A-Za-z0-9._\-]/', '', $fileName);
            // Asegurarnos de usar lower para evitas problemas con azure
            $fileName = strtolower($fileName);

            // Si usamos local storage o Azure
            try {
                Storage::disk('azure')->put($letrasIdentificacion . '/' . $newData['codigo_producto'] . '/' . $fileName, $pdfContent);
            } catch (\Exception $e) {
                // Fallback to local storage if azure fails/is not configured (e.g., in local environment)
                Log::warning("Azure storage failed or not configured, falling back to default disk: " . $e->getMessage());
                Storage::put($letrasIdentificacion . '/' . $newData['codigo_producto'] . '/' . $fileName, $pdfContent);
            }

            // Actualizar la BBDD con el nombre del blob
            DB::table($tableName)->where('id', $newId)->update(['blob_name' => $fileName]);

            $newData['id'] = $newId;
            $newData['blob_name'] = $fileName;

            DB::commit();

            return (object) $newData;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('InsuranceRenewalService Error: ' . $e->getMessage());
            throw $e;
        }
    }
}
