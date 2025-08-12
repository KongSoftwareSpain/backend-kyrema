<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function check()
    {
        // Estado de la base de datos
        try {
            DB::connection()->getPdo();
            $dbStatus = 'ok';
        } catch (\Exception $e) {
            $dbStatus = 'error';
        }

        // Respuesta final
        return response()->json([
            'status'    => $dbStatus === 'ok' ? 'ok' : 'error',
            'database'  => $dbStatus,
            'timestamp' => now(),
        ], $dbStatus === 'ok' ? 200 : 500);
    }
}
