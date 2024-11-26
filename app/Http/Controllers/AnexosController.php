<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use App\Models\TiposAnexos;
use App\Models\TipoProductoSociedad;
use App\Models\TipoProducto;

class AnexosController extends Controller
{
    public function conectarAnexosConProducto($id_producto, Request $request){
        // Me llegará un array con todos los anexos que tengo que crear y conectar con el producto
        // Si ya tiene un id se cambia el mismo anexo sino se crea unno nuevo
        // El formato en el que llegan los anexos es el siguiente:
        // {id: '', formato: this.formatosAnexos[tipo_anexo.id], tipo_anexo: tipo_anexo}
        // La tabla en la que hay que meterlos es la siguiente:
        // SELECT TOP (1000) [id]
        //     ,[producto_id]
        //     ,[perro_asegurado]
        //     ,[created_at]
        //     ,[updated_at]
        // FROM [KYREMA].[dbo].[letras_identificacion que estan dentro del tipo_anexo]
        // Obtener el array de anexos desde el request
        $anexos = $request->input('anexos');

        foreach ($anexos as $anexo) {
            $tipoAnexo = $anexo['tipo_anexo']; // Tipo de anexo
            $letrasIdentificacion = strtolower($tipoAnexo['letras_identificacion']); // Nombre de la tabla
            $duracion = $tipoAnexo['duracion']; // Duración del anexo
            $plantillasPaths = [
                $tipoAnexo['plantilla_path_1'], $tipoAnexo['plantilla_path_2'], $tipoAnexo['plantilla_path_3'], $tipoAnexo['plantilla_path_4'],
                $tipoAnexo['plantilla_path_5'], $tipoAnexo['plantilla_path_6'], $tipoAnexo['plantilla_path_7'], $tipoAnexo['plantilla_path_8'],
            ]; // Plantillas asociadas al anexo
            $anexoId = $anexo['id']; // ID del anexo (si existe)
            $formato = $anexo['formato']; // Campos dinámicos del anexo
            $tarifas = $anexo['tarifas']; // Tarifas del anexo

            // Verificar que la tabla existe
            if (Schema::hasTable($letrasIdentificacion)) {
                // Construir los datos a insertar/actualizar, agregando siempre el id_producto y las marcas de tiempo

                $data = [
                    'producto_id' => $id_producto,
                    'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s')
                ];


                $data['precio_base'] = $tarifas['precio_base'];
                $data['extra_1'] = $tarifas['extra_1'];
                $data['extra_2'] = $tarifas['extra_2'];
                $data['extra_3'] = $tarifas['extra_3'];
                $data['precio_total'] = $tarifas['precio_total'];
                
                
                // Agregar los campos dinámicos del formato
                foreach ($formato as $key => $value) {
                    $data[$key] = $value;
                }

                // Agregar la plantilla que tenga asignado el tipo de anexo
                $data['plantilla_path_1'] = $plantillasPaths[0];
                $data['plantilla_path_2'] = $plantillasPaths[1];
                $data['plantilla_path_3'] = $plantillasPaths[2];
                $data['plantilla_path_4'] = $plantillasPaths[3];
                $data['plantilla_path_5'] = $plantillasPaths[4];
                $data['plantilla_path_6'] = $plantillasPaths[5];
                $data['plantilla_path_7'] = $plantillasPaths[6];
                $data['plantilla_path_8'] = $plantillasPaths[7];

                if ($anexoId) {
                    // Si el anexo tiene un ID, se actualiza el registro existente
                    DB::table($letrasIdentificacion)->where('id', $anexoId)->update($data);
                } else {
                    // Si no tiene ID, se crea un nuevo registro
                    // Se añade el dato de created_at a data:
                    $data['created_at'] = Carbon::now()->format('Y-m-d\TH:i:s');

                    if($tipoAnexo['tipo_duracion'] != 'fecha_dependiente'){
                        // Se añade la duracion a la fecha de inicio y se inserta en la fecha_de_fin
                        $data['fecha_de_fin'] = Carbon::parse($formato['fecha_de_inicio'])
                            ->addDays(intval($duracion)) // Convertir la duración a un entero
                            ->format('Y-m-d\TH:i:s');
                        $data['duracion'] = $duracion;    
                    } else {
                        // Si la fecha es dependiente, la duracion son los dias entre fecha de inicio y fecha de fin
                        $data['duracion'] = Carbon::parse($formato['fecha_de_fin'])
                            ->diffInDays(Carbon::parse($formato['fecha_de_inicio']));
                    }
                    $data['fecha_de_emisión'] = Carbon::now()->format('Y-m-d\TH:i:s');
                    
                    DB::table($letrasIdentificacion)->insert($data);
                }
            } else {
                return response()->json(['error' => "La tabla {$letrasIdentificacion} no existe."], 400);
            }
        }
        return response()->json(['message' => 'Anexos conectados con éxito'], 200);
    }

    public function createTipoAnexo(Request $request){
         // Validar los datos recibidos
        $request->validate([
            'nombre' => 'required|string',
            'letras_identificacion' => 'required|string',
            'campos' => 'required|array',
            'campos.*.nombre' => 'required|string',
            'campos.*.tipo_dato' => 'required|string|in:text,number,date,decimal',
            'tipoProductoAsociado' => 'required|integer|exists:tipo_producto,id',
            'duracion' => 'nullable|array',
        ]);

        $nombre = $request->input('nombre');
        $letrasIdentificacion = $request->input('letras_identificacion');
        $campos = $request->input('campos');
        $tipoProductoAsociado = $request->input('tipoProductoAsociado');
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
                $table->decimal('precio', 8, 2)->nullable();
                $table->timestamps();
            });
            if (!empty($duracion['opciones'])) {
                foreach ($duracion['opciones'] as $opcion) {
                    DB::table($valorDuracion)->insert([
                        'duracion' => $opcion['nombre'],
                        'precio' => $opcion['precio'] ?? null,
                        'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                        'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                    ]);
                }
            }
        }

        // Definir el nombre de la nueva tabla usando las letras de identificación
        $nombreTabla = strtolower($letrasIdentificacion);

        // Crear la tabla en la base de datos
        Schema::create($nombreTabla, function (Blueprint $table) use ($campos) {
            $table->id();

            // Agregar campos adicionales
            $table->unsignedBigInteger('producto_id')->nullable();
            $table->string('plantilla_path')->nullable();
            $table->string('duracion')->nullable();
            // Booleano de si está anulado o no
            $table->boolean('anulado')->default(false);

            foreach ($campos as $campo) {
                $nombreCampo = strtolower(str_replace(' ', '_', $campo['nombre']));
                switch ($campo['tipo_dato']) {
                    case 'text':
                        $table->string($nombreCampo)->nullable();
                        break;
                    case 'decimal':
                        $table->decimal($nombreCampo, 10, 2)->nullable();
                        break;
                    case 'number':
                        $table->integer($nombreCampo)->nullable();
                        break;
                    case 'date':
                        $table->date($nombreCampo)->nullable();
                        break;
                }
            }
            $table->timestamps();
        });

        // Insertar información del tipo de anexo en la tabla correspondiente y obtener el ID
        $tipoAnexoId = DB::table('tipos_anexos')->insertGetId([
            'nombre' => $nombre,
            'letras_identificacion' => $letrasIdentificacion,
            'id_tipo_producto' => $tipoProductoAsociado,
            'duracion' => $valorDuracion,
            'tipo_duracion' => $tipoDuracion,
            'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
        ]);

        // Insertar información de los campos en la tabla 'campos_anexos'
        foreach ($campos as $campo) {
            DB::table('campos_anexos')->insert([
                'nombre' => $campo['nombre'],
                //nombre_codigo es el nombre pero todo en minusculas y reemplazando los espacios por guiones bajos
                'nombre_codigo' => strtolower(str_replace(' ', '_', $campo['nombre'])),
                'tipo_anexo' => $tipoAnexoId,
                'columna' => $campo['columna'] ?? null,
                'fila' => $campo['fila'] ?? null,
                'tipo_dato' => $campo['tipo_dato'],
                'obligatorio' => $campo['obligatorio'] ?? false,
                'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                'grupo' => $campo['grupo'] ?? null,
            ]);
        }

        self::insertDuracionEnCampos($duracion, $tipoAnexoId);

        return response()->json([
            'message' => 'Anexo creado con éxito',
            'id' => $tipoAnexoId,
            'letras_identificacion' => $letrasIdentificacion,
        ], 200);
    }

    private function insertDuracionEnCampos($duracion, $tipoProductoId){
        DB::table('campos_anexos')->insert([
            'nombre' => 'Duración',
            'nombre_codigo' => 'duracion',
            'tipo_anexo' => $tipoProductoId,
            'columna' => $duracion['columna'] ?? null,
            'fila' => $duracion['fila'] ?? null,
            'tipo_dato' => $duracion['tipo_dato'],
            'obligatorio' => $duracion['obligatorio'] ?? false,
            'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
            'grupo' => $duracion['grupo'] ?? null,
        ]);
    }

    public function getAnexosPorProducto($id_tipo_producto , $id_producto){ 
        // IMPORTANTE PUEDE HABER MÁS DE UN TIPO ANEXO POR TIPO PRODUCTO
        // Necesito coger todos las letras_identificacion e ids de TiposAnexos que tengan id_tipo_producto = $id_tipo_producto

        // Las letras_identificacion usarlas como nombres de tablas y coger todos los anexos relacionados con el $id_producto y poner el anexo con el siguiente formato:
        // {id: '', formato: this.formatosAnexos[tipo_anexo.id], tipo_anexo: tipo_anexo} (Es decir que el tipo_anexo me lo deberia de haber guardado previamente)
        
         // Obtener los tipos de anexos asociados al tipo de producto
        $tiposAnexos = DB::table('tipo_producto')
        ->where('tipo_producto_asociado', $id_tipo_producto)
        ->get();

        $anexos = [];

        // Iterar sobre los tipos de anexos para buscar en las tablas correspondientes
        foreach ($tiposAnexos as $tipoAnexo) {
            $nombreTabla = strtolower($tipoAnexo->letras_identificacion);

            if (Schema::hasTable($nombreTabla)) {
                // Obtener los anexos de la tabla correspondiente al tipo de anexo
                $anexosDeTabla = DB::table($nombreTabla)
                    ->where('producto_id', $id_producto)
                    ->get();

                // Formatear cada anexo con el formato especificado
                foreach ($anexosDeTabla as $anexo) {
                    $anexos[] = [
                        'id' => $anexo->id,
                        // El formato son todos los campos excepto los campos de control (producto_id, created_at, updated_at)
                        'formato' => collect($anexo)->except(['id', 'producto_id', 'created_at', 'updated_at'])->toArray(),
                        'tarifas' => collect($anexo)->only(['precio_base', 'extra_1', 'extra_2', 'extra_3', 'precio_total'])->toArray(),
                        'tipo_anexo' => $tipoAnexo
                    ];
                }
            }
        }

        return response()->json($anexos);
    }

    public function show(string $id){
        $tipoAnexo = TiposAnexos::findOrFail($id);
        return response()->json($tipoAnexo);
    }


    public function getAnexosPorSociedad($id_sociedad){
        //Cogemos todos los tipos_productos asociados a esta sociedad:
        $tiposProductoSociedad = TipoProductoSociedad::where('id_sociedad', $id_sociedad)->get();

        $tiposProductoSociedadIds = $tiposProductoSociedad->pluck('id_tipo_producto');

        //Cogemos todos los tipos_anexo asociados a estos tipos_productos:
        $tiposAnexo = TipoProducto::whereIn('tipo_producto_asociado', $tiposProductoSociedadIds)->get();

        return response()->json($tiposAnexo);
    }

    public function getTipoAnexosPorTipoProducto($id_tipo_producto){

        $tiposAnexo = TipoProducto::where('tipo_producto_asociado', $id_tipo_producto)->get();

        return response()->json($tiposAnexo);

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        
        $tipoAnexo = TipoProducto::findOrFail($id);
        $plantillasPaths = [$tipoAnexo->plantilla_path_1, $tipoAnexo->plantilla_path_2, $tipoAnexo->plantilla_path_3, $tipoAnexo->plantilla_path_4];

        // Borrar la tabla asociada al tipo de anexo
        $letrasIdentificacion = $tipoAnexo->letras_identificacion;
        $nombreTabla = strtolower($letrasIdentificacion);
        Schema::dropIfExists($nombreTabla);

        // Borrar el tipo de anexo de la base de datos
        $tipoAnexo->delete();

        // Borrar los campos asociados al tipo de anexo
        DB::table('campos')->where('tipo_producto_id', $id)->delete();

        //Eliminar las tarifas asociadas al tipo de anexo
        DB::table('tarifas_producto')->where('tipo_producto_id', $id)->delete();

        foreach ($plantillasPaths as $plantillaPath) {
            // Eliminar la plantilla si existe
            if ($plantillaPath && Storage::disk('public')->exists($plantillaPath)) {
                Storage::disk('public')->delete($plantillaPath);
            }
        }

        return response()->json(null, 204);
    }

    public function subirPlantillaAnexo($letrasIdentificacion, Request $request){

        if ($request->hasFile('plantilla')) {

            $archivoPlantilla = $request->file('plantilla');
            $nombreArchivo = $archivoPlantilla->getClientOriginalName();
            $rutaArchivo = 'plantillas/anexos/' . $nombreArchivo;

            // Comprobar si ya existe un archivo con el mismo nombre
            if (Storage::disk('public')->exists($rutaArchivo)) {
                return response()->json(['error' => 'Ya existe una plantilla con ese nombre'], 400);
            }

            // Guardar la plantilla Excel en el sistema de archivos
            $rutaPlantilla = Storage::disk('public')->putFileAs('plantillas/anexos', $archivoPlantilla, $nombreArchivo);

            // Añadir la ruta de la plantilla a la tabla tipo_producto
            DB::table('tipos_anexos')
                ->where('letras_identificacion', $letrasIdentificacion)
                ->update(['plantilla_path' => $rutaPlantilla]);

            return response()->json(['message' => 'Plantilla subida correctamente'], 200);
        } else {
            return response()->json(['error' => 'No se recibió ninguna plantilla'], 400);
        }

    }
}
