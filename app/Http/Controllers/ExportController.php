<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf as PdfMpdf;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Illuminate\Support\Facades\Log;

class ExportController extends Controller
{

    public function exportExcelToPdf($letrasIdentificacion, Request $request)
    {
        
        try {

            // Obtener el id del request
            $id = $request->input('id');
            
            // Obtener los valores de los campos de la tabla que se llama igual que las letrasIdentificacion
            $valores = DB::table($letrasIdentificacion)->where('id', $id)->first();

            

            // Obtener el tipo de producto basado en las letras de identificación
            $tipoProducto = DB::table('tipo_producto')->where('letras_identificacion', $letrasIdentificacion)->first();
            
            if (!$tipoProducto) {
                return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
            }

           // Obtener la ruta de la plantilla
           $plantillaPath = storage_path('app/public/' . $valores->plantilla_path);

           if (!file_exists($plantillaPath)) {
               return response()->json(['error' => 'Plantilla no encontrada'. $plantillaPath], 404);
           }

            // Cargar el archivo Excel
            $spreadsheet = IOFactory::load($plantillaPath);
            $sheet = $spreadsheet->getActiveSheet();

           // Configuración de impresión para ajustar el contenido a una página
            // $sheet->getPageSetup()
            // ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);
            // ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
            // ->setFitToWidth(1)  // Ajustar al ancho de una página
            // ->setFitToHeight(1); // Ajustar al alto de una página

            // Configura márgenes (ajústalos según sea necesario)
            $sheet->getPageMargins()->setTop(0.5);
            $sheet->getPageMargins()->setRight(0.5);
            $sheet->getPageMargins()->setLeft(0.5);
            $sheet->getPageMargins()->setBottom(0.5);

            $sheet->getPageSetup()->setPrintArea('A1:I68');


            // Obtener los campos del tipo de producto con columna y fila no nulos
            $campos = DB::table('campos')
                ->where('tipo_producto_id', $tipoProducto->id)
                ->whereNotNull('columna')
                ->whereNotNull('fila')
                ->get();


            if (!$valores) {
                return response()->json(['error' => 'Valores no encontrados'], 404);
            }

            // Rellenar el archivo Excel con los valores obtenidos
            foreach ($campos as $campo) {
                $celda = $campo->columna . $campo->fila;
                // Convertir el nombre del campo a minúsculas y reemplazar espacios por guiones bajos
                $nombreCampo = strtolower(str_replace(' ', '_', $campo->nombre));
                $valor = $valores->{$nombreCampo}; 
                
                // Obtener el contenido existente de la celda
                $contenidoExistente = $sheet->getCell($celda)->getValue();
                
                // Concatenar el contenido existente con el nuevo valor
                $nuevoContenido = $contenidoExistente . ' ' . $valor;
                
                // Establecer el nuevo contenido en la celda
                $sheet->setCellValue($celda, $nuevoContenido);
            }


            if($valores->sociedad_id == env('SOCIEDAD_ADMIN_ID')){
                $logo = 'logos/Logo_CANAMA__003.png';
            } else {
                $logo = $valores->logo_sociedad_path;
            }

            if($logo){
                // Obtener la ruta del logo
                $logoPath = storage_path('app/public/' . $logo);
            
                if (file_exists($logoPath) && $tipoProducto->casilla_logo_sociedad) {
                    // Insertar el logo en la celda A1
                    // Crear una nueva instancia de Drawing
                    $drawing = new Drawing();
                    $drawing->setName('Logo');
                    $drawing->setDescription('Logo de la empresa');
                    $drawing->setPath($logoPath); // Ruta de la imagen
                    $drawing->setHeight(90); // Altura de la imagen (puedes ajustarlo según sea necesario)
                    $drawing->setCoordinates(strtoupper($tipoProducto->casilla_logo_sociedad)); // Celda en la que deseas insertar la imagen
                    $drawing->setWorksheet($sheet); // Asignar la hoja donde se insertará la imagen
                }
            
            }

            // Obtener y colocar los datos de tipo_producto_polizas y las pólizas relacionadas
            $polizasTipoProducto = DB::table('tipo_producto_polizas')
            ->where('tipo_producto_id', $tipoProducto->id)
            ->get();

            $polizas = DB::table('polizas')
            ->whereIn('id', $polizasTipoProducto->pluck('poliza_id'))
            ->get();

            // Obtener las compañías asociadas a cada póliza
            $companiasIds = $polizas->pluck('compania_id')->unique();
            $companias = DB::table('companias')
            ->whereIn('id', $companiasIds)
            ->get();

            Log::info($polizasTipoProducto);
            Log::info($polizas);
            Log::info($companias);

            // Agregar el logo y número de póliza de cada compañía en las celdas correspondientes
            foreach ($polizasTipoProducto as $tipoPoliza) {
                $poliza = $polizas->firstWhere('id', $tipoPoliza->poliza_id);
                $compania = $companias->firstWhere('id', $poliza->compania_id);

                $numeroPoliza = $poliza ? $poliza->numero : 'N/A';

                // Insertar número de póliza en la celda correspondiente
                $celdaPoliza = strtoupper($tipoPoliza->columna) . $tipoPoliza->fila;
                if($celdaPoliza != ''){
                    $sheet->setCellValue($celdaPoliza, $numeroPoliza);

                    // Insertar logo de la compañía en la celda correspondiente
                    if ($compania && !empty($tipoPoliza->columna_logo) && !empty($tipoPoliza->fila_logo) && $compania->logo_path) {
                        $logoPath = storage_path('app/public/' . $compania->logo_path);
                        if (file_exists($logoPath)) {
                            $celdaLogo = strtoupper($tipoPoliza->columna_logo) . $tipoPoliza->fila_logo;

                            $drawing = new Drawing();
                            $drawing->setName('Logo Compañía');
                            $drawing->setDescription('Logo de la compañía');
                            $drawing->setPath($logoPath);
                            $drawing->setHeight(50);
                            $drawing->setCoordinates($celdaLogo);
                            $drawing->setWorksheet($sheet);
                        }
                    }
                }
            }



            // Guardar el archivo Excel con los nuevos datos
            $tempExcelPath = storage_path('app/public/temp/plantilla_' . time() . '.xlsx');
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempExcelPath);
            
            // Convertir el archivo Excel a PDF usando mPDF
            try{
                IOFactory::registerWriter('Pdf', PdfMpdf::class);
            
                $pdfWriter = IOFactory::createWriter($spreadsheet, 'Pdf');
                
                
                // Guardar el archivo PDF temporalmente
                $tempPdfPath = storage_path('app/public/temp/plantilla_' . time() . '.pdf');
                $pdfWriter->save($tempPdfPath);

                // Devolver el archivo PDF como respuesta HTTP con el tipo de contenido adecuado
                $fileContent = file_get_contents($tempPdfPath);
                $response = response($fileContent, 200)->header('Content-Type', 'application/pdf');

                // Eliminar los archivos temporales
                // unlink($tempExcelPath);
                // unlink($tempPdfPath);

            } catch (\PhpOffice\PhpSpreadsheet\Writer\Exception $e) {
                // Limpieza del directorio temporal
                
                return response()->json(['error' => 'El servicio de generación de PDF está temporalmente fuera de servicio. Vuelve a intentarlo más tarde'], 503);
            }

            return $response;


        } catch (\ErrorException $e) {

            Log::error("Error de variable indefinida: " . $e->getMessage());

            $tempDir = sys_get_temp_dir() . '/phpsppdf/mpdf/mpdf/ttfontdata/';
            if (is_dir($tempDir)) {
                array_map('unlink', glob("$tempDir/*"));
            }

            Log::info($tempDir);

            return response()->json(['error' => 'Error en el procesamiento de PDF. Verifique la configuración.'], 500);

        }catch (\Exception $e) {
            Log::info($e);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function exportAnexoExcelToPdf($tipoAnexoId , Request $request){
        // Obtener el id del request
        $id = $request->input('id');

        // Obtener el tipoAnexo desde las letrasIdentificacion (Para coger la plantilla)
        $tipoAnexo = DB::table('tipo_producto')
        ->where('id', $tipoAnexoId)
        ->first();


        $letrasIdentificacionAnexo = $tipoAnexo->letras_identificacion;

        // Obtener la ruta de la plantilla
        $plantillaPath = storage_path('app/public/' . $tipoAnexo->plantilla_path);
        
        if (!file_exists($plantillaPath)) {
            return response()->json(['error' => 'Plantilla no encontrada'. $plantillaPath], 404);
        }


        // Cargar el archivo Excel
        $spreadsheet = IOFactory::load($plantillaPath);
        $sheet = $spreadsheet->getActiveSheet();
                
        // Coger los anexos relacionados con el id del producto de la tabla con el nombre $letrasIdentificacionAnexo
        $anexos = DB::table($letrasIdentificacionAnexo)->where('producto_id', $id)->get();


        // NECESITAMOS TAMBIEN LOS DATOS DEL PRODUCTO PARA RELLENAR LOS CAMPOS DE LA PLANTILLA
        $tipoProducto = DB::table('tipo_producto')
        ->where('id', $tipoAnexo->tipo_producto_asociado)
        ->first();


        $producto = DB::table($tipoProducto->letras_identificacion)->where('id', $id)->first();

        $campos = DB::table('campos')
            ->where('tipo_producto_id', $tipoAnexoId)
            ->whereNotNull('columna')
            ->whereNotNull('fila')
            ->whereNotIn('grupo', ['datos_anexo', 'datos_precio'])
            ->get();


        // Rellenar el archivo Excel con los valores obtenidos
        foreach ($campos as $campo) {
            $celda = $campo->columna . $campo->fila;
            // Convertir el nombre del campo a minúsculas y reemplazar espacios por guiones bajos
            $nombreCampo = strtolower(str_replace(' ', '_', $campo->nombre));
            $valor = $producto->{$nombreCampo}; 
            
            // Obtener el contenido existente de la celda
            $contenidoExistente = $sheet->getCell($celda)->getValue();
            
            // Concatenar el contenido existente con el nuevo valor
            $nuevoContenido = $contenidoExistente . ' ' . $valor;
            
            // Establecer el nuevo contenido en la celda
            $sheet->setCellValue($celda, $nuevoContenido);
        }

        if ($anexos->isNotEmpty()) {
            // Obtener los campos de la tabla 'campos' que están asociados con el tipo de producto
            $camposAnexo = DB::table('campos')
                ->where('tipo_producto_id', $tipoAnexo->id)
                ->whereNotNull('columna')
                ->whereNotNull('fila')
                ->whereIn('grupo', ['datos_anexo', 'datos_precio'])
                ->get();

            // Recorrer cada anexo
            foreach ($anexos as $index => $anexo) {
                // Recorrer cada campo del anexo
                foreach ($camposAnexo as $campoAnexo) {
                    // Determinar la celda en la hoja de cálculo
                    $celda = $campoAnexo->columna . ($campoAnexo->fila + $index);

                    // Obtener el valor correspondiente al campo en el anexo
                    $valorAnexo = $anexo->{$campoAnexo->nombre_codigo};

                    // Obtener el valor existente en la celda
                    $valorExistente = $sheet->getCell($celda)->getValue();


                    // Concatenar el valor existente con el nuevo valor solo si tenia un valor previo
                    if($valorExistente != ''){
                        $nuevoValor = $valorExistente . ' ' . $valorAnexo;
                    }else{
                        $nuevoValor = $valorAnexo;
                    }

                    // Escribir el nuevo valor en la celda
                    $sheet->setCellValue($celda, $nuevoValor);
                }
            }
        }

        if($producto->sociedad_id == env('SOCIEDAD_ADMIN_ID')){
            $logo = 'logos/Logo_CANAMA__003.png';
        } else {
            $logo = $producto->logo_sociedad_path;
        }
        

        // Obtener la ruta del logo
        $logoPath = storage_path('app/public/' . $logo);
    
        if (file_exists($logoPath) && $tipoAnexo->casilla_logo_sociedad) {
            // Insertar el logo en la celda A1
            // Crear una nueva instancia de Drawing
            $drawing = new Drawing();
            $drawing->setName('Logo');
            $drawing->setDescription('Logo de la empresa');
            $drawing->setPath($logoPath); // Ruta de la imagen
            $drawing->setHeight(90); // Altura de la imagen (puedes ajustarlo según sea necesario)
            $drawing->setCoordinates(strtoupper($tipoAnexo->casilla_logo_sociedad)); // Celda en la que deseas insertar la imagen
            $drawing->setWorksheet($sheet); // Asignar la hoja donde se insertará la imagen
        }

        $sheet->getPageMargins()->setTop(0);
        $sheet->getPageMargins()->setRight(0);
        $sheet->getPageMargins()->setLeft(0);
        $sheet->getPageMargins()->setBottom(0);

        // Guardar el archivo Excel con los nuevos datos
        $tempExcelPath = storage_path('app/public/temp/plantilla_' . time() . '.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempExcelPath);

        // Convertir el archivo Excel a PDF usando mPDF
        IOFactory::registerWriter('Pdf', PdfMpdf::class);
        $pdfWriter = IOFactory::createWriter($spreadsheet, 'Pdf');
        
        // Guardar el archivo PDF temporalmente
        $tempPdfPath = storage_path('app/public/temp/plantilla_' . time() . '.pdf');
        $pdfWriter->save($tempPdfPath);

        // Devolver el archivo PDF como respuesta HTTP con el tipo de contenido adecuado
        $fileContent = file_get_contents($tempPdfPath);
        $response = response($fileContent, 200)->header('Content-Type', 'application/pdf');

        // Eliminar los archivos temporales
        unlink($tempExcelPath);
        unlink($tempPdfPath);

        return $response;
    }


    // public function exportExcelToPdf($letrasIdentificacion, Request $request)
    // {
    //     try{
    //         // Obtener el tipo de producto basado en las letras de identificación
    //         $tipoProducto = DB::table('tipo_producto')->where('letras_identificacion', $letrasIdentificacion)->first();
            
    //         if (!$tipoProducto) {
    //             return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
    //         }

    //         // Obtener la ruta de la plantilla
    //         $plantillaPath = storage_path('app/public/' . $tipoProducto->plantilla_path);
            
    //         if (!file_exists($plantillaPath)) {
    //             return response()->json(['error' => 'Plantilla no encontrada'], 404);
    //         }

    //         // Cargar el archivo Excel
    //         $spreadsheet = IOFactory::load($plantillaPath);
    //         $sheet = $spreadsheet->getActiveSheet();

    //         // Obtener los campos del tipo de producto con columna y fila no nulos
    //         $campos = DB::table('campos')
    //             ->where('tipo_producto_id', $tipoProducto->id)
    //             ->whereNotNull('columna')
    //             ->whereNotNull('fila')
    //             ->get();

    //         // Obtener el id del request
    //         $id = $request->input('id');
            
    //         // Obtener los valores de los campos de la tabla que se llama igual que las letrasIdentificacion
    //         $valores = DB::table($letrasIdentificacion)->where('id', $id)->first();

    //         if (!$valores) {
    //             return response()->json(['error' => 'Valores no encontrados'], 404);
    //         }

    //         // Rellenar el archivo Excel con los valores obtenidos
    //         foreach ($campos as $campo) {
    //             $celda = $campo->columna . $campo->fila;
    //             // Convertir el nombre del campo a minúsculas y reemplazar espacios por guiones bajos
    //             $nombreCampo = strtolower(str_replace(' ', '_', $campo->nombre));
    //             $valor = $valores->{$nombreCampo}; 
                
    //             // Obtener el contenido existente de la celda
    //             $contenidoExistente = $sheet->getCell($celda)->getValue();
                
    //             // Concatenar el contenido existente con el nuevo valor
    //             $nuevoContenido = $contenidoExistente . ' ' . $valor;
                
    //             // Establecer el nuevo contenido en la celda
    //             $sheet->setCellValue($celda, $nuevoContenido);
                
    //         }

    //         foreach ($campos as $campo) {
    //             $celda = $campo->columna . $campo->fila;
    //             $sheet->getStyle($celda)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);
    //         }

    //         // Establecer el área de impresión (ajusta las celdas según sea necesario)
    //         $sheet->getPageSetup()->setPrintArea('D1:L54');

    //         // Guardar el archivo Excel con los nuevos datos
    //         $tempExcelPath = storage_path('app/public/temp/plantilla_' . time() . '.xlsx');
    //         $writer = new Xlsx($spreadsheet);
    //         $writer->save($tempExcelPath);

    //         // Convertir el archivo Excel a HTML para generar el PDF
    //         $htmlWriter = IOFactory::createWriter($spreadsheet, 'Html');
    //         ob_start();
    //         $htmlWriter->save('php://output');
    //         $htmlContent = ob_get_clean();

    //         // Eliminar imágenes en base64 del contenido HTML
    //         $htmlContent = $this->removeBase64Images($htmlContent);            

    //         // Añadir estilos CSS al contenido HTML
    //         $htmlContent = $this->adjustHtmlStyles($htmlContent);

    //         // ELIMINAR BORDES QUE SE GENERAN EN EL PDF:
    //         $htmlContent = str_replace('border: 1px solid black;', '', $htmlContent);

    //         // Guardar el contenido HTML en un archivo para revisión
    //         $htmlFilePath = storage_path('app/public/temp/plantilla_' . time() . '.html');
    //         file_put_contents($htmlFilePath, $htmlContent);

            
    //         // Crear el PDF desde el contenido HTML
    //         $pdf = Pdf::loadHTML($htmlContent);

    //         // Guardar el archivo PDF temporalmente
    //         $tempPdfPath = storage_path('app/public/temp/plantilla_' . time() . '.pdf');
    //         $pdf->save($tempPdfPath);
            
    //         // Devolver el archivo PDF como respuesta HTTP con el tipo de contenido adecuado
    //         $fileContent = file_get_contents($tempPdfPath);
    //         $response = response($fileContent, 200)->header('Content-Type', 'application/pdf');

    //         // Eliminar los archivos temporales
    //         unlink($tempExcelPath);
    //         unlink($tempPdfPath);

    //         return $response;

    //     }catch(\Exception $e){

    //         return response()->json(['error' => $e->getMessage()], 500);

    //     }
        
    // }

    // private function adjustHtmlStyles($htmlContent)
    // {
    //     // Reducir espacios en blanco y ajustar el tamaño de la letra
    //     $styles = "
    //         <style>
    //             body {
    //                 font-size: 6px;
    //                 line-height: 1;
    //             }
    //             h1, h2, h3, h4, h5, h6 {
    //                 margin: 2px 0;
    //             }
    //             p {
    //                 margin: 2px 0;
    //             }
    //             table {
    //                 width: 100%;
    //                 border-collapse: collapse;
    //             }
    //             td, th {
    //                 padding: 2px;
    //             }
    //         </style>
    //     ";

    //     // Insertar los estilos en el contenido HTML
    //     $htmlContent = str_replace('</head>', $styles . '</head>', $htmlContent);

    //     return $htmlContent;
    // }

    // private function removeBase64Images($htmlContent)
    // {
    //     // Eliminar todas las imágenes en base64
    //     $htmlContent = preg_replace('/<img[^>]+src="data:image\/[^;]+;base64,([^"]+)"[^>]*>/', '', $htmlContent);
    //     return $htmlContent;
    // }

}
