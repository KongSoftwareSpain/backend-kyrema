<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ExportPagosService;

class PagoExportController extends Controller
{
    public function exportarPagos(Request $request, ExportPagosService $service)
    {
        $request->validate([
            'tipo' => 'required|string',
            'sociedad_id' => 'required|integer|exists:sociedades,id',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
        ]);

        return $service->exportarCSV(
            $request->tipo,
            $request->sociedad_id,
            $request->desde,
            $request->hasta
        );
    }
}
