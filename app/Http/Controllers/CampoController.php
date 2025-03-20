<?php

namespace App\Http\Controllers;

use App\Models\Campos;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\TipoProducto;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Config;


class CampoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $campos = Campos::all();
        return response()->json($campos);
    }

    public function getByTipoProducto(Request $request)
    {
        $id_tipo_producto = $request->input('id_tipo_producto');

        $campos = Campos::where('tipo_producto_id', $id_tipo_producto)->get();

        // Devolver los resultados en formato JSON
        return response()->json($campos);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'tipo_producto_id' => 'nullable|string|max:255',
            'visible' => 'required|boolean',
            'obligatorio' => 'required|boolean',
            'grupo' => 'nullable|string|max:255',
        ]);

        $campo = Campos::create($request->all());

        return response()->json($campo, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $campo = Campos::findOrFail($id);
        return response()->json($campo);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'string|max:255',
            'tipo_producto_id' => 'nullable|string|max:255',
            'visible' => 'boolean',
            'obligatorio' => 'boolean',
            'grupo' => 'nullable|string|max:255',
        ]);

        $campo = Campos::findOrFail($id);
        $campo->update($request->all());

        return response()->json($campo);
    }

    public function updatePorTipoProducto(Request $request, $id_tipo_producto)
    {
        $campos = $request->input('campos');

        foreach ($campos as $campo) {
            // Añadir el campo tipo_producto_id
            $campo['tipo_producto_id'] = $id_tipo_producto;
            $campo['nombre_codigo'] = strtolower(str_replace(' ', '_', $campo['nombre']));

            // Obtener el id y eliminarlo del array de atributos
            $id = $campo['id'];
            unset($campo['id']);

            $campo['opciones'] = null;

            // Asegurarse de que updated_at esté en el formato correcto
            $campo['updated_at'] = Carbon::now()->format('Y-m-d\TH:i:s');

            if (isset($id)) {
                
                // Asegurarse de no incluir created_at en la actualización
                unset($campo['created_at']);

                // Actualizar el registro en la base de datos
                DB::table('campos')->where('id', $id)->update($campo);
            } else {
                // Asegurarse de que el campo created_at esté formateado correctamente
                $campo['created_at'] = Carbon::now()->format('Y-m-d\TH:i:s');
                $campo['updated_at'] = Carbon::now()->format('Y-m-d\TH:i:s');

                // Crear un nuevo registro
                DB::table('campos')->insert($campo);
            }
        }

        return response()->json(['message' => 'Campos actualizados correctamente']);
    }

    // Obtiene las opciones de un campo con opciones
    public function getOpcionesPorCampo($id_campo)
    {
        $campo = Campos::findOrFail($id_campo);
        $opciones = DB::table($campo->opciones)->selectRaw('id, nombre, CAST(precio AS DECIMAL(10,2)) as precio')
        ->get();

        $opciones = $opciones->map(function ($item) {
            $item->precio = (float) $item->precio; // Convierte a número flotante
            return $item;
        });

        return response()->json($opciones);

    }


    // Crea un campo con opciones
    public function createCampoConOpciones($data, $id_tipo_producto)
    {

        $data = $this->validateData($data);

        // Generar nombre de la tabla OPCIONES_NOMBRECAMPO_LETRAS:
        $tipo_producto = TipoProducto::findOrFail($id_tipo_producto);
        $nombreTabla = strtolower(Config::get('app.prefijo_tabla_opciones') . str_replace(' ', '_', $data['nombre']) . '_' . $tipo_producto->letras_identificacion);

        // Crear la tabla de opciones
        Schema::create($nombreTabla, function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->decimal('precio', 8, 2)->nullable();
            $table->timestamps();
        });

        // Insertar las opciones en la tabla
        if (!empty($data['opciones'])) {
            foreach ($data['opciones'] as $opcion) {
                DB::table($nombreTabla)->insert([
                    'nombre' => $opcion['nombre'],
                    'precio' => $opcion['precio'] ?? null,
                    'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                    'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                ]);
            }
        }

        // Crear el campo en la tabla 'campos'
        DB::table('campos')->insert([
            'nombre' => $data['nombre'],
            'nombre_codigo' => strtolower(str_replace(' ', '_', $data['nombre'])),
            'tipo_producto_id' => $id_tipo_producto,
            'tipo_dato' => $data['tipo_dato'],
            'columna' => $data['columna'],
            'fila' => $data['fila'],
            'page' => $data['page'],
            // 'font_size' => $data['font_size'],
            'visible' => $data['visible'],
            'obligatorio' => $data['obligatorio'],
            'grupo' => $data['grupo'] ?? null,
            'opciones' => $nombreTabla,
            'copia' => $data['copia'] ?? false,
            'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
        ]);


        // self::addCampoConOpciones($data, $tipo_producto->letras_identificacion);

        return response()->json(['message' => 'Campo con opciones creado exitosamente'], 201);
    }

    public function createCampoConOpcionesHTTP(Request $request, $id_tipo_producto)
    {


        DB::beginTransaction();

        // Validar los datos del request
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'tipo_dato' => 'required|string|max:255',
            'columna' => 'nullable|string|max:255',
            'fila' => 'nullable|string|max:255',
            'page' => 'nullable|string|max:255',
            'visible' => 'required|boolean',
            'obligatorio' => 'required|boolean',
            'grupo' => 'nullable|string|max:255',
            'opciones' => 'nullable|array',
        ]);

        // Obtener el tipo de producto
        $tipo_producto = TipoProducto::findOrFail($id_tipo_producto);

        // Generar nombre de la tabla OPCIONES_NOMBRECAMPO_LETRAS
        $nombreTabla = strtolower(Config::get('app.prefijo_tabla_opciones') . str_replace(' ', '_', $data['nombre']) . '_' . $tipo_producto->letras_identificacion);

        try {

            // Crear la tabla de opciones
            Schema::create($nombreTabla, function (Blueprint $table) {
                $table->id();
                $table->string('nombre');
                $table->decimal('precio', 8, 2)->nullable();
                $table->timestamps();
            });

            // Insertar las opciones en la tabla
            if (!empty($data['opciones'])) {
                foreach ($data['opciones'] as $opcion) {
                    DB::table($nombreTabla)->insert([
                        'nombre' => $opcion['nombre'],
                        'precio' => $opcion['precio'] ?? null,
                        'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                        'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                    ]);
                }
            }

            // Insertar el campo en la tabla 'campos'
            DB::table('campos')->insert([
                'nombre' => $data['nombre'],
                'nombre_codigo' => strtolower(str_replace(' ', '_', $data['nombre'])),
                'tipo_producto_id' => $id_tipo_producto,
                'tipo_dato' => $data['tipo_dato'],
                'columna' => $data['columna'],
                'fila' => $data['fila'],
                'page' => $data['page'],
                'visible' => $data['visible'],
                'obligatorio' => $data['obligatorio'],
                'grupo' => $data['grupo'] ?? null,
                'opciones' => $nombreTabla,
                'copia' => $data['copia'] ?? false,
                'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
            ]);

            // Si es un producto los campos se añaden a su misma tabla, sino si es un subproducto se añaden a su tabla padre
            if($tipo_producto->padre_id == null){
                self::addCampoConOpciones($data, $tipo_producto->letras_identificacion);
            } else {
                $tipo_producto_padre = TipoProducto::findOrFail($tipo_producto->padre_id);
                self::addCampoConOpciones($data, $tipo_producto_padre->letras_identificacion);
            }
            
            

            // Confirmar la transacción
            DB::commit();

            return response()->json(['message' => 'Campo con opciones creado exitosamente'], 201);

        } catch (\Exception $e) {
            // Si algo falla, revertir los cambios
            DB::rollBack();
            
            // Intentar eliminar la tabla creada si ya existía en la transacción
            if (Schema::hasTable($nombreTabla)) {
                Schema::dropIfExists($nombreTabla);
            }

            return response()->json(['error' => 'Error al crear el campo: ' . $e->getMessage()], 500);
        }

    }

    private function addCampoConOpciones($campoConOpciones, $letrasIdentificacion) {
        Schema::table($letrasIdentificacion, function (Blueprint $table) use ($campoConOpciones) {
            $table->string(strtolower(str_replace(' ', '_', $campoConOpciones['nombre'])))->nullable();
        });
    }
    


    // Método para validar manualmente los datos en caso de recibir un array
    private function validateData(array $data)
    {
        return Validator::make($data, [
            'nombre' => 'required|string|max:255',
            'visible' => 'required|boolean',
            'tipo_dato' => 'required|string|max:255',
            'columna' => 'nullable|string|max:255',
            'fila' => 'nullable|string|max:255',
            'page' => 'nullable|string|max:255',
            'obligatorio' => 'required|boolean',
            'grupo' => 'nullable|string|max:255',
            'opciones' => 'nullable|array',
            'opciones.*.nombre' => 'required|string|max:255',
            'opciones.*.precio' => 'nullable|numeric',
        ])->validate();
    }


    public function updateCampoConOpciones(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'string|max:255',
            'tipo_dato' => 'required|string|max:255',
            'columna' => 'nullable|string|max:255',
            'fila' => 'nullable|string|max:255',
            'page' => 'nullable|string|max:255',
            'visible' => 'boolean',
            'obligatorio' => 'boolean',
            'grupo' => 'nullable|string|max:255',
            'opciones' => 'nullable|array',  // Validar que 'opciones' sea un array
            'opciones.*.nombre' => 'required|string|max:255',  // Validar cada opción
            'opciones.*.precio' => 'nullable|string',  // Validar precio si existe
        ]);

        // Buscar el campo existente
        $campo = Campos::findOrFail($id);

        // Nombre de la tabla de opciones
        $nombreTablaOpciones = $campo->opciones;

        // Actualizar el campo principal si es necesario
        DB::table('campos')->where('id', $id)->update([
            'nombre' => $request->input('nombre'),
            'visible' => $request->input('visible'),
            'obligatorio' => $request->input('obligatorio'),
            'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
        ]);

        // Vaciar la tabla de opciones
        DB::table($nombreTablaOpciones)->truncate();

        // Recorrer y procesar las opciones
        foreach ($request->input('opciones', []) as $opcion) {
            DB::table($nombreTablaOpciones)->insert([
                'nombre' => $opcion['nombre'],
                'precio' => $opcion['precio'] ?? null,
                'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
            ]);
        }

        return response()->json(['message' => 'Campo y opciones actualizados correctamente']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $campo = Campos::findOrFail($id);
        $campo->delete();

        return response()->json(null, 204);
    }

    public function addCampos(Request $request, $letrasIdentificacion)
    {
        // Validar la estructura de los campos que se esperan en el request
        $campos = $request->input('campos');

        // Modificar la tabla $letrasIdentificacion
        Schema::table($letrasIdentificacion, function (Blueprint $table) use ($campos) {
            foreach ($campos as $campo) {
                $nombreCampo = strtolower(str_replace(' ', '_', $campo['nombre']));
                switch ($campo['tipo_dato']) {
                    case 'text':
                        $table->string($nombreCampo)->nullable();
                        break;
                    case 'decimal':
                        $table->decimal($nombreCampo, 8, 2)->nullable();
                        break;
                    case 'number':
                        $table->integer($nombreCampo)->nullable();
                        break;
                    case 'date':
                        $table->date($nombreCampo)->nullable();
                        break;
                }
            }
        });

        return response()->json(['message' => 'Campos añadidos con éxito'], 200);
    }

    public function getCamposCertificado($id)
    {
        $campos = CampoController::fetchCamposCertificado($id);
        return response()->json($campos);
    }

    public static function fetchCamposCertificado($id)
    {
        return DB::table('campos')
                ->where('tipo_producto_id', $id)
                ->whereNotNull('columna')
                ->whereNotNull('fila')
                ->get();
    }

    public function getCamposLogos($id)
    {
        $campos = CampoController::fetchCamposLogos($id);
        return response()->json($campos);
    }

    public static function fetchCamposLogos($id)
    {
        return DB::table('campos_logos')
                ->where('tipo_producto_id', $id)
                ->whereNotNull('page')
                ->whereNotNull('altura')
                ->whereNotNull('ancho')
                ->get();
    }
}

