<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnuladosController extends Controller
{
    public function getAnulados($letrasIdentificacion, Request $request)
    {
        // Obtener las sociedades desde la consulta
        $sociedades = $request->query('sociedades');

        if ($sociedades) {
            $sociedades = explode(',', $sociedades);
        } else {
            $sociedades = [];
        }

        // Convertir letras de identificación a nombre de tabla (en este caso, anulaciones)
        $nombreTabla = 'anulaciones';

        // Construir la consulta para obtener los datos anulados
        $query = DB::table($nombreTabla)
                    ->where('letrasIdentificacion', $letrasIdentificacion)
                    ->select('id', 'fecha', 'sociedad_id', 'comercial_id', 'sociedad_nombre', 'comercial_nombre', 'causa', 'letrasIdentificacion', 'producto_id', 'codigo_producto', 'created_at', 'updated_at');

        // Filtrar por sociedades si están presentes
        if (!empty($sociedades)) {
            $query->whereIn('sociedad_id', $sociedades);
        }

        // Obtener los resultados
        $anulados = $query->get();

        return response()->json($anulados);
    }
}