<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log; // Importar la clase Log
use App\Models\Anulacion; // Importar el modelo Anulacion
// Usar CampoController;
use App\Http\Controllers\CampoController;
use Illuminate\Support\Facades\Config;

class ProductoController extends Controller
{
    // Array para seleccionar el numero de días dependiendo de el tipoDuracion


    public function crearTipoProducto(Request $request)
    {
        // Validar los datos recibidos
        $request->validate([
            'nombreProducto' => 'required|string',
            'letrasIdentificacion' => 'required|string',
            'padre_id' => 'nullable|integer',
            'tipo_producto_asociado' => 'nullable|integer',
            'campos' => 'nullable|array',
            'campos.*.nombre' => 'required|string',
            'campos.*.tipo_dato' => 'required|string|in:text,number,date,decimal,selector',
            'camposConOpciones' => 'nullable|array',
            'camposConOpciones.*.nombre' => 'required|string',
            'camposConOpciones.*.opciones' => 'required|array',
            'camposConOpciones.*.opciones.*.nombre' => 'required|string',
            'camposConOpciones.*.opciones.*.precio' => 'nullable|string',
            'duracion' => 'required|array',
            'duracion.*.nombre' => 'required|string',
            'duracion.*.tipo_dato' => 'required|string|in:anual,mensual,diario,dias_delimitados,selector_dias,fecha_exacta,heredada',
        ]);

        $nombreProducto = $request->input('nombreProducto');
        $letrasIdentificacion = $request->input('letrasIdentificacion');
        $padre_id = $request->input('padre_id');
        $tipo_producto_asociado = $request->input('tipo_producto_asociado');
        $campos = $request->input('campos');
        $camposConOpciones = $request->input('camposConOpciones') ?? [];
        $duracion = $request->input('duracion')[0];

        // Gestión de la duración del tipo de producto
        $tipoDuracion = $duracion['tipo_dato'];
        $valorDuracion = null;

        // Array asociativo para relacionar tipos de duración con días
        $diasRelacionados = [
            'anual' => 365,   // Ejemplo: 365 días
            'mensual' => 30,  // Ejemplo: 30 días
            'diario' => 1,    // Ejemplo: 1 día
        ];

        if (array_key_exists($tipoDuracion, $diasRelacionados)) {
            // Asignar el valor de duración basado en el array asociativo
            $valorDuracion = $diasRelacionados[$tipoDuracion];
        } elseif ($tipoDuracion == 'dias_delimitados') {
            // Si el tipo de duración es 'dias_delimitados', coger la primera opción disponible
            $valorDuracion = $duracion['opciones'][0]['nombre'] ?? null;
        } elseif ($tipoDuracion == 'selector_dias') {
            // Add your code here for 'selector_dias' duration type
            $valorDuracion = Config::get('app.prefijo_duracion') . $letrasIdentificacion;
            $valorDuracion = strtolower($valorDuracion);
            Schema::create($valorDuracion, function (Blueprint $table) {
                $table->id();
                $table->string('duracion');
                $table->decimal('precio_base', 8, 2)->nullable();
                $table->decimal('extra_1', 8, 2)->nullable();
                $table->decimal('extra_2', 8, 2)->nullable();
                $table->decimal('extra_3', 8, 2)->nullable();
                $table->decimal('precio_total', 8, 2)->nullable();
                $table->timestamps();
            });
            if (!empty($duracion['opciones'])) {
                foreach ($duracion['opciones'] as $opcion) {
                    DB::table($valorDuracion)->insert([
                        'duracion' => $opcion['nombre'],
                        'precio_base' => $opcion['precio_base'] ?? null,
                        'extra_1' => $opcion['extra_1'] ?? null,
                        'extra_2' => $opcion['extra_2'] ?? null,
                        'extra_3' => $opcion['extra_3'] ?? null,
                        'precio_total' => $opcion['precio_total'] ?? null,
                        'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                        'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                    ]);
                }
            }
        }

        // Insertar información del tipo de producto en la tabla correspondiente y obtener el ID
        $tipoProductoId = DB::table('tipo_producto')->insertGetId([
            'letras_identificacion' => $letrasIdentificacion,
            'nombre' => $nombreProducto,
            'padre_id' => $padre_id,
            'tipo_producto_asociado' => $tipo_producto_asociado,
            'tipo_duracion' => $tipoDuracion,
            'duracion' => $valorDuracion,
            'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
        ]);

        // Insertar información de los campos en la tabla 'campos'
        foreach ($campos as $campo) {
            DB::table('campos')->insert([
                'nombre' => $campo['nombre'],
                'nombre_codigo' => strtolower(str_replace(' ', '_', $campo['nombre'])),
                'tipo_producto_id' => $tipoProductoId,
                'columna' => $campo['columna'] ?? null,
                'fila' => $campo['fila'] ?? null,
                'tipo_dato' => $campo['tipo_dato'],
                'visible' => $campo['visible'] ?? false,
                'obligatorio' => $campo['obligatorio'] ?? false,
                'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                'grupo' => $campo['grupo'] ?? null,
            ]);
        }

        self::insertDuracionEnCampos($duracion, $tipoProductoId);

        // Crear campos con opciones recorriendo el array de camposConOpciones
        foreach ($camposConOpciones as $campoConOpciones) {
            // Crear el campo con opciones
            $campoController = new CampoController();

            $campoController->createCampoConOpciones($campoConOpciones, $tipoProductoId);
        }

        
        // Definir el nombre de la nueva tabla usando las letras de identificación
        $nombreTabla = strtolower($letrasIdentificacion);


        // Si es un anexo (Es decir tiene tipo_producto_asociado) se crea la tabla solo con los campos
        // con grupo datos_anexo:
        if($tipo_producto_asociado){
            $campos = array_filter($campos, function($campo) {
                return $campo['grupo'] === 'datos_anexo' || $campo['grupo'] === 'datos_fecha';
            });
            
            $camposConOpciones = array_filter($camposConOpciones, function($campo) {
                return $campo['grupo'] === 'datos_anexo' || $campo['grupo'] === 'datos_fecha';
            });
        }
        


        if ($padre_id) {
            // Obtén el nombre de la tabla del padre
            $nombreTablaPadre = DB::table('tipo_producto')->where('id', $padre_id)->value('letras_identificacion');
        
            // Verifica si la tabla existe antes de modificarla
            if (Schema::hasTable($nombreTablaPadre)) {
                Schema::table($nombreTablaPadre, function (Blueprint $table) use ($campos, $camposConOpciones) {
                    if(!Schema::hasColumn($table->getTable(), 'subproducto')){
                        $table->string('subproducto')->nullable();
                    }
                    if(!Schema::hasColumn($table->getTable(), 'subproducto_codigo')){
                        $table->string('subproducto_codigo')->nullable();
                    }
                    // Añadir los campos dinámicos desde $campos
                    foreach ($campos as $campo) {
                        $nombreCampo = strtolower(str_replace(' ', '_', $campo['nombre']));
                        if (!Schema::hasColumn($table->getTable(), $nombreCampo)) {
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
                                default:
                                    $table->string($nombreCampo)->nullable();
                                    break;
                            }
                        }
                    }
        
                    // Añadir campos con opciones desde $camposConOpciones
                    foreach ($camposConOpciones as $campoConOpciones) {
                        $nombreCampo = strtolower(str_replace(' ', '_', $campoConOpciones['nombre']));
                        if (!Schema::hasColumn($table->getTable(), $nombreCampo)) {
                            $table->string($nombreCampo)->nullable();
                        }
                    }
        
                });
            }
        } else {
            // Crear la tabla en la base de datos
            Schema::create($nombreTabla, function (Blueprint $table) use ($campos, $camposConOpciones, $tipo_producto_asociado) {
                $table->id();

                // Estos campos solo se añaden al producto, no al anexo.
                if($tipo_producto_asociado == null){
                    // Agregar campos adicionales
                    $table->unsignedBigInteger('sociedad_id')->nullable();
                    $table->unsignedBigInteger('tipo_de_pago_id')->nullable();
                    $table->unsignedBigInteger('comercial_id')->nullable();
                    // Campo para saber si que comercial crea el producto en nombre de otro
                    $table->unsignedBigInteger('comercial_creador_id')->nullable();
                } else {
                    $table->unsignedBigInteger('producto_id')->nullable();
                    $table->decimal('precio_base', 8, 2)->nullable();
                    $table->decimal('extra_1', 8, 2)->nullable();
                    $table->decimal('extra_2', 8, 2)->nullable();
                    $table->decimal('extra_3', 8, 2)->nullable();
                    $table->decimal('precio_total', 8, 2)->nullable();
                }

                $table->string('plantilla_path')->nullable();
                $table->string('duracion')->nullable();
                // Booleano de si está anulado o no
                $table->boolean('anulado')->default(false);
                
                // Añadimos campos a la tabla
                foreach ($campos as $campo) {
                    $nombreCampo = strtolower(str_replace(' ', '_', $campo['nombre']));
                    switch ($campo['tipo_dato']) {
                        case 'text':
                            $table->string($nombreCampo)->nullable();
                            break;
                        case 'decimal':
                            $table->decimal($nombreCampo, 8, 2)->nullable();  // Cambié a decimal en lugar de string para manejar mejor los números decimales
                            break;
                        case 'number':
                            $table->integer($nombreCampo)->nullable();
                            break;
                        case 'date':
                            $table->date($nombreCampo)->nullable();
                            break;
                        default:
                            $table->string($nombreCampo)->nullable();
                            break;
                    }
                }

                // Añadimos campos con opciones a la tabla
                foreach ($camposConOpciones as $campoConOpciones) {
                    $nombreCampo = strtolower(str_replace(' ', '_', $campoConOpciones['nombre']));
                    $table->string($nombreCampo)->nullable();
                }
                
                $table->timestamps();
            });
        }
        

        return response()->json([
            'message' => 'Producto creado con éxito',
            'id' => $tipoProductoId
        ], 200);
    }

    private function insertDuracionEnCampos($duracion, $tipoProductoId){
        DB::table('campos')->insert([
            'nombre' => 'Duración',
            'nombre_codigo' => 'duracion',
            'tipo_producto_id' => $tipoProductoId,
            'columna' => $duracion['columna'] ?? null,
            'fila' => $duracion['fila'] ?? null,
            'tipo_dato' => $duracion['tipo_dato'],
            'visible' => $duracion['visible'] ?? false,
            'obligatorio' => $duracion['obligatorio'] ?? false,
            'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
            'grupo' => $duracion['grupo'] ?? null,
        ]);
    }

    public function subirPlantilla($letrasIdentificacion, Request $request)
    {
        if ($request->hasFile('plantilla')) {

            $archivoPlantilla = $request->file('plantilla');
            $nombreArchivo = $archivoPlantilla->getClientOriginalName();
            $rutaArchivo = 'plantillas/' . $nombreArchivo;

            // Comprobar si ya existe un archivo con el mismo nombre
            if (Storage::disk('public')->exists($rutaArchivo)) {
                return response()->json(['error' => 'Ya existe una plantilla con ese nombre'], 400);
            }

            // Guardar la plantilla Excel en el sistema de archivos
            Storage::disk('public')->putFileAs('plantillas', $archivoPlantilla, $nombreArchivo);

            // Añadir la ruta de la plantilla a la tabla tipo_producto
            DB::table('tipo_producto')
                ->where('letras_identificacion', (Config::get('app.prefijo_tipo_producto') . $letrasIdentificacion))
                ->update(['plantilla_path' => $rutaArchivo]);

            return response()->json(['message' => 'Plantilla subida correctamente'], 200);
        } else {
            return response()->json(['error' => 'No se recibió ninguna plantilla'], 400);
        }
    }


    public function getProductosByTipoAndSociedades($letrasIdentificacion, Request $request)
    {
        $sociedades = $request->query('sociedades');

        if ($sociedades) {
            $sociedades = explode(',', $sociedades);
        } else {
            $sociedades = [];
        }
        
        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($letrasIdentificacion);
        
        // Obtener la fecha y hora actual
        $fechaActual = now();
        
        // Realizar consulta dinámica usando el nombre de la tabla
        $productos = DB::table($nombreTabla)
            ->when(count($sociedades) > 0, function ($query) use ($sociedades) {
                $query->whereIn('sociedad_id', $sociedades);
            })
            ->where('fecha_de_fin', '>', $fechaActual) // Filtrar productos con fecha_de_fin mayor que la fecha actual
            ->orderBy('updated_at', 'desc') // Ordenar por fecha de actualización de forma descendente
            ->get();
        
        return response()->json($productos);
    }

    public function getProductosByTipoAndComercial($letrasIdentificacion, $comercial_id){
        
        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($letrasIdentificacion);
        
        // Obtener la fecha y hora actual
        $fechaActual = now();
        
        // Realizar consulta dinámica usando el nombre de la tabla
        $productos = DB::table($nombreTabla)
            ->where('comercial_id', $comercial_id)
            ->where('fecha_de_fin', '>', $fechaActual) // Filtrar productos con fecha_de_fin mayor que la fecha actual
            ->orderBy('updated_at', 'desc') // Ordenar por fecha de actualización de forma descendente
            ->get();
        
        return response()->json($productos);
    }


    public function crearProducto($letrasIdentificacion, Request $request)
    {
        // Obtener el id del tipo_producto basado en las letras_identificacion
        $tipoProducto = DB::table('tipo_producto')
                        ->where('letras_identificacion', $letrasIdentificacion)
                        ->first();


        if (!$tipoProducto) {
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }

        // Obtener la plantilla antes de gestionar el tipoProducto padre
        $plantilla_path = $tipoProducto->plantilla_path ?? null;

        // Si el tipoProducto tiene padre, coger el tipoProducto padre para meter los datos en la tabla correspondiente
        if($tipoProducto->padre_id != null){
            $tipoProducto = DB::table('tipo_producto')
                        ->where('id', $tipoProducto->padre_id)
                        ->first();
        }

        $tipoProductoId = $tipoProducto->id;

        // Obtener los campos relacionados con el tipo_producto_id
        $camposRelacionados = DB::table('campos')
                                ->where('tipo_producto_id', $tipoProductoId)
                                ->get();

        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($tipoProducto->letras_identificacion);

        // Validar los datos recibidos
        $request->validate([
            'nuevoProducto' => 'required|array',
        ]);

        // Cojo los datos del nuevo producto
        $datos = $request->input('nuevoProducto');

        //Añadir a los datos la plantilla_path que tenga el seguro en ese momento:
        $datos['plantilla_path'] = $plantilla_path;

        // Formatear los campos datetime al formato deseado
        foreach ($camposRelacionados as $campo) {
            $nombreCampo = strtolower(str_replace(' ', '_', $campo->nombre));
            if ($campo->tipo_dato == 'date' && isset($datos[$nombreCampo])) {
                $datos[$nombreCampo] = Carbon::createFromFormat('Y-m-d', $datos[$nombreCampo])->format('Y-m-d\TH:i:s');
            }
        }

        // Obtener el último código de producto generado
        $tableDatePrefix = Carbon::now()->format('mY');
        $lastProduct = DB::table($nombreTabla)
            ->orderBy('id', 'desc')
            ->first();

        // Calcular el siguiente número secuencial
        $lastNumber = $lastProduct ? intval(substr($lastProduct->codigo_producto, -6)) : 0;
        $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

        // Obtén el prefijo desde la configuración
        $prefijo = strtolower(Config::get('app.prefijo_tipo_producto'));
    
        // Elimina el prefijo del código
        $codigoPorTipoProducto = str_replace($prefijo, '', $letrasIdentificacion);

        $newCodigoProducto = $tableDatePrefix . strtoupper($codigoPorTipoProducto) . $newNumber;

        // Añadir el código de producto al array de datos
        $datos['codigo_producto'] = $newCodigoProducto;

        // Añadir created_at y updated_at al array de datos
        $datos['created_at'] = Carbon::now()->format('Y-m-d\TH:i:s');
        $datos['updated_at'] = Carbon::now()->format('Y-m-d\TH:i:s');
        // $datos['hora_inicio'] = Carbon::now()->format('H:i:s');
        $hora = Carbon::now()->format('H:i:s');

        // $datos['fecha_de_inicio'] = $datos['fecha_de_inicio'] . $hora;

        // $datos['fecha_de_fin'] = $datos['fecha_de_fin'] . $hora;

        // Insertar los datos en la tabla correspondiente
        $id = DB::table($nombreTabla)->insertGetId($datos);

        return response()->json(['id' => $id], 201);
    }



    public function editarProducto($letrasIdentificacion, Request $request){
        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($letrasIdentificacion);
        
        // Coger el resto de datos de la request excepto el id:
        $datos = $request->input('productoEditado');

        $id = $datos['id'];

        // Quitar el id de los datos:
        unset($datos['id']);

        $datos['updated_at'] = Carbon::now()->format('Y-m-d\TH:i:s');
        
        // Actualizar los datos en la tabla correspondiente
        DB::table($nombreTabla)
            ->where('id', $id)
            ->update($datos);
        
        return response()->json(['message' => 'Producto actualizado con éxito',
        'id' => $id], 200);
    }

    public function eliminarProducto($letrasIdentificacion, Request $request){
        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($letrasIdentificacion);

        $id = $request->input('id');
        
        // Eliminar el producto de la tabla correspondiente
        DB::table($nombreTabla)->where('id', $id)->delete();
        
        return response()->json(['message' => 'Producto eliminado con éxito'], 200);
    }

    public function anularProducto($letrasIdentificacion, Request $request){
        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($letrasIdentificacion);

        $id = $request->input('id');
        
        // Actualizar el campo 'anulado' a true en la tabla correspondiente
        DB::table($nombreTabla)
            ->where('id', $id)
            ->update(['anulado' => true]);

        // Meter la anulación a la tabla de anulaciones
        $anulacionId = DB::table('anulaciones')->insertGetId([
            'fecha' => Carbon::now()->format('Y-m-d\TH:i:s'),
            'sociedad_id' => $request->input('sociedad_id'),
            'comercial_id' => $request->input('comercial_id'),
            'sociedad_nombre' => $request->input('sociedad_nombre'),
            'comercial_nombre' => $request->input('comercial_nombre'),
            'causa' => $request->input('causa'),
            'letrasIdentificacion' => $letrasIdentificacion,
            'producto_id' => $id,
            'codigo_producto' => $request->input('codigo_producto')
        ]);
        
        return response()->json(['message' => 'Producto anulado con éxito'], 200);
    }

    public function getDuraciones($nombreTabla){

        // Coger todos los datos de la tabla $nombreTabla:
        $datos = DB::table($nombreTabla)->get();

        return response()->json($datos);

    }

    
}
