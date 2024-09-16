<?php

namespace App\Http\Controllers;

use App\Models\Sociedad;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


class SociedadController extends Controller
{
    const SOCIEDAD_ADMIN_ID = 1;

    public function index()
    {
        $sociedades = Sociedad::all();
        return response()->json($sociedades);
    }

    public function getSociedadesPadres()
    {
        // Cuando la sociedad padre sea el admin o no tenga padre, se considera sociedad padre
        $sociedadesPadres = Sociedad::where('sociedad_padre_id', null)->orWhere('sociedad_padre_id', self::SOCIEDAD_ADMIN_ID)->get();
        return response()->json($sociedadesPadres);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'cif' => 'nullable|string|max:255',
            'correo_electronico' => 'required|string|email|max:255',
            'tipo_sociedad' => 'required|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'poblacion' => 'nullable|string|max:255',
            'pais' => 'nullable|string|max:255',
            'codigo_postal' => 'nullable|numeric',
            'codigo_sociedad' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'fax' => 'nullable|string|max:20',
            'movil' => 'nullable|string|max:20',
            'iban' => 'nullable|string|max:34',
            'banco' => 'nullable|string|max:255',
            'sucursal' => 'nullable|string|max:255',
            'dc' => 'nullable|string|max:2',
            'numero_cuenta' => 'nullable|string|max:20',
            'swift' => 'nullable|string|max:11',
            'dominio' => 'nullable|string|max:255',
            'observaciones' => 'nullable|string|max:255',
            'logo' => 'nullable|string|max:255',
            'sociedad_padre_id' => 'nullable|numeric|exists:sociedad,id',
        ]);
    
        // Crear la sociedad con los datos recibidos
        $sociedad = Sociedad::create($request->all());
    
        return response()->json([
            'id' => $sociedad->id,
            'message' => 'Sociedad creada con éxito',
            'sociedad' => $sociedad,
        ], 201);
    }
    

    public function getSociedadesHijas($id)
    {
        $sociedad = Sociedad::findOrFail($id); // Obtener la sociedad inicial
        $sociedadesHijas = $sociedad->getSociedadesHijasRecursivo($id);

        $sociedadesCompletas = array_merge([$sociedad], $sociedadesHijas);

        return response()->json($sociedadesCompletas);
    }

    public function getSociedadesHijasPorTipoProducto($sociedad_id, $letras_identificacion)
    {
        // Obtener la sociedad inicial
        $sociedad = Sociedad::findOrFail($sociedad_id);

        // Obtener las sociedades hijas
        $sociedadesHijas = $sociedad->getSociedadesHijasRecursivo($sociedad_id);

        // Combinar la sociedad principal con las sociedades hijas en una colección
        $sociedadesCompletas = collect(array_merge([$sociedad], $sociedadesHijas));

        // Obtener los tipos de producto basados en letras de identificación
        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letras_identificacion)
            ->get();

        // Obtener los IDs de los tipos de producto
        $tipoProductoId = $tipoProducto->pluck('id');

        $sociedadesFiltradas = $sociedadesCompletas->filter(function($sociedad) use ($tipoProductoId) {
            // Verifica si existe una relación entre la sociedad y el tipo de producto
            $existeRelacion = DB::table('tipo_producto_sociedad')
                ->where('id_tipo_producto', $tipoProductoId)
                ->where('id_sociedad', $sociedad->id)
                ->exists();
        
            // Retorna true si existe la relación, lo que mantendrá la sociedad
            return $existeRelacion;
        });

        return response()->json($sociedadesFiltradas->toArray());
    }


    public function show($id)
    {
        $sociedad = Sociedad::findOrFail($id);
        
        // Convertir el logo binario a Base64
        if ($sociedad->logo) {
            $sociedad->logo = base64_encode($sociedad->logo);
        }

        return response()->json($sociedad);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'string|max:255',
            'cif' => 'nullable|string|max:255',
            'correo_electronico' => 'string|email|max:255',
            'tipo_sociedad' => 'string|max:255',
            'direccion' => 'nullable|string|max:255',
            'poblacion' => 'nullable|string|max:255',
            'pais' => 'nullable|string|max:255',
            'codigo_postal' => 'nullable|numeric',
            'codigo_sociedad' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'fax' => 'nullable|string|max:20',
            'movil' => 'nullable|string|max:20',
            'iban' => 'nullable|string|max:34',
            'banco' => 'nullable|string|max:255',
            'sucursal' => 'nullable|string|max:255',
            'dc' => 'nullable|string|max:2',
            'numero_cuenta' => 'nullable|string|max:20',
            'swift' => 'nullable|string|max:11',
            'dominio' => 'nullable|string|max:255',
            'observaciones' => 'nullable|string|max:255',
            'logo' => 'nullable|string|max:255',
            'sociedad_padre_id' => 'nullable|numeric|exists:sociedad,id',
        ]);

        $sociedad = Sociedad::findOrFail($id);
        $sociedad->update($request->all());

        return response()->json($sociedad);
    }

    public function updatePermisos(Request $request, $id)
    {
        $request->validate([
            'permisosTiposProductos' => 'required|array',
        ]);

        $sociedad = Sociedad::findOrFail($id);

        // Array de permisos que contiene los tipos_productos (ids) y un booleano tienePermisos
        $permisos = $request->input('permisosTiposProductos');

        // Iterar sobre los permisos para agregar o quitar según el valor de tienePermisos
        foreach ($permisos as $permiso) {
            $tipoProductoId = $permiso['id'];
            $tienePermisos = $permiso['tienePermisos'];

            // Verificar si ya existe una relación entre la sociedad y el tipo de producto
            $existingPermiso = DB::table('tipo_producto_sociedad')
                ->where('id_sociedad', $sociedad->id)
                ->where('id_tipo_producto', $tipoProductoId)
                ->first();

            if ($tienePermisos) {
                if (!$existingPermiso) {
                    // Si no existe la relación y tienePermisos es true, la creamos
                    DB::table('tipo_producto_sociedad')->insert([
                        'id_sociedad' => $sociedad->id,
                        'id_tipo_producto' => $tipoProductoId,
                    ]);
                }
            } else {
                if ($existingPermiso) {
                    // Si existe la relación y tienePermisos es false, la eliminamos
                    DB::table('tipo_producto_sociedad')
                        ->where('id_sociedad', $sociedad->id)
                        ->where('id_tipo_producto', $tipoProductoId)
                        ->delete();
                }
            }
        }

        return response()->json(['message' => 'Permisos actualizados con éxito', 'sociedad' => $sociedad], 200);
    }


    public function destroy($id)
    {
        $sociedad = Sociedad::findOrFail($id);
        $sociedad->delete();

        return response()->json(null, 204);
    }
}
