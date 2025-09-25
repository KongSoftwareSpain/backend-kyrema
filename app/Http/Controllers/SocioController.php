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
use App\Notifications\SetInitialPasswordNotification;
use App\Models\Categoria;
use Carbon\Carbon;

class SocioController extends Controller
{
    // Mostrar una lista de los socios
    public function index()
    {
        $socios = Socio::all();
        return response()->json($socios);
    }

    public function getAsegurado($dni, $categoria_id)
    {
        $socio = Socio::where('dni', $dni)->where('categoria_id', $categoria_id)->first();
        if (!$socio) {
            return response()->json(['message' => 'Socio not found.'], 404);
        }
        return response()->json($socio);
    }

    public function getSociosByComercial($id_comercial)
    {
        // Recoger el Comercial, ver si es comercial responsable, si lo es devolver todos los socios conectados a los comerciales de su sociedad
        // y las sociedades por debajo, si no lo es devolver solo los socios conectados a él.
        if (Comercial::isResponsable($id_comercial)) {
            $comercial = Comercial::find($id_comercial);

            $sociedad = Sociedad::find($comercial->id_sociedad);

            $sociedades = $sociedad->getSociedadesHijasDesde($comercial->id_sociedad);

            // Coger solo los ids de las sociedades
            $sociedades = array_map(function ($sociedad) {
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
        $data = $request->validate([
            'asegurado' => 'required|array',
            'sendEmail' => 'sometimes|boolean', // o 'required|boolean' si es obligatorio

            'asegurado.id_comercial'       => 'required|string',
            'asegurado.dni'                => 'required|string',
            'asegurado.nombre_socio'       => 'required|string',
            'asegurado.apellido_1'         => 'nullable|string',
            'asegurado.apellido_2'         => 'nullable|string',
            'asegurado.email'              => 'required|email',
            'asegurado.telefono'           => 'nullable|string',
            'asegurado.fecha_de_nacimiento' => 'required|date',
            'asegurado.sexo'               => 'nullable|string',
            'asegurado.direccion'          => 'nullable|string',
            'asegurado.poblacion'          => 'nullable|string',
            'asegurado.provincia'          => 'nullable|string',
            'asegurado.codigo_postal'      => 'nullable|string',
        ], [
            'asegurado.email.email' => 'El formato del correo electrónico no es correcto.',
        ]);

        $asegurado = $data['asegurado'];
        $sendEmail = $request->boolean('sendEmail'); // false si no viene

        // Validar si el DNI ya existe en la misma categoría
        $exists = Socio::query()
            ->where('dni', $asegurado['dni'])
            ->where('categoria_id', $categoria_id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'El DNI ya está en uso en esta categoría.'], 409);
        }

        $payload = [
            'categoria_id'        => $categoria_id,
            'dni'                 => trim($asegurado['dni']),
            'nombre_socio'        => $asegurado['nombre_socio'],
            'apellido_1'          => $asegurado['apellido_1'] ?? null,
            'apellido_2'          => $asegurado['apellido_2'] ?? null,
            'email'               => $asegurado['email'],
            'telefono'            => $asegurado['telefono'] ?? null,
            'sexo'                => $asegurado['sexo'] ?? null,
            'direccion'           => $asegurado['direccion'] ?? null,
            'poblacion'           => $asegurado['poblacion'] ?? null,
            'provincia'           => $asegurado['provincia'] ?? null,
            'codigo_postal'       => $asegurado['codigo_postal'] ?? null,
            'fecha_de_nacimiento' => Carbon::parse($asegurado['fecha_de_nacimiento'])->format('Y-m-d\TH:i:s'),
        ];

        $socio = DB::transaction(function () use ($payload, $sendEmail) {
            // Crear socio con Eloquent
            $socio = Socio::create($payload);

            // 2) Genera token de set/reset
            $token = $socio->createToken('socio')->plainTextToken;

            if(!$sendEmail){
                return $socio;
            }
            // 3) Notifica (queue)
            $socio->notify(new SetInitialPasswordNotification(
                token: $token,
                email: $socio->email,
                categoryName: Categoria::find($payload['categoria_id'])->nombre,
                displayName: $socio->nombre,
                productHint: 'Desde aquí podrás crear tu contraseña y ver tus productos contratados.'
            ));

            return $socio;
        });

        SocioComercial::create([
            'id_comercial' => $asegurado['id_comercial'],
            'id_socio' => $socio->id
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

        if ($request->id_comercial) {
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
