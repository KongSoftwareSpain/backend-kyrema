<?php

namespace App\Http\Controllers;

use App\Models\Socio;
use App\Models\SocioComercial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Comercial;
use App\Models\Sociedad;
use App\Models\TipoProducto;
use App\Models\SocioProducto;
use Illuminate\Support\Facades\Schema;

class SocioController extends Controller
{
    // Mostrar una lista de los socios
    public function index()
    {
        $socios = Socio::all();
        return response()->json($socios);
    }

    public function getAsegurado($dni, $categoria_id){
        $socio = Socio::where('dni', $dni)->where('categoria_id', $categoria_id)->first();
        if (!$socio) {
            return response()->json(['message' => 'Socio not found.'], 404);
        }
        return response()->json($socio);
    }

    public function getSociosByComercial($id_comercial){
        // Recoger el Comercial, ver si es comercial responsable, si lo es devolver todos los socios conectados a los comerciales de su sociedad
        // y las sociedades por debajo, si no lo es devolver solo los socios conectados a él.
        if(Comercial::isResponsable($id_comercial)){
            $comercial = Comercial::find($id_comercial);

            $sociedad = Sociedad::find($comercial->id_sociedad);

            $sociedades = $sociedad->getSociedadesHijasRecursivo($comercial->id_sociedad);

            // Coger solo los ids de las sociedades
            $sociedades = array_map(function($sociedad){
                return $sociedad->id;
            }, $sociedades);

            // Añadir la sociedad actual
            $sociedades[] = $comercial->id_sociedad;

            $socios = Socio::join('socios_comerciales', 'socios.id', '=', 'socios_comerciales.id_socio')
            ->join('comercial', 'socios_comerciales.id_comercial', '=', 'comercial.id')
            ->whereIn('comercial.id_sociedad', $sociedades)
            ->select('socios.*')
            ->get();
        
        } else {
            $socios = Socio::join('socios_comerciales', 'socios.id', '=', 'socios_comerciales.id_socio')
                ->where('socios_comerciales.id_comercial', $id_comercial)
                ->select('socios.*')
                ->get();
        }

        return $socios;
    }

    public function store(Request $request, $categoria_id)
    {
        $request->validate([
            'id_comercial' => 'required|string',
            'dni' => 'required|string',
            'nombre_socio' => 'required|string',
            'apellido_1' => 'nullable|string',
            'apellido_2' => 'nullable|string',
            'email' => 'required|email',
            'telefono' => 'nullable|string',
            'fecha_de_nacimiento' => 'required|date',
            'sexo' => 'nullable|string',
            'direccion' => 'nullable|string',
            'poblacion' => 'nullable|string',
            'provincia' => 'nullable|string',
            'codigo_postal' => 'nullable|string'
        ], [
            'email.email' => 'El formato del correo electrónico no es correcto.'
        ]);

        $request->merge([
            'categoria_id' => $categoria_id
        ]);

        // Validar si el DNI ya existe en la misma categoría
        if (DB::table('socios')
         ->where('dni', $request->dni)
         ->where('categoria_id', $categoria_id)
         ->exists()) {
            return response()->json(['message' => 'El DNI ya está en uso en esta categoría.'], 409);
        }

        if ($request->fecha_nacimiento) {
            $request->merge([
                'fecha_nacimiento' => date('Y-m-d\TH:i:s', strtotime($request->fecha_nacimiento)),
            ]);
        }

        $socio = DB::table('socios')->insertGetId($request->except(['id_comercial']));

        SocioComercial::create([
            'id_comercial' => $request->id_comercial,
            'id_socio' => $socio
        ]);

        return response()->json($socio, 201);
    }

    // Mostrar un socio específico
    public function show($id)
    {
        $socio = Socio::find($id);
        return response()->json($socio);
    }

    // Actualizar un socio específico
    public function update(Request $request, $id)
    {
        $socio = Socio::findOrFail($id);
        $socio->update($request->except(['updated_at']));

        $socio_comercial = SocioComercial::where('id_socio', $id)->first();

        // Si el socio no estaba conectado con nadie previamente, conectarlo con el comercial
        if (!$socio_comercial) {
            SocioComercial::create([
                'id_comercial' => $request->id_comercial,
                'id_socio' => $id
            ]);
        } else {

            // Si ya existe la conexion con ese mismo comercial, no hacer nada
            // si el comercial es distinto añadirlo.
            if ($socio_comercial->id_comercial != $request->id_comercial) {
                $socio_comercial->update([
                    'id_comercial' => $request->id_comercial
                ]);
            }

        }

        return response()->json($socio);
    }

    // Eliminar un socio específico
    public function destroy($id)
    {
        $socio = Socio::findOrFail($id);
        $socio->delete();
        return response()->json(null, 204);
    }

    public function getProductosBySocio($id, $id_tipo_producto)
    {
        // 1️⃣ Obtener el tipo de producto por su ID
        $tipoProducto = TipoProducto::find($id_tipo_producto);

        if (!$tipoProducto) {
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }

        // 2️⃣ Obtener las letras de identificación
        $letrasIdentificacion = $tipoProducto->letras_identificacion;

        if (!$letrasIdentificacion) {
            return response()->json(['error' => 'El tipo de producto no tiene letras de identificación'], 400);
        }

        // 3️⃣ Verificar si la tabla con el nombre de las letras_identificacion existe
        if (!Schema::hasTable($letrasIdentificacion)) {
            return response()->json([]); // Si no existe, devolvemos un array vacío
        }

        // 4️⃣ Obtener los registros de la tabla SocioProducto para el socio y tipo de producto
        $socioProductos = SocioProducto::where('id_socio', $id)
            ->where('letras_identificacion', $letrasIdentificacion)
            ->get();

        if ($socioProductos->isEmpty()) {
            return response()->json([]); // Si no hay registros, devolvemos un array vacío
        }

        // 5️⃣ Obtener los productos de la tabla dinámica con los IDs de SocioProducto
        $productos = DB::table($letrasIdentificacion)
            ->whereIn('id', $socioProductos->pluck('id_producto'))
            ->get();

        return response()->json($productos);
    }

}
