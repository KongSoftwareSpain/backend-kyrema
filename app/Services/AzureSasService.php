<?php

namespace App\Services;

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\BlobSharedAccessSignatureHelper;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use MicrosoftAzure\Storage\Common\Internal\Resources;

class AzureSasService
{
    private string $account;
    private string $key;
    private string $container;
    private BlobRestProxy $client;
    private BlobSharedAccessSignatureHelper $sasHelper;

    public function __construct()
    {
        // Coge primero de config/filesystems, con fallback a .env
        $this->account   = config('filesystems.disks.azure.account_name', env('AZURE_STORAGE_NAME'));
        $this->key       = config('filesystems.disks.azure.account_key', env('AZURE_STORAGE_KEY'));
        $this->container = config('filesystems.disks.azure.container', env('AZURE_STORAGE_CONTAINER', 'documentos'));

        $connection = sprintf(
            'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net',
            $this->account,
            $this->key
        );

        $this->client    = BlobRestProxy::createBlobService($connection);
        $this->sasHelper = new BlobSharedAccessSignatureHelper($this->account, $this->key);
    }

    // Permite solo a-z 0-9 . _ - (y pasa a minúsculas)
    private function sanitize(string $s): string
    {
        $s = trim(mb_strtolower($s, 'UTF-8'));
        return preg_replace('/[^a-z0-9._-]/', '-', $s);
    }

    /** URL base (sin SAS) del blob */
    private function blobBaseUrl(string $blobName): string
    {
        // Codifica cada segmento, no las barras
        $segments = array_map('rawurlencode', explode('/', $blobName));
        $safePath = implode('/', $segments);
        return "https://{$this->account}.blob.core.windows.net/{$this->container}/{$safePath}";
    }

    /** True si el blob existe (getBlobProperties 200); false si 404 */
    public function exists(string $blobName): bool
    {
        try {
            $this->client->getBlobProperties($this->container, $blobName);
            return true;
        } catch (ServiceException $e) {
            if ((int) $e->getCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * SAS para subir un PDF a /{letras_identificacion}/{codigo_producto}/{uuid}.pdf
     * (Mantiene exactamente tu construcción y return)
     */
    public function makeUploadPdfSasForProduct(
        string $seg1,
        string $seg2,
        string $filename,
        int $ttlMinutes = 10
    ): array {
        $account   = $this->account;
        $key       = $this->key;
        $container = $this->container;

        // Ruta final: letras/codigo/uuid.pdf
        $seg1     = $this->sanitize($seg1);
        $seg2     = $this->sanitize($seg2);
        $blobName = "{$seg1}/{$seg2}/{$filename}";

        // Ventana de validez (con tolerancia de reloj)
        $start  = gmdate('Y-m-d\TH:i:s\Z', time() - 120);
        $expiry = gmdate('Y-m-d\TH:i:s\Z', time() + ($ttlMinutes * 60));

        // Permisos mínimos para crear/escribir el blob concreto
        // Puedes usar 'c' si no quieres sobreescrituras; 'cw' permite reintentos.
        $permissions = 'cw';

        $helper = new BlobSharedAccessSignatureHelper($account, $key);

        $sas = $helper->generateBlobServiceSharedAccessSignatureToken(
            Resources::RESOURCE_TYPE_BLOB,           // recurso: blob
            "{$container}/{$blobName}",              // contenedor + ruta exacta
            $permissions,                            // permisos
            $expiry,                                 // expira
            $start,                                  // empieza
            '',                                      // IP range (vacío = cualquiera)
            'https',                                 // solo HTTPS
            '',                                      // cacheControl
            '',                                      // contentDisposition
            '',                                      // contentEncoding
            '',                                      // contentLanguage
            'application/pdf'                        // contentType
        );

        $base = "https://{$account}.blob.core.windows.net/{$container}/{$blobName}";

        return [
            // Devuelve ambos estilos por comodidad (camelCase y snake_case)
            'blobName'   => $blobName,
            'blob_name'  => $blobName,               // <-- este es el que guardarás en BD
            'uploadUrl'  => "{$base}?{$sas}",        // URL firmada para el PUT
            'blobUrl'    => $base,                   // sin SAS (si el contenedor es público)
            'expiresAt'  => $expiry,
            'headers'    => [
                'x-ms-blob-type'         => 'BlockBlob',
                'x-ms-blob-content-type' => 'application/pdf',
            ],
        ];
    }

    /**
     * SAS de LECTURA para un blob ya conocido (usando blob_name guardado en BD).
     * Devuelve la URL completa firmada (GET con permiso `r`).
     */
    public function makeReadSasForBlobName(string $blobName, int $ttlMinutes = 3): string
    {
        $start  = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
        $expiry = gmdate('Y-m-d\TH:i:s\Z', time() + ($ttlMinutes * 60));

        $sas = $this->sasHelper->generateBlobServiceSharedAccessSignatureToken(
            Resources::RESOURCE_TYPE_BLOB,           // 'b'
            "{$this->container}/{$blobName}",        // "<container>/<blob>"
            'r',                                     // read
            $expiry,
            $start,
            '',                                      // IP range
            'https',                                 // protocol
            '', '', '', '', ''                       // cc, cd, ce, cl, ct (no necesarios para lectura)
        );

        return $this->blobBaseUrl($blobName) . '?' . $sas;
    }

    /**
     * (Opcional) SAS de lectura construyendo la ruta como en la subida,
     * por si prefieres no pasar blob_name directamente.
     */
    public function makeReadPdfSasForProduct(
        string $letras_identificacion,
        string $codigo_producto,
        string $filename,
        int $ttlMinutes = 3
    ): string {
        $seg1     = $this->sanitize($letras_identificacion);
        $seg2     = $this->sanitize($codigo_producto);
        $blobName = "{$seg1}/{$seg2}/{$filename}";

        return $this->makeReadSasForBlobName($blobName, $ttlMinutes);
    }
}
