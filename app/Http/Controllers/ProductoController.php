<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log; // Importar la clase Log
use App\Http\Controllers\CampoController;
use Illuminate\Support\Facades\Config;
use App\Models\Socio;
use App\Models\Comercial;
use App\Models\SocioProducto;
use App\Services\ReferenceService;
use App\Support\Dni;

class ProductoController extends Controller
{
    // Array para seleccionar el numero de días dependiendo de el tipoDuracion


    public function crearTipoProducto(Request $request)
    {
        DB::beginTransaction();

        try {
            // Validar los datos recibidos
            $request->validate([
                'nombreProducto' => 'required|string',
                'letrasIdentificacion' => 'required|string',
                'acuerdo_kyrema' => 'nullable|boolean',
                'categoria_id' => 'nullable|integer',
                'columna_logo_sociedad' => 'nullable|string',
                'fila_logo_sociedad' => 'nullable|string',
                'page_logo_sociedad' => 'nullable|string',
                'padre_id' => 'nullable|integer',
                'tipo_producto_asociado' => 'nullable|integer',
                'separacion_anexos' => 'nullable|string',
                'polizas' => 'nullable|array',
                'campos' => 'nullable|array',
                'campos.*.nombre' => 'required|string',
                'campos.*.tipo_dato' => 'required|string|in:text,number,date,decimal,selector,select,time',
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
            $categoria_id = $request->input('categoria_id');
            $acuerdo_kyrema = $request->input('acuerdo_kyrema');
            $nombre_unificado = $request->input('nombre_unificado');
            $campos_logos = $request->input('campos_logos');
            $padre_id = $request->input('padre_id');
            $tipo_producto_asociado = $request->input('tipo_producto_asociado');
            $separacion_anexos = $request->input('separacion_anexos');
            $polizas = $request->input('polizas');
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
                'categoria_id' => $categoria_id,
                'acuerdo_kyrema' => $acuerdo_kyrema,
                'nombre_unificado' => $nombre_unificado,
                'nombre' => $nombreProducto,
                'padre_id' => $padre_id,
                'tipo_producto_asociado' => $tipo_producto_asociado,
                'separacion_anexos' => $separacion_anexos,
                'tipo_duracion' => $tipoDuracion,
                'duracion' => $valorDuracion,
                'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
            ]);

            if ($polizas && count($polizas) > 0) {
                // Conectar las polizas con el tipo_producto
                self::insertPolizas($polizas, $tipoProductoId);
            }

            if ($campos_logos && count($campos_logos) > 0) {
                // Conectar las polizas con el tipo_producto
                self::insertLogos($campos_logos, $tipoProductoId);
            }

            // Insertar información de los campos en la tabla 'campos'
            foreach ($campos as $campo) {
                DB::table('campos')->insert([
                    'nombre' => $campo['nombre'],
                    'nombre_codigo' => strtolower(str_replace(' ', '_', $campo['nombre'])),
                    'tipo_producto_id' => $tipoProductoId,
                    'columna' => $campo['columna'] ?? null,
                    'fila' => $campo['fila'] ?? null,
                    'page' => $campo['page'] ?? null,
                    'font_size' => $campo['font_size'] ?? null,
                    'tipo_dato' => $campo['tipo_dato'],
                    'visible' => $campo['visible'] ?? false,
                    'obligatorio' => $campo['obligatorio'] ?? false,
                    'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                    'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                    'grupo' => $campo['grupo'] ?? null,
                    'copia' => $campo['copia'] ?? false,
                ]);
            }

            // Duraciones con campos 'copia'.
            $duraciones = $request->input('duracion');
            self::insertDuracionEnCampos($duraciones, $tipoProductoId);


            // Definir el nombre de la nueva tabla usando las letras de identificación
            $nombreTabla = strtolower($letrasIdentificacion);

            // Filtrar y quitar las COPIAS para que no se inserten en la tabla duplicados:
            $campos = array_filter($campos, function ($campo) {
                return $campo['copia'] === false;
            });

            $camposConOpciones = array_filter($camposConOpciones, function ($campo) {
                return $campo['copia'] === false;
            });

            // Si es un anexo (Es decir tiene tipo_producto_asociado) se crea la tabla solo con los campos
            // con grupo datos_anexo:
            if ($tipo_producto_asociado) {
                $campos = array_filter($campos, function ($campo) {
                    return $campo['grupo'] === 'datos_anexo' || $campo['grupo'] === 'datos_fecha';
                });

                $camposConOpciones = array_filter($camposConOpciones, function ($campo) {
                    return $campo['grupo'] === 'datos_anexo' || $campo['grupo'] === 'datos_fecha';
                });
            }


            if ($padre_id) {
                // Obtén el nombre de la tabla del padre
                $nombreTablaPadre = DB::table('tipo_producto')->where('id', $padre_id)->value('letras_identificacion');

                Log::info($nombreTablaPadre);

                // Verifica si la tabla existe antes de modificarla
                if (Schema::hasTable($nombreTablaPadre)) {
                    Schema::table($nombreTablaPadre, function (Blueprint $table) use ($campos, $camposConOpciones) {
                        if (!Schema::hasColumn($table->getTable(), 'subproducto')) {
                            $table->string('subproducto')->nullable();
                        }
                        if (!Schema::hasColumn($table->getTable(), 'subproducto_codigo')) {
                            $table->string('subproducto_codigo')->nullable();
                        }
                        if (!Schema::hasColumn($table->getTable(), 'sociedad_id')) {
                            $table->unsignedBigInteger('sociedad_id')->nullable()->index();
                        }
                        // Añadir los campos dinámicos desde $campos
                        foreach ($campos as $campo) {
                            $nombreCampo = strtolower(str_replace(' ', '_', $campo['nombre']));
                            if (!Schema::hasColumn($table->getTable(), $nombreCampo)) {
                                switch ($campo['tipo_dato']) {
                                    case 'text':
                                        $table->string($nombreCampo)->nullable();
                                        break;
                                    case 'number':
                                        $table->integer($nombreCampo)->nullable();
                                        break;
                                    case 'date':
                                        $table->dateTime($nombreCampo)->nullable();
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
                    if ($tipo_producto_asociado == null) {
                        // Agregar campos adicionales
                        $table->unsignedBigInteger('sociedad_id')->nullable()->index();
                        $table->unsignedBigInteger('tipo_de_pago_id')->nullable()->index();
                        $table->unsignedBigInteger('comercial_id')->nullable()->index();
                        $table->unsignedBigInteger('pago_id')->nullable()->index();
                        // Campo para saber si el comercial crea el producto en nombre de otro
                        $table->unsignedBigInteger('comercial_creador_id')->nullable()->index();

                        // Define si se ha contratado mediante un comercial con tipo_comercial 'Pagina Web' 
                        // Se usa por si una sociedad tiene venta directa por la web se le crea un comercial tipo 'Pagina Web' para 
                        // no relacionar las ventas directas de la web con un comercial real.
                        $table->boolean('mediante_pagina_web')->nullable();
                        $table->unsignedBigInteger('socio_id')->nullable();
                        $table->string('logo_sociedad_path')->nullable();
                    } else {
                        $table->unsignedBigInteger('producto_id')->nullable()->index();
                        $table->decimal('precio_base', 8, 2)->nullable();
                        $table->decimal('extra_1', 8, 2)->nullable();
                        $table->decimal('extra_2', 8, 2)->nullable();
                        $table->decimal('extra_3', 8, 2)->nullable();
                        $table->decimal('precio_total', 8, 2)->nullable();
                    }

                    $table->string('plantilla_path_1')->nullable();
                    $table->string('plantilla_path_2')->nullable();
                    $table->string('plantilla_path_3')->nullable();
                    $table->string('plantilla_path_4')->nullable();
                    $table->string('plantilla_path_5')->nullable();
                    $table->string('plantilla_path_6')->nullable();
                    $table->string('plantilla_path_7')->nullable();
                    $table->string('plantilla_path_8')->nullable();
                    $table->string('duracion')->nullable();
                    // Booleano de si está anulado o no
                    $table->boolean('anulado')->default(false);
                    $table->string('blob_name')->nullable();

                    // Añadimos campos a la tabla
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
                                $table->datetime($nombreCampo)->nullable();
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

            // Crear campos con opciones recorriendo el array de camposConOpciones
            foreach ($camposConOpciones as $campoConOpciones) {
                $campoController = new CampoController();

                $campoController->createCampoConOpciones($campoConOpciones, $tipoProductoId);
            }

            DB::commit();
            return response()->json([
                'message' => 'Producto creado con éxito',
                'id' => $tipoProductoId
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al crear el tipo de producto', 'message' => $e->getMessage()], 500);
        }
    }

    private function insertLogos($campos_logos, $tipoProductoId)
    {
        foreach ($campos_logos as $campo_logo) {
            DB::table('campos_logos')->insert([
                'tipo_logo' => $campo_logo['tipo_logo'],
                'entidad_id' => $campo_logo['entidad_id'],
                'tipo_producto_id' => $tipoProductoId,
                'columna' => $campo_logo['columna'] ?? null,
                'fila' => $campo_logo['fila'] ?? null,
                'page' => $campo_logo['page'] ?? null,
                'altura' => $campo_logo['altura'] ?? null,
                'ancho' => $campo_logo['ancho'] ?? null,
            ]);
        }
    }

    private function insertPolizas($polizas, $tipoProductoId)
    {
        foreach ($polizas as $poliza) {
            DB::table('tipo_producto_polizas')->insert([
                'compania_id' => $poliza['compania_id'],
                'poliza_id' => $poliza['poliza_id'],
                'tipo_producto_id' => $tipoProductoId,
                'fila' => $poliza['fila'] ?? null,
                'page' => $poliza['page'] ?? null,
                'font_size' => $poliza['font_size'] ?? null,
                'columna' => $poliza['columna'] ?? null,
                'copia' => $poliza['copia'] ?? false,
            ]);
        }
    }

    private function insertDuracionEnCampos($duraciones, $tipoProductoId)
    {
        foreach ($duraciones as $duracion) {
            DB::table('campos')->insert([
                'nombre' => 'Duración',
                'nombre_codigo' => 'duracion',
                'tipo_producto_id' => $tipoProductoId,
                'columna' => $duracion['columna'] ?? null,
                'fila' => $duracion['fila'] ?? null,
                'page' => $duracion['page'] ?? null,
                'font_size' => $duracion['font_size'] ?? null,
                'tipo_dato' => $duracion['tipo_dato'],
                'visible' => $duracion['visible'] ?? false,
                'obligatorio' => $duracion['obligatorio'] ?? false,
                'created_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                'updated_at' => Carbon::now()->format('Y-m-d\TH:i:s'),
                'grupo' => $duracion['grupo'] ?? null,
                'copia' => $duracion['copia'] ?? false,
            ]);
        }
    }

    public function subirPlantilla($id_tipo_producto, $page, Request $request)
    {
        // Borrar la plantilla anterior
        $tipoProducto = DB::table('tipo_producto')
            ->where('id', $id_tipo_producto)
            ->first();

        if ($request->hasFile('plantilla')) {
            $archivoPlantilla = $request->file('plantilla');
            $nombreArchivo = $archivoPlantilla->getClientOriginalName();
            $rutaArchivo = 'plantillas/' . $nombreArchivo;

            // Renombrar el archivo si ya existe con "- copia"
            $contador = 1;
            while (Storage::disk('public')->exists($rutaArchivo)) {
                // Generar un nuevo nombre con "- copia" y un número si es necesario
                $nombreArchivoSinExtension = pathinfo($archivoPlantilla->getClientOriginalName(), PATHINFO_FILENAME);
                $extension = $archivoPlantilla->getClientOriginalExtension();
                $nombreArchivo = $nombreArchivoSinExtension . ' - copia' . ($contador > 1 ? " {$contador}" : '') . '.' . $extension;
                $rutaArchivo = 'plantillas/' . $nombreArchivo;
                $contador++;
            }

            // Guardar la plantilla Excel en el sistema de archivos
            Storage::disk('public')->putFileAs('plantillas', $archivoPlantilla, $nombreArchivo);

            $plantilla_path_name = 'plantilla_path_' . $page;

            Log::info($plantilla_path_name);
            Log::info($rutaArchivo);

            // Añadir la ruta de la plantilla a la tabla tipo_producto
            DB::table('tipo_producto')
                ->where('id', $id_tipo_producto)
                ->update([$plantilla_path_name => $rutaArchivo]);

            return response()->json(['message' => 'Plantilla:' . $page . 'subida correctamente'], 200);
        } else {
            return response()->json(['error' => 'No se recibió ninguna plantilla'], 400);
        }
    }

    public function getProductosByTipoAndSociedades($letrasIdentificacion, Request $request)
    {
        // Obtener las sociedades del request
        $sociedades = $request->query('sociedades');

        if ($sociedades) {
            $sociedades = explode(',', $sociedades);
        } else {
            $sociedades = [];
        }

        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($letrasIdentificacion);

        // Obtener la fecha y hora actual
        $fechaActual = Carbon::now()->format('Y-m-d\TH:i:s');

        // Obtener el tipo de producto por las letras de identificación
        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        // Obtener todas las tablas de anexos asociados al tipo de producto
        $anexos = DB::table('tipo_producto')
            ->where('tipo_producto_asociado', $tipoProducto->id)
            ->pluck('letras_identificacion'); // Solo letras de identificación para las tablas de anexos

        // 1. Consulta principal: Obtener productos vigentes por fecha y sociedades
        $productosVigentes = DB::table($nombreTabla)
            ->when(count($sociedades) > 0 && !in_array(env('SOCIEDAD_ADMIN_ID', 1), $sociedades), function ($query) use ($sociedades) {
                $query->whereIn('sociedad_id', $sociedades);
            })
            ->orderBy('updated_at', 'desc')
            ->get();

        // 2. Consulta de productos con anexos vigentes en las tablas asociadas
        $productosConAnexosVigentes = collect(); // Inicializar colección vacía para productos con anexos

        foreach ($anexos as $letraAnexo) {
            // Convertir letra del anexo en nombre de tabla
            $nombreTablaAnexo = strtolower($letraAnexo);

            // Consultar productos que tienen anexos vigentes en cada tabla de anexos
            $productosAnexoVigentes = DB::table($nombreTablaAnexo)
                ->join($nombreTabla, "$nombreTablaAnexo.producto_id", '=', "$nombreTabla.id")
                ->when(count($sociedades) > 0 && !in_array(env('SOCIEDAD_ADMIN_ID', 1), $sociedades), function ($query) use ($sociedades, $nombreTabla) {
                    $query->whereIn("$nombreTabla.sociedad_id", $sociedades);
                })
                ->select("$nombreTabla.*") // Seleccionar solo los productos
                ->orderBy("$nombreTabla.updated_at", 'desc')
                ->get();

            // Combinar productos con anexos vigentes en la colección
            $productosConAnexosVigentes = $productosConAnexosVigentes->merge($productosAnexoVigentes);
        }

        // 3. Combinar los productos vigentes directamente con los productos que tienen anexos vigentes
        $productosFinales = $productosVigentes->merge($productosConAnexosVigentes)->unique('id');

        $productosFinales = self::appendNumeroAnexos($nombreTabla, $tipoProducto->id, collect($productosFinales->values()));

        // 4. Eliminar duplicados por ID de producto
        return response()->json($this->appendApellidos($nombreTabla, $productosFinales));
    }

    public function getProductosByTipoAndComercial($letrasIdentificacion, $comercial_id)
    {
        // Obtener el tipo de producto por las letras de identificación
        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        // Obtener todas las tablas de anexos asociados
        $anexos = DB::table('tipo_producto')
            ->where('tipo_producto_asociado', $tipoProducto->id)
            ->pluck('letras_identificacion'); // Obtener letras identificativas de las tablas de anexos

        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($letrasIdentificacion);

        // Obtener la fecha y hora actual
        $fechaActual = Carbon::now()->format('Y-m-d\TH:i:s');

        // Obtener los productos que están asociados al comercial
        $productosVigentes = DB::table($nombreTabla)
            ->where('comercial_id', $comercial_id)
            ->orderBy('updated_at', 'desc')
            ->get();

        // Ahora procesamos los productos que tienen anexos
        $productosConAnexosVigentes = collect(); // Inicializar una colección vacía

        foreach ($anexos as $letraAnexo) {
            // Convertir letra del anexo en nombre de tabla
            $nombreTablaAnexo = strtolower($letraAnexo);

            // Consultar los productos con anexos en cada tabla de anexos
            $productosAnexoVigentes = DB::table($nombreTablaAnexo)
                ->join($nombreTabla, "$nombreTablaAnexo.producto_id", '=', "$nombreTabla.id")
                ->where("$nombreTabla.comercial_id", $comercial_id)
                ->select("$nombreTabla.*") // Seleccionar solo los productos
                ->orderBy("$nombreTabla.updated_at", 'desc')
                ->get();

            // Añadir los productos con anexos vigentes a la colección
            $productosConAnexosVigentes = $productosConAnexosVigentes->merge($productosAnexoVigentes);
        }

        // Combinar productos vigentes y productos con anexos vigentes
        $productosFinales = $productosVigentes->merge($productosConAnexosVigentes)->unique('id');

        $productosFinales = self::appendNumeroAnexos($nombreTabla, $tipoProducto->id, collect($productosFinales->values()));

        // Devolver los productos como respuesta JSON
        return response()->json($this->appendApellidos($nombreTabla, $productosFinales));
    }

    public function getHistorialProductosByTipoAndSociedades($letrasIdentificacion, Request $request)
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
        $fechaActual = Carbon::now()->format('Y-m-d\TH:i:s');

        Log::info('Fecha actual: ' . $fechaActual);

        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        // Realizar consulta dinámica usando el nombre de la tabla
        $productos = DB::table($nombreTabla)
            ->when(count($sociedades) > 0 && !in_array(env('SOCIEDAD_ADMIN_ID', 1), $sociedades), function ($query) use ($sociedades) {
                $query->whereIn('sociedad_id', $sociedades);
            })
            ->where('fecha_de_fin', '<', $fechaActual) // Filtrar productos con fecha_de_fin mayor que la fecha actual
            ->orderBy('updated_at', 'desc') // Ordenar por fecha de actualización de forma descendente
            ->get();

        $productos = self::appendNumeroAnexos($nombreTabla, $tipoProducto->id, $productos);

        return response()->json($this->appendApellidos($nombreTabla, $productos));
    }


    public function getHistorialProductosByTipoAndComercial($letrasIdentificacion, $comercial_id)
    {

        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($letrasIdentificacion);

        $fechaActual = Carbon::now()->format('Y-m-d\TH:i:s');

        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        // Realizar consulta dinámica usando el nombre de la tabla
        $productos = DB::table($nombreTabla)
            ->where('comercial_id', $comercial_id)
            ->where('fecha_de_fin', '<', $fechaActual)
            ->orderBy('updated_at', 'desc') // Ordenar por fecha de actualización de forma descendente
            ->get();

        $productos = self::appendNumeroAnexos($nombreTabla, $tipoProducto->id, $productos);

        return response()->json($this->appendApellidos($nombreTabla, $productos));
    }

    /**
     * Append apellido_1 and apellido_2 to each row if those columns exist in the table.
     * This ensures the frontend can always build the full name without needing them as visible campos.
     */
    private function appendApellidos(string $nombreTabla, $productos)
    {
        $hasApe1 = Schema::hasColumn($nombreTabla, 'apellido_1');
        $hasApe2 = Schema::hasColumn($nombreTabla, 'apellido_2');

        if (!$hasApe1 && !$hasApe2) {
            return $productos;
        }

        // If the collection was returned from ->get() the items are stdClass objects.
        return $productos->map(function ($row) use ($hasApe1, $hasApe2) {
            $row = (array) $row;
            if ($hasApe1 && !array_key_exists('apellido_1', $row)) {
                $row['apellido_1'] = null;
            }
            if ($hasApe2 && !array_key_exists('apellido_2', $row)) {
                $row['apellido_2'] = null;
            }
            return (object) $row;
        });
    }

    public static function appendNumeroAnexos(string $nombreTabla, $tipoProductoId, $productos)
    {
        if ($productos->isEmpty()) {
            return $productos;
        }

        $hasNumeroAnexos = Schema::hasColumn($nombreTabla, 'numero_anexos');
        if (!$hasNumeroAnexos) {
            return $productos;
        }

        $anexos = DB::table('tipo_producto')
            ->where('tipo_producto_asociado', $tipoProductoId)
            ->pluck('letras_identificacion');

        if ($anexos->isEmpty()) {
            return $productos;
        }

        $productIds = $productos->pluck('id')->toArray();
        $anexoCounts = [];

        foreach ($anexos as $letraAnexo) {
            $nombreTablaAnexo = strtolower($letraAnexo);
            if (!Schema::hasTable($nombreTablaAnexo)) continue;

            $chunks = array_chunk($productIds, 500);
            foreach ($chunks as $chunk) {
                $counts = DB::table($nombreTablaAnexo)
                    ->whereIn('producto_id', $chunk)
                    ->where('anulado', 0)
                    ->select('producto_id', DB::raw('count(*) as total'))
                    ->groupBy('producto_id')
                    ->get();

                foreach ($counts as $count) {
                    if (!isset($anexoCounts[$count->producto_id])) {
                        $anexoCounts[$count->producto_id] = 0;
                    }
                    $anexoCounts[$count->producto_id] += $count->total;
                }
            }
        }

        return $productos->map(function ($row) use ($anexoCounts) {
            $rowArray = (array) $row;
            if (isset($anexoCounts[$rowArray['id']]) && $anexoCounts[$rowArray['id']] > 0) {
                // Si hay anexos nuevos activos en las tablas hijas, este es el valor más real
                $rowArray['numero_anexos'] = $anexoCounts[$rowArray['id']];
            } else {
                // Si no hay nuevos anexos (ej: no se han migrado aún), preservamos el valor importado de la BD
                $rowArray['numero_anexos'] = $rowArray['numero_anexos'] ?? 0;
            }
            return (object) $rowArray;
        });
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
        $plantillas_paths = [
            $tipoProducto->plantilla_path_1 ?? null,
            $tipoProducto->plantilla_path_2 ?? null,
            $tipoProducto->plantilla_path_3 ?? null,
            $tipoProducto->plantilla_path_4 ?? null,
            $tipoProducto->plantilla_path_5 ?? null,
            $tipoProducto->plantilla_path_6 ?? null,
            $tipoProducto->plantilla_path_7 ?? null,
            $tipoProducto->plantilla_path_8 ?? null
        ];

        // Si el tipoProducto tiene padre, coger el tipoProducto padre para meter los datos en la tabla correspondiente
        if ($tipoProducto->padre_id != null) {
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

        Log::info($datos);

        // Control por si se hace por pagina web para que el comercial que haya traido a un socio siga cobrando las comisiones pertinentes.
        if ($datos['mediante_pagina_web'] == true) {
            $datos['mediante_pagina_web'] = 1;
            $ultimoProducto = Socio::getUltimoProducto($datos['socio_id']);
            Log::info('Letras identificacion ' . $ultimoProducto->letras_identificacion);
            Log::info('ID: ' . $ultimoProducto->id_producto);
            $comercial_id = Comercial::getComercialByProducto($ultimoProducto->letras_identificacion, $ultimoProducto->id_producto);

            if ($comercial_id) {
                Log::info('Comercial ID: ' . $comercial_id);
            } else {
                Log::info('No se encontró un comercial_id para el producto con ID: ' . $ultimoProducto->id_producto);
            }
            $datos['comercial_id'] = $comercial_id;
        }

        //Añadir a los datos la plantilla_path que tenga el seguro en ese momento:
        $datos['plantilla_path_1'] = $plantillas_paths[0];
        $datos['plantilla_path_2'] = $plantillas_paths[1];
        $datos['plantilla_path_3'] = $plantillas_paths[2];
        $datos['plantilla_path_4'] = $plantillas_paths[3];
        $datos['plantilla_path_5'] = $plantillas_paths[4];
        $datos['plantilla_path_6'] = $plantillas_paths[5];
        $datos['plantilla_path_7'] = $plantillas_paths[6];
        $datos['plantilla_path_8'] = $plantillas_paths[7];

        $datos['logo_sociedad_path'] = DB::table('sociedad')->where('id', $datos['sociedad_id'])->value('logo');

        // Formatear los campos datetime al formato deseado
        foreach ($camposRelacionados as $campo) {
            $nombreCampo = strtolower(str_replace(' ', '_', $campo->nombre));
            if ($campo->tipo_dato == 'date' && isset($datos[$nombreCampo])) {
                $datos[$nombreCampo] = Carbon::createFromFormat('Y-m-d', $datos[$nombreCampo])->format('Y-m-d\TH:i:s');
            }
        }

        // Obtener el último código de producto generado
        $tableDatePrefix = Carbon::now()->format('mY');

        if (!isset($datos['referencia']) || !$datos['referencia']) {
            $referenciaService = new ReferenceService();
            $datos['referencia'] = $referenciaService->generarReferencia($letrasIdentificacion);
        }

        // Construir el nuevo código de producto
        $newCodigoProducto = $tableDatePrefix . $datos['referencia'];


        // Añadir el código de producto al array de datos
        $datos['codigo_producto'] = $newCodigoProducto;

        // Añadir created_at y updated_at al array de datos
        $datos['created_at'] = Carbon::now()->format('Y-m-d\TH:i:s');
        $datos['updated_at'] = Carbon::now()->format('Y-m-d\TH:i:s');
        // $datos['hora_inicio'] = Carbon::now()->format('H:i:s');
        $horaActual = Carbon::now()->format('H:i:s');
        $fechaHoy = Carbon::today();

        // Compruebo si el seguro se ha comprado para hoy o para otro día más adelante.
        // Creo los objetos con las fechas correspondientes dependiendo de si la fecha de inicio es mayor que hoy o no.
        if (Carbon::parse($datos['fecha_de_inicio'])->toDateString() > $fechaHoy->toDateString()) {
            $fechaInicio = Carbon::parse($datos['fecha_de_inicio'])->setTime(0, 0, 0);
            $fechaFin = Carbon::parse($datos['fecha_de_fin'])->setTime(23, 59, 0);
        } else {
            $fechaInicio = Carbon::parse($datos['fecha_de_inicio'])->setTimeFromTimeString($horaActual);
            $fechaFin = Carbon::parse($datos['fecha_de_fin'])->setTimeFromTimeString($horaActual);
        }

        // Asigno los datos de fecha y hora (strings a partir de object) al array de datos que se insertará en la base de datos 
        $datos['fecha_de_inicio'] = $fechaInicio->format('Y-m-d\TH:i:s');
        $datos['fecha_de_fin'] = $fechaFin->format('Y-m-d\TH:i:s');

        $datos['hora_de_inicio'] = $fechaInicio->format('H:i:s');
        $datos['hora_de_fin'] = $fechaFin->format('H:i:s');
        $datos['hora_de_emisión'] = $horaActual;

        unset($datos['nombre_producto'], $datos['letras_identificacion'], $datos['categoria'], $datos['referencia']);
        $registro = DB::transaction(function () use ($nombreTabla, $datos) {
            $id = DB::table($nombreTabla)->insertGetId($datos);
            // Si la PK no se llama "id", ajusta el campo:
            return DB::table($nombreTabla)->where('id', $id)->first();
        });


        if (isset($datos['socio_id']) && isset($datos['dni']) && Dni::equals($datos['dni'], Socio::findOrFail($datos['socio_id'])->dni)) {
            // Conectar el socio con el producto
            SocioProducto::connectSocioAndProducto($datos['socio_id'], $registro->id, $nombreTabla);
        }

        return response()->json($registro, 201);
    }


    public function setBlobNameForProductId($letras_identificacion, $id, Request $request)
    {
        $data = $request->validate([
            'blob_name' => ['required', 'string', 'max:300', 'regex:/^[A-Za-z0-9._\-\/]+\.pdf$/'],
            'is_anexo' => ['required', 'boolean']
        ], [
            'regex' => 'El nombre del blob solo puede contener letras, números, punto, guion, guion bajo y barras (/).',
        ]);

        $nombreTabla = strtolower($letras_identificacion);

        if ($data['is_anexo']) {
            DB::table($nombreTabla)
                ->where('producto_id', $id)
                ->update(['blob_name' => $data['blob_name']]);
        } else {
            DB::table($nombreTabla)
                ->where('id', $id)
                ->update(['blob_name' => $data['blob_name']]);
        }

        return response()->json(['message' => 'Nombre del blob actualizado correctamente']);
    }



    public function editarProducto($letrasIdentificacion, Request $request)
    {

        // Obtener el id del tipo_producto basado en las letras_identificacion
        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();


        // Si el tipoProducto tiene padre, coger el tipoProducto padre para meter los datos en la tabla correspondiente
        if ($tipoProducto->padre_id != null) {
            $tipoProducto = DB::table('tipo_producto')
                ->where('id', $tipoProducto->padre_id)
                ->first();
        }

        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($tipoProducto->letras_identificacion);

        // Coger el resto de datos de la request excepto el id:
        $datos = $request->input('productoEditado');

        $id = $datos['id'];

        // Quitar el id y otros datos que no se deben de guardar en BDD:
        unset($datos['id'], $datos['nombre_producto'], $datos['letras_identificacion'], $datos['categoria'], $datos['referencia']);

        // Formato ISO-8601 con la T intermedia
        $isoFormat = 'Y-m-d\TH:i:s';

        // Normalizar fechas
        foreach (['fecha_de_inicio', 'fecha_de_fin', 'updated_at'] as $campo) {
            if (!empty($datos[$campo])) {
                $datos[$campo] = Carbon::parse($datos[$campo])->format($isoFormat);
            }
        }

        // Actualizar los datos en la tabla correspondiente
        DB::table($nombreTabla)
            ->where('id', $id)
            ->update($datos);

        return response()->json([
            'message' => 'Producto actualizado con éxito',
            'id' => $id
        ], 200);
    }

    public function eliminarProducto($letrasIdentificacion, Request $request)
    {
        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($letrasIdentificacion);

        $id = $request->input('id');

        // Eliminar el producto de la tabla correspondiente
        DB::table($nombreTabla)->where('id', $id)->delete();

        return response()->json(['message' => 'Producto eliminado con éxito'], 200);
    }

    public function getDuraciones($nombreTabla)
    {

        // Coger todos los datos de la tabla $nombreTabla:
        $datos = DB::table($nombreTabla)->get();

        return response()->json($datos);
    }

    public function getPlantillaBase64(string $path)
    {
        $file = Storage::disk('public')->get($path);
        $base64 = base64_encode($file);
        return response()->json(['base64' => $base64]);
    }

    public function show($letrasIdentificacion, $id)
    {
        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        if ($tipoProducto && $tipoProducto->padre_id != null) {
            $tipoProducto = DB::table('tipo_producto')
                ->where('id', $tipoProducto->padre_id)
                ->first();
            $letrasIdentificacion = $tipoProducto->letras_identificacion;
        }

        $nombreTabla = strtolower($letrasIdentificacion);

        if (!Schema::hasTable($nombreTabla)) {
            return response()->json(['error' => 'Product table not found'], 404);
        }

        $producto = DB::table($nombreTabla)->where('id', $id)->first();

        if (!$producto) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        return response()->json($producto, 200);
    }

    public function regenerarDatosInstancia($letrasIdentificacion, $id)
    {
        // Buscar el tipo_producto base original
        $tipoProductoReal = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        if (!$tipoProductoReal) {
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }

        // Si el tipoProducto tiene padre (es un subproducto), la tabla a modificar es la del padre
        $tipoProductoTabla = $tipoProductoReal;
        if ($tipoProductoReal->padre_id != null) {
            $tipoProductoTabla = DB::table('tipo_producto')
                ->where('id', $tipoProductoReal->padre_id)
                ->first();
        }

        $nombreTabla = strtolower($tipoProductoTabla->letras_identificacion);

        if (!Schema::hasTable($nombreTabla)) {
            return response()->json(['error' => 'Product table not found'], 404);
        }

        // Buscar la instancia
        $instancia = DB::table($nombreTabla)
            ->where('id', $id)
            ->first();

        if (!$instancia) {
            return response()->json(['error' => 'Instancia de producto no encontrada'], 404);
        }

        $updateData = [];

        // 1. Rutas de plantillas (Desde el tipo_producto_real, ya que los subproductos pueden tener sus propias plantillas)
        for ($i = 1; $i <= 8; $i++) {
            $colName = 'plantilla_path_' . $i;
            if (Schema::hasColumn($nombreTabla, $colName)) {
                $updateData[$colName] = $tipoProductoReal->$colName ?? null;
            }
        }

        // 2. Logo Sociedad
        if (Schema::hasColumn($nombreTabla, 'logo_sociedad_path')) {
            $updateData['logo_sociedad_path'] = DB::table('sociedad')->where('id', $instancia->sociedad_id)->value('logo');
        }

        // 3. Precios y Tarifas (Tabla hija e instancia base)
        // Usamos la tarifa del ProductoReal
        $tarifaIdAUsar = $tipoProductoReal->id;
        if (isset($instancia->subproducto) && $instancia->subproducto) {
            $tarifaIdAUsar = $instancia->subproducto;
        }

        $tarifa = DB::table('tarifas_producto')
                    ->where('id_sociedad', $instancia->sociedad_id)
                    ->where('tipo_producto_id', $tarifaIdAUsar)
                    ->first();

        if ($tarifa) {
            $colsToUpdate = ['precio_base', 'extra_1', 'extra_2', 'extra_3', 'precio_total'];
            foreach ($colsToUpdate as $col) {
                if (Schema::hasColumn($nombreTabla, $col)) {
                    $updateData[$col] = $tarifa->$col;
                }
            }
        }

        // 4. Metadatos adicionales (nombre_producto)
        if (Schema::hasColumn($nombreTabla, 'nombre_producto')) {
            $updateData['nombre_producto'] = $tipoProductoReal->nombre;
        }

        DB::table($nombreTabla)->where('id', $id)->update($updateData);

        // 5. Regenerar anexos también (Los anexos se enlazan al PADRE en Base de Datos porque es el que da la Estructura en algunos casos, o al REAL?)
        // En Kyrema los anexos suelen estar asociados al padre o al hijo. En base a $tipoProductoTabla o $tipoProductoReal?
        // Revisamos tipo_producto_asociado = $tipoProductoTabla->id (El Padre, porque la tabla de anexos guarda asociaciion con la estructura general)
        $anexosNames = DB::table('tipo_producto')
            ->where('tipo_producto_asociado', $tipoProductoTabla->id)
            ->pluck('letras_identificacion');

        $sumaAnexos = 0;

        foreach ($anexosNames as $letraAnexo) {
            $tablaAnexo = strtolower($letraAnexo);
            if (Schema::hasTable($tablaAnexo)) {
                $anexosInstancia = DB::table($tablaAnexo)
                    ->where('producto_id', $id)
                    ->where('anulado', 0)
                    ->get();
                    
                foreach ($anexosInstancia as $anInst) {
                    $updateAnexo = [];
                    $tipoAnexo = DB::table('tipo_producto')->where('letras_identificacion', $letraAnexo)->first();
                    
                    if ($tipoAnexo) {
                        for ($i = 1; $i <= 8; $i++) {
                            $colName = 'plantilla_path_' . $i;
                            if (Schema::hasColumn($tablaAnexo, $colName)) {
                                $updateAnexo[$colName] = $tipoAnexo->$colName ?? null;
                            }
                        }

                        $tarifaAnexo = DB::table('tarifas_producto')
                            ->where('id_sociedad', env('SOCIEDAD_ADMIN_ID', 1)) // Las tarifas de anexo siempre apuntan a Admin
                            ->where('tipo_producto_id', $tipoAnexo->id)
                            ->first();

                        if ($tarifaAnexo) {
                            $colsToUpdate = ['precio_base', 'extra_1', 'extra_2', 'extra_3', 'precio_total'];
                            foreach ($colsToUpdate as $col) {
                                if (Schema::hasColumn($tablaAnexo, $col)) {
                                    $updateAnexo[$col] = $tarifaAnexo->$col;
                                }
                            }
                            $sumaAnexos += $tarifaAnexo->precio_total;
                        }

                        if (!empty($updateAnexo)) {
                            DB::table($tablaAnexo)->where('id', $anInst->id)->update($updateAnexo);
                        }
                    }
                }
            }
        }

        // 6. Actualizar precio_final global
        if (Schema::hasColumn($nombreTabla, 'precio_final')) {
            $baseTarifa = $tarifa ? ($tarifa->precio_total ?? 0) : ($instancia->precio_total ?? 0);
            DB::table($nombreTabla)->where('id', $id)->update(['precio_final' => $baseTarifa + $sumaAnexos]);
        }

        $instanciaActualizada = DB::table($nombreTabla)->where('id', $id)->first();
        return response()->json([
            'message' => 'Datos regenerados con éxito',
            'producto' => $instanciaActualizada
        ], 200);
    }
}
