<?php

// app/Http/Controllers/BlobController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Services\AzureSasService;
use Illuminate\Support\Facades\DB;

class BlobController extends Controller
{
    public function __construct(private AzureSasService $sas) {}

    /**
     * Devuelve SAS de SUBIDA para un PDF bajo:
     * /{letras_identificacion}/{codigo_producto}/{uuid}.pdf
     *
     * GET/POST /api/blob/upload-sas?letras_identificacion=...&codigo_producto=...[&ttl=10]
     */
    public function getPdfUploadSasForProduct(Request $request)
    {
        $data = $request->validate([
            'first_url_segment' => ['required','string','max:50','regex:/^[A-Za-z0-9._-]+$/'],
            'second_url_segment'       => ['required','string','max:100','regex:/^[A-Za-z0-9._-]+$/'],
            'ttl'                   => ['sometimes','integer','min:1','max:60'],
        ], [
            'regex' => 'Solo se permiten letras, números, punto, guion y guion bajo.',
        ]);

        // filename por UUID (tú ya subes así)
        $filename = Str::uuid()->toString() . '.pdf';
        $ttl = (int)($data['ttl'] ?? 10);

        $res = $this->sas->makeUploadPdfSasForProduct(
            $data['first_url_segment'],
            $data['second_url_segment'],
            $filename,
            $ttl
        );

        return response()->json($res);
    }

    /**
     * Devuelve SAS de LECTURA (permiso r) para un blob concreto (blob_name).
     *
     * GET /api/blob/read-url?blob_name=producto_tdoc/102025tdoc100019/<uuid>.pdf[&ttl=3]
     */
    public function getReadSasByBlobName(Request $request)
    {
        $data = $request->validate([
            // Permitimos / en la ruta interna del blob
            'blob_name' => ['required','string','max:400','regex:/^[A-Za-z0-9._\-\/]+$/'],
            'ttl'       => ['sometimes','integer','min:1','max:60'],
        ], [
            'blob_name.regex' => 'blob_name solo puede contener letras, números, punto, guion, guion bajo y barras.',
        ]);

        $blobName = ltrim($data['blob_name'], '/');  // normaliza por si llega con /
        $ttl      = (int)($data['ttl'] ?? 3);

        // (Opcional pero recomendable) comprobar existencia => 404 si no existe
        if (!$this->sas->exists($blobName)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $url = $this->sas->makeReadSasForBlobName($blobName, $ttl);

        // Marcamos el instante de expiración (orientativo, en base al TTL que pasamos)
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + ($ttl * 60));

        return response()->json([
            'url'       => $url,       // URL firmada lista para GET
            'blob_name' => $blobName,  // eco por si lo necesitas en front
            'expiresAt' => $expiresAt,
        ]);
    }

    public function getReadSasForAnexoByBlobName(Request $request)
    {
        $data = $request->validate([
            'tipo_anexo_id' => ['required','integer'],
            'producto_id' => ['required','integer'],
            'ttl'       => ['sometimes','integer','min:1','max:60'],
        ], [
            'tipo_anexo_id.required' => 'El tipo_anexo_id es obligatorio.',
            'producto_id.required' => 'El producto_id es obligatorio.',
        ]);

        $tipoAnexo = DB::table('tipo_producto')->where('id', $data['tipo_anexo_id'])->first();

        $anexoRelacionado = DB::table($tipoAnexo->letras_identificacion)
            ->where('producto_id', $data['producto_id'])
            ->first();

        $blobName = ltrim($anexoRelacionado->blob_name, '/');
        $ttl      = (int)($data['ttl'] ?? 3);

        // (Opcional pero recomendable) comprobar existencia => 404 si no existe
        if (!$this->sas->exists($blobName)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $url = $this->sas->makeReadSasForBlobName($blobName, $ttl);

        // Marcamos el instante de expiración (orientativo, en base al TTL que pasamos)
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + ($ttl * 60));

        return response()->json([
            'url'       => $url,       // URL firmada lista para GET
            'blob_name' => $blobName,  // eco por si lo necesitas en front
            'expiresAt' => $expiresAt,
        ]);
    }
}
