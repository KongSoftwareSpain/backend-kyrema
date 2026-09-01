<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\CampoController;
use App\Models\Compania;
use Mpdf\Mpdf;
use Carbon\Carbon;
use Exception;

class PdfBuilderService
{
    /**
     * Build the PDF for a given product and upload or return it.
     * Retorna el contenido binario del PDF generado.
     */
    public function generatePdf($letrasIdentificacion, $id)
    {
        try {
            if (!$id) {
                throw new Exception('ID no proporcionado');
            }

            // VALORES DEL PRODUCTO
            $valores = DB::table($letrasIdentificacion)->where('id', $id)->first();
            if (!$valores) {
                throw new Exception('Valores no encontrados');
            }

            // TIPO PRODUCTO
            if (property_exists($valores, 'subproducto') && $valores->subproducto !== null) {
                $tipoProducto = DB::table('tipo_producto')->where('id', $valores->subproducto)->first();
            } else {
                $tipoProducto = DB::table('tipo_producto')->where('letras_identificacion', $letrasIdentificacion)->first();
            }

            if (!$tipoProducto) {
                throw new Exception('Tipo de producto no encontrado');
            }

            // PLANTILLAS (BACKGROUNDS)
            $plantillaPaths = [
                $valores->plantilla_path_1,
                $valores->plantilla_path_2,
                $valores->plantilla_path_3,
                $valores->plantilla_path_4,
                $valores->plantilla_path_5,
                $valores->plantilla_path_6,
                $valores->plantilla_path_7,
                $valores->plantilla_path_8,
            ];

            $plantillaFullPaths = [];
            foreach ($plantillaPaths as $path) {
                if ($path !== null && $path !== '') {
                    // Posibles rutas en disco
                    $possiblePaths = [
                        storage_path('app/public/' . $path),
                        storage_path('app/' . $path),
                        public_path('storage/' . $path),
                        public_path($path),
                    ];

                    foreach ($possiblePaths as $fullPath) {
                        if (file_exists($fullPath)) {
                            $plantillaFullPaths[] = $fullPath;
                            break;
                        }
                    }
                }
            }

            // CAMPOS
            $campos = CampoController::fetchCamposCertificado($tipoProducto->id);

            // LOGOS
            $camposLogos = CampoController::fetchCamposLogos($tipoProducto->id);
            foreach ($camposLogos as $campoLogo) {
                if ($campoLogo->tipo_logo == 'sociedad') {
                    if ($valores->sociedad_id == env('SOCIEDAD_ADMIN_ID')) {
                        $campoLogo->url = 'logos/logo_18.png';
                    } else {
                        $campoLogo->url = $valores->logo_sociedad_path;
                    }
                } else {
                    $compania = Compania::find($campoLogo->entidad_id);
                    $campoLogo->url = $compania ? $compania->logo : null;
                }

                if ($campoLogo->url) {
                    // Buscar en public y en storage
                    $logoPath = public_path('storage/' . $campoLogo->url);
                    if (!file_exists($logoPath)) {
                        $logoPath = storage_path('app/public/' . $campoLogo->url);
                    }

                    if (file_exists($logoPath)) {
                        $campoLogo->fullPath = $logoPath;
                    }
                }
            }

            // POLIZAS
            $polizasTipoProducto = DB::table('tipo_producto_polizas')
                ->where('tipo_producto_id', $tipoProducto->id)
                ->get();
            $polizas = DB::table('polizas')
                ->whereIn('id', $polizasTipoProducto->pluck('poliza_id'))
                ->get();

            // CONSTRUIR EL PDF USANDO MPDF
            // pt en mPDF: "P", "pt", "A4" -> mPDF usa principalmente pt, mm, etc.
            // Para mantener compatibilidad con JS jsPDF (p, pt, a4), seteamos la unidad 'pt' y formato A4
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'default_font' => 'helvetica',
                'margin_left' => 0,
                'margin_right' => 0,
                'margin_top' => 0,
                'margin_bottom' => 0,
                'margin_header' => 0,
                'margin_footer' => 0
            ]);

            $mpdf->SetDisplayMode('fullpage');

            // Unidades en pt para coincidir con jsPDF.
            // A4 en pt es 595.28 x 841.89
            $a4width_pt = 595.28;
            $a4height_pt = 841.89;

            // Si no hay plantillas, añadir al menos una página
            if (empty($plantillaFullPaths)) {
                $mpdf->AddPage();
            }

            foreach ($plantillaFullPaths as $index => $fullPath) {
                if ($index > 0 || empty($plantillaFullPaths)) {
                    $mpdf->AddPage();
                } else if ($index == 0) {
                    $mpdf->AddPage(); // Primera página
                }

                $pt2mm = 0.352778;

                // Imagen de fondo (Template)
                $mpdf->Image($fullPath, 0, 0, $a4width_pt * $pt2mm, $a4height_pt * $pt2mm);

                // Logos
                foreach ($camposLogos as $logo) {
                    if ($logo->page == $index + 1 && !empty($logo->fullPath)) {
                        $x = (float)$logo->columna * $pt2mm;
                        $y = (float)$logo->fila * $pt2mm;
                        $w = ((float)($logo->ancho ?: 40)) * $pt2mm;
                        $h = ((float)($logo->altura ?: 40)) * $pt2mm;
                        $mpdf->Image($logo->fullPath, $x, $y, $w, $h);
                    }
                }

                // Polizas
                foreach ($polizasTipoProducto as $poliza) {
                    if ($poliza->page == $index + 1) {
                        $polizaRecord = $polizas->firstWhere('id', $poliza->poliza_id);
                        $polizaNumero = $polizaRecord ? $polizaRecord->numero : null;

                        if ($polizaNumero) {
                            $fontSize = $poliza->font_size ? $poliza->font_size : 10;
                            $mpdf->SetFont('helvetica', '', $fontSize);
                            $x = (float)$poliza->columna * $pt2mm;
                            $y = (float)$poliza->fila * $pt2mm;
                            $mpdf->Text($x, $y, $polizaNumero);
                        }
                    }
                }

                // Campos
                foreach ($campos as $campo) {
                    if ($campo->page == $index + 1) {
                        $valor = $valores->{$campo->nombre_codigo} ?? '';

                        // nombre_unificado
                        if (($tipoProducto->nombre_unificado ?? 0) == 1 && $campo->nombre_codigo === 'nombre_socio') {
                            $nombre = $valores->nombre_socio ?? '';
                            $ape1 = $valores->apellido_1 ?? '';
                            $ape2 = $valores->apellido_2 ?? '';
                            $valor = trim("$nombre $ape1 $ape2");
                        }

                        // Fechas: se detectan por tipo_dato, no por el nombre del campo,
                        // porque estos campos son configurables y no siempre se llaman
                        // "fecha_de_inicio"/"fecha_de_fin"/"fecha_de_emisión" (p.ej.
                        // "fecha_adhesión", "fecha_incorporacion"...). Si el valor guardado
                        // no es parseable se deja tal cual en vez de romper el PDF entero.
                        if (($campo->tipo_dato ?? null) === 'date' && $valor) {
                            try {
                                $fecha = Carbon::parse($valor);
                                if ($fecha->isValid()) {
                                    $valor = $fecha->format('d/m/Y');
                                }
                            } catch (Exception $e) {
                                Log::warning("PdfBuilderService: fecha no parseable en campo {$campo->nombre_codigo}: {$valor}");
                            }
                        }

                        if ($valor !== null && $valor !== '') {
                            $fontSize = $campo->font_size ? $campo->font_size : 10;
                            $mpdf->SetFont('helvetica', '', $fontSize);
                            $x = (float)$campo->columna * $pt2mm;
                            $y = (float)$campo->fila * $pt2mm;

                            // Usamos Text() para posicionamiento absoluto (y es el baseline)
                            $mpdf->Text($x, $y, (string)$valor);
                        }
                    }
                }
            }

            return $mpdf->Output('', 'S'); // 'S' returns the document as a string

        } catch (Exception $e) {
            Log::error('PdfBuilderService Error: ' . $e->getMessage());
            throw $e;
        }
    }
}
