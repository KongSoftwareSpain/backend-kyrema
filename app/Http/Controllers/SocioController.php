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
use App\Models\Categoria;
use Carbon\Carbon;
use Illuminate\Support\Facades\Password;
use App\Notifications\SetInitialPasswordNotification;

class SocioController extends Controller
{
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
        if (Comercial::isResponsable($id_comercial)) {
            $comercial = Comercial::find($id_comercial);
            $sociedad = Sociedad::find($comercial->id_sociedad);
            $sociedades = $sociedad->getSociedadesHijasDesde($comercial->id_sociedad);

            $sociedades = array_map(function ($sociedad) {
                return $sociedad->id;
            }, $sociedades);

            $sociedades[] = $comercial->id_sociedad;

            // Si es la sociedad admin, devolvemos TODOS los socios
            if ($comercial->id_sociedad == 1) { // Asumiendo ID 1 es admin, o usar constante si está disponible en este scope
                $socios = Socio::join('socios_comerciales', 'socios.id', '=', 'socios_comerciales.id_socio')
                    ->join('comercial', 'socios_comerciales.id_comercial', '=', 'comercial.id')
                    ->join('sociedad', 'comercial.id_sociedad', '=', 'sociedad.id')
                    ->select('socios.*', 'sociedad.nombre as nombre_sociedad')
                    ->distinct() // Importante para no duplicar si un socio tiene múltiples comerciales
                    ->get();
                return $socios;
            }

            $socios = Socio::join('socios_comerciales', 'socios.id', '=', 'socios_comerciales.id_socio')
                ->join('comercial', 'socios_comerciales.id_comercial', '=', 'comercial.id')
                ->join('sociedad', 'comercial.id_sociedad', '=', 'sociedad.id')
                ->whereIn('comercial.id_sociedad', $sociedades)
                ->select('socios.*', 'sociedad.nombre as nombre_sociedad')
                ->get();
        } else {
            $socios = Socio::join('socios_comerciales', 'socios.id', '=', 'socios_comerciales.id_socio')
                ->join('comercial', 'socios_comerciales.id_comercial', '=', 'comercial.id')
                ->join('sociedad', 'comercial.id_sociedad', '=', 'sociedad.id')
                ->where('socios_comerciales.id_comercial', $id_comercial)
                ->select('socios.*', 'sociedad.nombre as nombre_sociedad')
                ->get();
        }

        return $socios;
    }

    public function store(Request $request, $categoria_id)
    {
        $data = $request->validate([
            'asegurado' => 'required|array',
            'sendEmail' => 'sometimes|boolean',

            'asegurado.id_comercial' => 'required|string',
            'asegurado.dni' => 'required|string',
            'asegurado.nombre_socio' => 'required|string',
            'asegurado.apellido_1' => 'nullable|string',
            'asegurado.apellido_2' => 'nullable|string',
            'asegurado.email' => 'required|email',
            'asegurado.telefono' => 'nullable|string',
            'asegurado.fecha_de_nacimiento' => 'required|date',
            'asegurado.sexo' => 'nullable|string',
            'asegurado.direccion' => 'nullable|string',
            'asegurado.poblacion' => 'nullable|string',
            'asegurado.provincia' => 'nullable|string',
            'asegurado.codigo_postal' => 'nullable|string',
        ], [
            'asegurado.email.email' => 'El formato del correo electrónico no es correcto.',
        ]);

        $asegurado = $data['asegurado'];
        $sendEmail = $request->boolean('sendEmail');

        // Validar si el DNI ya existe en la misma categoría
        $exists = Socio::query()
            ->where('dni', $asegurado['dni'])
            ->where('categoria_id', $categoria_id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'El DNI ya está en uso en esta categoría.'], 409);
        }

        $payload = [
            'categoria_id' => $categoria_id,
            'dni' => trim($asegurado['dni']),
            'nombre_socio' => $asegurado['nombre_socio'],
            'apellido_1' => $asegurado['apellido_1'] ?? null,
            'apellido_2' => $asegurado['apellido_2'] ?? null,
            'email' => $asegurado['email'],
            'telefono' => $asegurado['telefono'] ?? null,
            'sexo' => $asegurado['sexo'] ?? null,
            'direccion' => $asegurado['direccion'] ?? null,
            'poblacion' => $asegurado['poblacion'] ?? null,
            'provincia' => $asegurado['provincia'] ?? null,
            'codigo_postal' => $asegurado['codigo_postal'] ?? null,
            'fecha_de_nacimiento' => Carbon::parse($asegurado['fecha_de_nacimiento'])->format('Y-m-d\TH:i:s'),
        ];

        $socio = DB::transaction(function () use ($payload, $sendEmail) {
            // 1. Crear socio con Eloquent
            $socio = Socio::create($payload);

            if (!$sendEmail) {
                return $socio;
            }

            // 2. Generar token usando el sistema de Password Reset
            $token = Password::broker('socios')->createToken($socio);

            // 3. Guardar el token en la tabla password_resets
            DB::table('password_resets')->updateOrInsert(
                ['email' => $socio->email],
                [
                    'email' => $socio->email,
                    'token' => \Illuminate\Support\Facades\Hash::make($token),
                    'created_at' => now(),
                ]
            );

            // 4. Enviar notificación personalizada con el token generado
            $categoria = Categoria::find($payload['categoria_id']);
            $socio->notify(new SetInitialPasswordNotification(
                token: $token,
                email: $socio->email,
                categoryName: $categoria->nombre ?? 'Cánama Seguros',
                displayName: $socio->nombre_socio,
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

    public function show($id)
    {
        $socio = Socio::with(['socioComercial.comercial.sociedad'])->find($id);

        if (!$socio) {
            return response()->json(['message' => 'Socio not found'], 404);
        }

        // Aplanamos la estructura si es necesario para el frontend, o dejamos que el frontend lo maneje.
        // Vamos a devolver el objeto socio con las relaciones anidadas.
        // Para facilitar al frontend, podemos añadir campos calculados o simplemente usar los datos anidados.

        $data = $socio->toArray();

        // Añadimos información directa si existe la relación
        if ($socio->socioComercial && $socio->socioComercial->comercial) {
            $data['nombre_comercial'] = $socio->socioComercial->comercial->nombre;
            if ($socio->socioComercial->comercial->sociedad) {
                $data['nombre_sociedad'] = $socio->socioComercial->comercial->sociedad->nombre;
            }
        }

        return response()->json($data);
    }

    public function update(Request $request, $id)
    {
        $socio = Socio::findOrFail($id);
        $socio->update($request->except(['updated_at']));

        $socio_comercial = SocioComercial::where('id_socio', $id)->first();

        if ($request->id_comercial) {
            if (!$socio_comercial) {
                SocioComercial::create([
                    'id_comercial' => $request->id_comercial,
                    'id_socio' => $id
                ]);
            } else {
                if ($socio_comercial->id_comercial != $request->id_comercial) {
                    $socio_comercial->update([
                        'id_comercial' => $request->id_comercial
                    ]);
                }
            }
        }

        return response()->json($socio);
    }

    public function destroy($id)
    {
        $socio = Socio::findOrFail($id);
        $socio->delete();
        return response()->json(null, 204);
    }

    public function getProductosBySocio($id, $id_tipo_producto)
    {
        $tipoProducto = TipoProducto::find($id_tipo_producto);

        if (!$tipoProducto) {
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }

        $letrasIdentificacion = $tipoProducto->letras_identificacion;

        if (!$letrasIdentificacion) {
            return response()->json(['error' => 'El tipo de producto no tiene letras de identificación'], 400);
        }

        if (!Schema::hasTable($letrasIdentificacion)) {
            return response()->json([]);
        }

        $socioProductos = SocioProducto::where('id_socio', $id)
            ->where('letras_identificacion', $letrasIdentificacion)
            ->get();

        if ($socioProductos->isEmpty()) {
            return response()->json([]);
        }

        $productos = DB::table($letrasIdentificacion)
            ->whereIn('id', $socioProductos->pluck('id_producto'))
            ->get();

        return response()->json($productos);
    }
}
