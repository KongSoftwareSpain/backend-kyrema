<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $allowedOrigins = [
            'http://localhost:4200',
            'https://proud-ocean-001393110.5.azurestaticapps.net',
        ];

        $origin = $request->headers->get('Origin');

        $response = $next($request);

        if ($response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            return $response;
        }

        // Permitir origen si está en la lista
        if (in_array($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, multipart/form-data');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        if ($request->isMethod('OPTIONS')) {
            return response()->json([], 200, [
                'Access-Control-Allow-Origin' => in_array($origin, $allowedOrigins) ? $origin : '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, multipart/form-data',
                'Access-Control-Allow-Credentials' => 'true'
            ]);
        }

        return $response;
    }
}
