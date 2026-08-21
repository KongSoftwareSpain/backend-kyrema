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
use Illuminate\Support\Facades\Cache;
use App\Models\Payments\Pago;
use App\Models\Payments\GiroBancario;

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
                    // Booleano de si ha sido archivado por la limpieza de caducados
                    $table->boolean('caducado')->default(false);
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

    public function borrarPlantilla($id_tipo_producto, $page)
    {
        $tipoProducto = DB::table('tipo_producto')
            ->where('id', $id_tipo_producto)
            ->first();

        if (!$tipoProducto) {
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }

        $plantilla_path_name = 'plantilla_path_' . $page;
        $rutaArchivo = $tipoProducto->$plantilla_path_name;

        if ($rutaArchivo && Storage::disk('public')->exists($rutaArchivo)) {
            Storage::disk('public')->delete($rutaArchivo);
        }

        // Actualizar la base de datos
        DB::table('tipo_producto')
            ->where('id', $id_tipo_producto)
            ->update([$plantilla_path_name => null]);

        return response()->json(['message' => 'Plantilla ' . $page . ' borrada correctamente'], 200);
    }

    /**
     * Productos cuya fecha_de_fin cae dentro de los próximos $dias (query param,
     * por defecto 30), no anulados, filtrados por las sociedades permitidas.
     * Alimenta la pantalla de "Próximos a caducar" para renovación en paquete.
     */
    public function getProductosProximosACaducar($letrasIdentificacion, Request $request)
    {
        $dias = max(1, (int) $request->query('dias', 30));

        $sociedades = $request->query('sociedades');
        $sociedades = $sociedades ? explode(',', $sociedades) : [];

        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        if (!$tipoProducto) {
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }

        // Si es subproducto, los datos viven en la tabla del padre
        if ($tipoProducto->padre_id != null) {
            $tipoProducto = DB::table('tipo_producto')->where('id', $tipoProducto->padre_id)->first();
        }

        $nombreTabla = strtolower($tipoProducto->letras_identificacion);
        $isAdmin = count($sociedades) === 0 || in_array(env('SOCIEDAD_ADMIN_ID', 1), $sociedades);

        $columnasBase = Cache::remember("schema_cols_{$nombreTabla}", 3600, fn() => Schema::getColumnListing($nombreTabla));
        $tieneApellidos = in_array('apellido_1', $columnasBase);
        $tieneSubproducto = in_array('subproducto_codigo', $columnasBase);

        $select = [
            "$nombreTabla.id", "$nombreTabla.codigo_producto", "$nombreTabla.dni",
            "$nombreTabla.nombre_socio", "$nombreTabla.fecha_de_fin", "$nombreTabla.fecha_de_inicio",
            "$nombreTabla.tipo_de_pago", "$nombreTabla.sociedad_id", "$nombreTabla.comercial_id",
            'sociedad.nombre as sociedad', 'comercial.nombre as comercial',
        ];
        if ($tieneApellidos) {
            $select[] = "$nombreTabla.apellido_1";
            $select[] = "$nombreTabla.apellido_2";
        }
        if ($tieneSubproducto) {
            $select[] = "$nombreTabla.subproducto_codigo";
        }
        if (in_array('renovado', $columnasBase)) {
            $select[] = "$nombreTabla.renovado";
        }

        $hoy = Carbon::today();
        $limite = Carbon::today()->addDays($dias);

        $query = DB::table($nombreTabla)
            ->leftJoin('sociedad', "$nombreTabla.sociedad_id", '=', 'sociedad.id')
            ->leftJoin('comercial', "$nombreTabla.comercial_id", '=', 'comercial.id')
            ->select($select)
            ->where(function ($q) use ($nombreTabla) {
                $q->where("$nombreTabla.anulado", 0)->orWhereNull("$nombreTabla.anulado");
            })
            ->whereBetween("$nombreTabla.fecha_de_fin", [$hoy->format('Y-m-d\TH:i:s'), $limite->format('Y-m-d\TH:i:s')]);

        if (!$isAdmin) {
            $query->whereIn("$nombreTabla.sociedad_id", $sociedades);
        }

        $query->orderBy("$nombreTabla.fecha_de_fin", 'asc');

        $productos = $query->get()->map(function ($producto) use ($hoy) {
            $producto->dias_restantes = (int) $hoy->diffInDays(Carbon::parse($producto->fecha_de_fin), false);
            return $producto;
        });

        return response()->json($productos);
    }

    public function getProductosByTipoAndSociedades($letrasIdentificacion, Request $request)
    {
        $t0 = microtime(true);
        Log::info("[PERF] getProductosByTipoAndSociedades START — tabla: {$letrasIdentificacion}");

        $sociedades = $request->query('sociedades');
        if ($sociedades) {
            $sociedades = explode(',', $sociedades);
        } else {
            $sociedades = [];
        }

        $nombreTabla = strtolower($letrasIdentificacion);

        // Fetch tipo_producto (Direct query, <2ms)
        $t1 = microtime(true);
        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();
        Log::info("[PERF] tipo_producto lookup: " . round((microtime(true) - $t1) * 1000, 2) . "ms");

        if (!$tipoProducto) {
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }

        $isAdmin = count($sociedades) === 0 || in_array(env('SOCIEDAD_ADMIN_ID', 1), $sociedades);

        // Fetch listado de anexos (Direct query, <2ms)
        $t2 = microtime(true);
        $anexos = DB::table('tipo_producto')
            ->where('tipo_producto_asociado', $tipoProducto->id)
            ->pluck('letras_identificacion');
        Log::info("[PERF] anexos lookup: " . round((microtime(true) - $t2) * 1000, 2) . "ms — count: " . $anexos->count());

        // --- LAZY FETCHING: Seleccionar solo columnas necesarias para la tabla ---
        $camposVisibles = DB::table('campos')
            ->where('tipo_producto_id', $tipoProducto->id)
            ->where('visible', '1')
            ->get(['nombre', 'nombre_codigo']);

        $columnasSelect = ['id', 'sociedad_id', 'comercial_id', 'anulado', 'updated_at', 'created_at', 'numero_anexos', 'producto_id'];

        foreach ($camposVisibles as $c) {
            $colName = $c->nombre_codigo ? $c->nombre_codigo : str_replace(' ', '_', strtolower($c->nombre));
            $columnasSelect[] = $colName;
        }

        // Añadir obligatorias para ag-grid y formateos (aunque no sean "campos" visibles)
        $columnasAdicionales = ['codigo_producto', 'subproducto_codigo', 'dni', 'nombre_socio', 'apellido_1', 'apellido_2', 'fecha_de_inicio', 'fecha_de_emision', 'tipo_de_pago', 'puestos', 'numero_de_puestos', 'renovado'];
        $columnasSelect = array_unique(array_merge($columnasSelect, $columnasAdicionales));

        // Filtrar contra Schema para evitar errores SQL de columnas que no existan en la tabla actual
        $validColumns = Cache::remember("schema_cols_{$nombreTabla}", 3600, fn() => Schema::getColumnListing($nombreTabla));
        $finalSelect = array_intersect($columnasSelect, $validColumns);
        $finalSelect = array_filter($finalSelect, fn($col) => $col !== 'sociedad');

        // Query principal
        $t3 = microtime(true);
        $selectColumns = array_map(fn($col) => "$nombreTabla.$col", $finalSelect);
        $selectColumns[] = 'sociedad.nombre as sociedad';

        $query = DB::table($nombreTabla)
            ->leftJoin('sociedad', "$nombreTabla.sociedad_id", '=', 'sociedad.id')
            ->select($selectColumns);

        if (!$isAdmin) {
            $query->whereIn("$nombreTabla.sociedad_id", $sociedades);
        }

        if ($anexos->isNotEmpty()) {
            $query->where(function ($q) use ($anexos, $nombreTabla) {
                $q->whereRaw('1=1');
                foreach ($anexos as $letraAnexo) {
                    $nombreTablaAnexo = strtolower($letraAnexo);
                    $q->orWhereExists(function ($sub) use ($nombreTablaAnexo, $nombreTabla) {
                        $sub->select(DB::raw(1))
                            ->from($nombreTablaAnexo)
                            ->whereColumn("$nombreTablaAnexo.producto_id", "$nombreTabla.id");
                    });
                }
            });
        }

        $query->orderBy("$nombreTabla.updated_at", 'desc');

        $productosFinales = collect($query->get());
        $productosFinales = self::appendNumeroAnexos($nombreTabla, $tipoProducto->id, $productosFinales);
        $productosFinales = $this->appendApellidos($nombreTabla, $productosFinales);
        $productosFinales = $this->normalizePuestos($productosFinales);

        Log::info("[PERF] main query + fetch: " . round((microtime(true) - $t3) * 1000, 2) . "ms — rows returned: " . $productosFinales->count());

        $t4 = microtime(true);
        $response = response()->json($productosFinales);
        Log::info("[PERF] json encode: " . round((microtime(true) - $t4) * 1000, 2) . "ms");
        Log::info("[PERF] TOTAL: " . round((microtime(true) - $t0) * 1000, 2) . "ms");

        return $response;
    }

    public function getProductosByTipoAndComercial($letrasIdentificacion, $comercial_id, Request $request)
    {
        // Fetch tipo_producto (Direct query)
        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        if (!$tipoProducto) {
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }

        // Fetch listado de anexos (Direct query)
        $anexos = DB::table('tipo_producto')
            ->where('tipo_producto_asociado', $tipoProducto->id)
            ->pluck('letras_identificacion');

        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($letrasIdentificacion);

        // --- LAZY FETCHING: Seleccionar solo columnas necesarias para la tabla ---
        $camposVisibles = DB::table('campos')
            ->where('tipo_producto_id', $tipoProducto->id)
            ->where('visible', '1')
            ->get(['nombre', 'nombre_codigo']);

        $columnasSelect = ['id', 'sociedad_id', 'comercial_id', 'anulado', 'updated_at', 'created_at', 'numero_anexos', 'producto_id'];

        foreach ($camposVisibles as $c) {
            $colName = $c->nombre_codigo ? $c->nombre_codigo : str_replace(' ', '_', strtolower($c->nombre));
            $columnasSelect[] = $colName;
        }

        $columnasAdicionales = ['codigo_producto', 'subproducto_codigo', 'dni', 'nombre_socio', 'apellido_1', 'apellido_2', 'fecha_de_inicio', 'fecha_de_emision', 'tipo_de_pago', 'puestos', 'numero_de_puestos', 'renovado'];
        $columnasSelect = array_unique(array_merge($columnasSelect, $columnasAdicionales));

        $validColumns = Cache::remember("schema_cols_{$nombreTabla}", 3600, fn() => Schema::getColumnListing($nombreTabla));
        $finalSelect = array_intersect($columnasSelect, $validColumns);
        $finalSelect = array_filter($finalSelect, fn($col) => $col !== 'sociedad');

        // OPTIMIZACIÓN: 1 sola query con OR EXISTS en lugar de N queries + merge PHP
        $selectColumns = array_map(fn($col) => "$nombreTabla.$col", $finalSelect);
        $selectColumns[] = 'sociedad.nombre as sociedad';

        $query = DB::table($nombreTabla)
            ->leftJoin('sociedad', "$nombreTabla.sociedad_id", '=', 'sociedad.id')
            ->select($selectColumns)
            ->where("$nombreTabla.comercial_id", $comercial_id);

        if ($anexos->isNotEmpty()) {
            $query->where(function ($q) use ($anexos, $nombreTabla, $comercial_id) {
                $q->whereRaw('1=1');
                foreach ($anexos as $letraAnexo) {
                    $nombreTablaAnexo = strtolower($letraAnexo);
                    $q->orWhereExists(function ($sub) use ($nombreTablaAnexo, $nombreTabla) {
                        $sub->select(DB::raw(1))
                            ->from($nombreTablaAnexo)
                            ->whereColumn("$nombreTablaAnexo.producto_id", "$nombreTabla.id");
                    });
                }
            });
        }

        $query->orderBy("$nombreTabla.updated_at", 'desc');

        $productosFinales = collect($query->get());

        $productosFinales = self::appendNumeroAnexos($nombreTabla, $tipoProducto->id, $productosFinales);
        $productosFinales = $this->appendApellidos($nombreTabla, $productosFinales);
        $productosFinales = $this->normalizePuestos($productosFinales);

        return response()->json($productosFinales);
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

        $columns = Schema::getColumnListing($nombreTabla);
        $columns = array_filter($columns, fn($col) => $col !== 'sociedad');
        $selectColumns = array_map(fn($col) => "$nombreTabla.$col", $columns);
        $selectColumns[] = 'sociedad.nombre as sociedad';

        // Realizar consulta dinámica usando el nombre de la tabla
        $productos = DB::table($nombreTabla)
            ->leftJoin('sociedad', "$nombreTabla.sociedad_id", '=', 'sociedad.id')
            ->select($selectColumns)
            ->when(count($sociedades) > 0 && !in_array(env('SOCIEDAD_ADMIN_ID', 1), $sociedades), function ($query) use ($sociedades, $nombreTabla) {
                $query->whereIn("$nombreTabla.sociedad_id", $sociedades);
            })
            ->where("$nombreTabla.fecha_de_fin", '<', $fechaActual) // Filtrar productos con fecha_de_fin mayor que la fecha actual
            ->orderBy("$nombreTabla.updated_at", 'desc') // Ordenar por fecha de actualización de forma descendente
            ->get();

        $productos = self::appendNumeroAnexos($nombreTabla, $tipoProducto->id, $productos);
        $productos = $this->appendApellidos($nombreTabla, $productos);
        $productos = $this->normalizePuestos($productos);

        return response()->json($productos);
    }


    public function getHistorialProductosByTipoAndComercial($letrasIdentificacion, $comercial_id)
    {

        // Convertir letras de identificación a nombre de tabla
        $nombreTabla = strtolower($letrasIdentificacion);

        $fechaActual = Carbon::now()->format('Y-m-d\TH:i:s');

        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        $columns = Schema::getColumnListing($nombreTabla);
        $columns = array_filter($columns, fn($col) => $col !== 'sociedad');
        $selectColumns = array_map(fn($col) => "$nombreTabla.$col", $columns);
        $selectColumns[] = 'sociedad.nombre as sociedad';

        // Realizar consulta dinámica usando el nombre de la tabla
        $productos = DB::table($nombreTabla)
            ->leftJoin('sociedad', "$nombreTabla.sociedad_id", '=', 'sociedad.id')
            ->select($selectColumns)
            ->where("$nombreTabla.comercial_id", $comercial_id)
            ->where("$nombreTabla.fecha_de_fin", '<', $fechaActual)
            ->orderBy("$nombreTabla.updated_at", 'desc') // Ordenar por fecha de actualización de forma descendente
            ->get();

        $productos = self::appendNumeroAnexos($nombreTabla, $tipoProducto->id, $productos);
        $productos = $this->appendApellidos($nombreTabla, $productos);
        $productos = $this->normalizePuestos($productos);

        return response()->json($productos);
    }

    /**
     * Append apellido_1 and apellido_2 to each row if those columns exist in the table.
     * This ensures the frontend can always build the full name without needing them as visible campos.
     */
    private function appendApellidos(string $nombreTabla, $productos)
    {
        // Direct call (only runs twice per request regardless of rows)
        $hasApe1 = Schema::hasColumn($nombreTabla, 'apellido_1');
        $hasApe2 = Schema::hasColumn($nombreTabla, 'apellido_2');

        if (!$hasApe1 && !$hasApe2) {
            return $productos;
        }

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

    /**
     * Normaliza la columna de puestos para cacerías de Portugal (donde se almacena en numero_de_puestos).
     */
    private function normalizePuestos($productos)
    {
        return $productos->map(function ($row) {
            $rowArray = (array) $row;
            if (array_key_exists('numero_de_puestos', $rowArray) && (!isset($rowArray['puestos']) || $rowArray['puestos'] === null || $rowArray['puestos'] === '')) {
                $rowArray['puestos'] = $rowArray['numero_de_puestos'];
            }
            if (array_key_exists('puestos', $rowArray) && ($rowArray['puestos'] === null || $rowArray['puestos'] === '')) {
                $rowArray['puestos'] = 'Sin puestos';
            }
            return (object) $rowArray;
        });
    }

    public static function appendNumeroAnexos(string $nombreTabla, $tipoProductoId, $productos)
    {
        if ($productos->isEmpty()) {
            return $productos;
        }

        // Direct call
        $hasNumeroAnexos = Schema::hasColumn($nombreTabla, 'numero_anexos');
        if (!$hasNumeroAnexos) {
            return $productos;
        }

        // Direct call
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
            // Cache Schema::hasTable — TTL 1h
            $tableExists = Cache::remember("schema_table_{$nombreTablaAnexo}", 3600, fn() => Schema::hasTable($nombreTablaAnexo));
            if (!$tableExists) continue;

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

        // Filtrar datos para que solo contengan columnas existentes en la tabla
        $columnasValidas = Schema::getColumnListing($nombreTabla);
        $datos = array_intersect_key($datos, array_flip($columnasValidas));

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


    /**
     * Renovación en paquete: clona cada producto seleccionado con fechas nuevas
     * (inicio = fin original + 1 día, misma duración), clona sus anexos y, si el
     * pago era giro bancario, genera el pago recurrente. Los seguros con pago
     * por tarjeta NO se pueden renovar por este método.
     */
    public function renovarProductosEnPaquete($letrasIdentificacion, Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'numeric',
        ]);

        $tipoProducto = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        if (!$tipoProducto) {
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }
        if ($tipoProducto->padre_id != null) {
            $tipoProducto = DB::table('tipo_producto')->where('id', $tipoProducto->padre_id)->first();
        }

        $nombreTabla = strtolower($tipoProducto->letras_identificacion);
        $columnasValidas = Schema::getColumnListing($nombreTabla);

        // Tablas de anexos asociadas al tipo de producto
        $tiposAnexos = DB::table('tipo_producto')
            ->where('tipo_producto_asociado', $tipoProducto->id)
            ->get()
            ->filter(fn ($ta) => Schema::hasTable(strtolower($ta->letras_identificacion)));

        // Letras de los subproductos: la secuencia de referencia se genera con las
        // letras del subproducto de la fila (como en el alta), no con las del padre
        $letrasSubproductos = DB::table('tipo_producto')
            ->where('padre_id', $tipoProducto->id)
            ->pluck('letras_identificacion', 'id');

        $renovados = [];
        $omitidos = [];
        $errores = [];

        // SQL Server con locale español rechaza 'Y-m-d H:i:s' al insertar: todo valor
        // con pinta de fecha se reescribe en ISO con 'T' (formato inequívoco)
        $normalizarFechas = function (array $datos): array {
            foreach ($datos as $clave => $valor) {
                if (is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}|$)/', $valor)) {
                    try {
                        $datos[$clave] = Carbon::parse($valor)->format('Y-m-d\TH:i:s');
                    } catch (\Throwable $e) {
                        // se deja tal cual si no parsea
                    }
                }
            }
            return $datos;
        };

        foreach ($request->input('ids') as $id) {
            $original = DB::table($nombreTabla)->where('id', $id)->first();

            if (!$original) {
                $errores[] = ['id' => $id, 'motivo' => 'Producto no encontrado'];
                continue;
            }
            if (strcasecmp(trim($original->tipo_de_pago ?? ''), 'Tarjeta') === 0) {
                $omitidos[] = ['id' => $id, 'codigo_producto' => $original->codigo_producto, 'motivo' => 'Pago con tarjeta: no admite renovación en paquete'];
                continue;
            }
            if (!empty($original->anulado) && $original->anulado != '0') {
                $omitidos[] = ['id' => $id, 'codigo_producto' => $original->codigo_producto, 'motivo' => 'Producto anulado'];
                continue;
            }
            if (empty($original->fecha_de_fin) || empty($original->fecha_de_inicio)) {
                $errores[] = ['id' => $id, 'codigo_producto' => $original->codigo_producto, 'motivo' => 'Sin fechas de inicio/fin'];
                continue;
            }

            try {
                DB::beginTransaction();

                // Fechas nuevas: inicio = fin original + 1 día, misma duración que el original
                $inicioOriginal = Carbon::parse($original->fecha_de_inicio)->startOfDay();
                $finOriginal = Carbon::parse($original->fecha_de_fin)->startOfDay();
                $dias = $inicioOriginal->diffInDays($finOriginal);

                $horaActual = Carbon::now()->format('H:i:s');
                $inicioNuevo = $finOriginal->copy()->addDay();
                $finNuevo = $inicioNuevo->copy()->addDays($dias);

                if ($inicioNuevo->toDateString() > Carbon::today()->toDateString()) {
                    $inicioNuevo->setTime(0, 0, 0);
                    $finNuevo->setTime(23, 59, 0);
                } else {
                    $inicioNuevo->setTimeFromTimeString($horaActual);
                    $finNuevo->setTimeFromTimeString($horaActual);
                }

                // Nueva referencia y código de producto (mismo formato que crearProducto)
                $letrasReferencia = $tipoProducto->letras_identificacion;
                if (!empty($original->subproducto) && isset($letrasSubproductos[$original->subproducto])) {
                    $letrasReferencia = $letrasSubproductos[$original->subproducto];
                }
                $referenciaService = new ReferenceService();
                $referencia = $referenciaService->generarReferencia($letrasReferencia);
                $codigoNuevo = Carbon::now()->format('mY') . $referencia;

                $ahora = Carbon::now()->format('Y-m-d\TH:i:s');
                $datos = (array) $original;
                unset($datos['id'], $datos['pago_id']);
                // blob_name es NOT NULL en algunas tablas: vacío hasta que se genere el certificado
                $datos['blob_name'] = '';
                $datos['codigo_producto'] = $codigoNuevo;
                $datos['fecha_de_inicio'] = $inicioNuevo->format('Y-m-d\TH:i:s');
                $datos['fecha_de_fin'] = $finNuevo->format('Y-m-d\TH:i:s');
                $datos['hora_de_inicio'] = $inicioNuevo->format('H:i:s');
                $datos['hora_de_fin'] = $finNuevo->format('H:i:s');
                $datos['fecha_de_emisión'] = $ahora;
                $datos['hora_de_emisión'] = $horaActual;
                $datos['created_at'] = $ahora;
                $datos['updated_at'] = $ahora;
                // La póliza nueva nace sin marca de renovación: el clon arrastraría
                // la del original si este ya se hubiese renovado antes
                $datos['renovado'] = false;
                $datos['renovado_por_id'] = null;
                $datos = $normalizarFechas($datos);
                $datos = array_intersect_key($datos, array_flip($columnasValidas));

                $nuevoId = DB::table($nombreTabla)->insertGetId($datos);

                // El original queda marcado como renovado y apuntando a su hija.
                // Se comprueba la columna porque el despliegue del código puede ir
                // por delante de la migración 2026_08_21_000000.
                if (in_array('renovado', $columnasValidas)) {
                    DB::table($nombreTabla)->where('id', $original->id)->update([
                        'renovado' => true,
                        'renovado_por_id' => $nuevoId,
                    ]);
                }

                if (!empty($original->socio_id)) {
                    $socio = Socio::find($original->socio_id);
                    if ($socio && Dni::equals($original->dni, $socio->dni)) {
                        SocioProducto::connectSocioAndProducto($original->socio_id, $nuevoId, $nombreTabla);
                    }
                }

                // Clonar anexos no anulados
                $anexosClonados = 0;
                foreach ($tiposAnexos as $tipoAnexo) {
                    $tablaAnexo = strtolower($tipoAnexo->letras_identificacion);
                    $anexos = DB::table($tablaAnexo)->where('producto_id', $original->id)->get();
                    foreach ($anexos as $anexo) {
                        if (!empty($anexo->anulado) && $anexo->anulado != '0') {
                            continue;
                        }
                        $datosAnexo = (array) $anexo;
                        unset($datosAnexo['id']);
                        if (array_key_exists('blob_name', $datosAnexo)) $datosAnexo['blob_name'] = '';
                        $datosAnexo['producto_id'] = $nuevoId;
                        if (array_key_exists('fecha_de_inicio', $datosAnexo)) $datosAnexo['fecha_de_inicio'] = $datos['fecha_de_inicio'];
                        if (array_key_exists('fecha_de_fin', $datosAnexo)) $datosAnexo['fecha_de_fin'] = $datos['fecha_de_fin'];
                        if (array_key_exists('fecha_de_emisión', $datosAnexo)) $datosAnexo['fecha_de_emisión'] = $ahora;
                        if (array_key_exists('hora_de_emisión', $datosAnexo)) $datosAnexo['hora_de_emisión'] = $horaActual;
                        if (array_key_exists('created_at', $datosAnexo)) $datosAnexo['created_at'] = $ahora;
                        if (array_key_exists('updated_at', $datosAnexo)) $datosAnexo['updated_at'] = $ahora;
                        $datosAnexo = $normalizarFechas($datosAnexo);
                        DB::table($tablaAnexo)->insert($datosAnexo);
                        $anexosClonados++;
                    }
                }

                // Resincronizar plantillas y precios de la nueva instancia (y sus anexos)
                // contra la configuración actual del tipo de producto y tarifas_producto:
                // el clon anterior arrastraba precio_base/extra_*/precio_total/precio_final
                // y plantilla_path_* congelados del original, que podían quedar a 0/obsoletos.
                $this->regenerarDatosInstancia($letrasIdentificacion, $nuevoId);

                // Giro bancario: pago recurrente clonado del original con la nueva referencia
                if (strcasecmp(trim($original->tipo_de_pago ?? ''), 'Giro bancario') === 0 && !empty($original->pago_id)) {
                    $giroOriginal = GiroBancario::where('pago_id', $original->pago_id)->first();
                    if ($giroOriginal) {
                        // Actualizar referencia (con y sin prefijo de fecha) y fechas de cobertura en el concepto
                        $conceptoNuevo = str_replace(
                            [
                                $original->codigo_producto,
                                substr($original->codigo_producto, 6),
                                $inicioOriginal->toDateString(),
                                $finOriginal->toDateString(),
                            ],
                            [
                                $codigoNuevo,
                                substr($codigoNuevo, 6),
                                $inicioNuevo->toDateString(),
                                $finNuevo->toDateString(),
                            ],
                            $giroOriginal->concepto ?? ''
                        );
                        $pagoNuevo = Pago::create([
                            'referencia' => $codigoNuevo,
                            'letras_identificacion' => $tipoProducto->letras_identificacion,
                            'tipo_pago' => 'giro_bancario',
                            'monto' => $giroOriginal->importe,
                            'fecha' => Carbon::now()->format('Y-m-d\TH:i:s'),
                            'estado' => 'pendiente',
                            'sociedad_id' => $original->sociedad_id ?? null,
                        ]);
                        GiroBancario::create([
                            'pago_id' => $pagoNuevo->id,
                            'referencia' => $codigoNuevo,
                            'nombre_cliente' => $giroOriginal->nombre_cliente,
                            'dni' => $giroOriginal->dni,
                            'importe' => $giroOriginal->importe,
                            'fecha_firma_mandato' => $giroOriginal->fecha_firma_mandato,
                            'iban_cliente' => $giroOriginal->iban_cliente,
                            'auxiliar' => $giroOriginal->auxiliar,
                            'sociedad' => $giroOriginal->sociedad,
                            'residente' => $giroOriginal->residente ?? 'S',
                            'referencia_mandato' => $giroOriginal->referencia_mandato,
                            'referencia_adeudo' => $codigoNuevo,
                            'tipo_adeudo' => 'RCUR',
                            'concepto' => $conceptoNuevo,
                        ]);
                        DB::table($nombreTabla)->where('id', $nuevoId)->update(['pago_id' => $pagoNuevo->id]);
                    }
                }

                DB::commit();

                $renovados[] = [
                    'id' => $nuevoId,
                    'id_original' => $original->id,
                    'codigo_producto' => $codigoNuevo,
                    'codigo_original' => $original->codigo_producto,
                    'anexos_clonados' => $anexosClonados,
                ];
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error("Error renovando producto {$id} de {$nombreTabla}: " . $e->getMessage());
                $errores[] = ['id' => $id, 'codigo_producto' => $original->codigo_producto ?? null, 'motivo' => $e->getMessage()];
            }
        }

        return response()->json([
            'renovados' => $renovados,
            'omitidos' => $omitidos,
            'errores' => $errores,
        ]);
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

        // Normalizar todas las fechas del producto (igual que en crearProducto).
        // Sin la T, SQL Server con idioma español interpreta 'yyyy-mm-dd' como
        // año-día-mes en columnas datetime y revienta con fechas como 1951-07-21.
        $camposFecha = DB::table('campos')
            ->where('tipo_producto_id', (string) $tipoProducto->id)
            ->where('tipo_dato', 'date')
            ->pluck('nombre')
            ->map(fn($nombre) => strtolower(str_replace(' ', '_', $nombre)))
            ->toArray();

        foreach (array_unique(array_merge(['fecha_de_inicio', 'fecha_de_fin', 'updated_at'], $camposFecha)) as $campo) {
            if (!empty($datos[$campo])) {
                try {
                    $datos[$campo] = Carbon::parse($datos[$campo])->format($isoFormat);
                } catch (\Exception $e) {
                    // Valor no parseable: se deja tal cual
                }
            }
        }

        // Filtrar datos para que solo contengan columnas existentes en la tabla
        $columnasValidas = Schema::getColumnListing($nombreTabla);
        $datos = array_intersect_key($datos, array_flip($columnasValidas));

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

        return response()->json($producto);
    }

    public function regenerarDatosInstancia($letrasIdentificacion, $id)
    {
        Log::info("Regenerar Datos - INICIO. Letras: $letrasIdentificacion, ID: $id");

        // 1. Buscar el tipo_producto base original
        $tipoProductoBase = DB::table('tipo_producto')
            ->where('letras_identificacion', $letrasIdentificacion)
            ->first();

        if (!$tipoProductoBase) {
            Log::error("Regenerar Datos - ERROR: Tipo de producto no encontrado. Letras: $letrasIdentificacion");
            return response()->json(['error' => 'Tipo de producto no encontrado'], 404);
        }

        // Si el tipoProducto tiene padre (es un subproducto), la tabla a modificar es la del padre
        $tipoProductoTabla = $tipoProductoBase;
        if ($tipoProductoBase->padre_id != null) {
            Log::info("Regenerar Datos - El tipo proporcionado es un subproducto. Usando padre para resolución de tabla.");
            $tipoProductoTabla = DB::table('tipo_producto')
                ->where('id', $tipoProductoBase->padre_id)
                ->first();
        }

        $nombreTabla = strtolower($tipoProductoTabla->letras_identificacion);
        Log::info("Regenerar Datos - Tabla resolucion: $nombreTabla");

        if (!Schema::hasTable($nombreTabla)) {
            Log::error("Regenerar Datos - ERROR: No existe tabla física: $nombreTabla");
            return response()->json(['error' => 'Product table not found'], 404);
        }

        // Buscar la instancia
        $instancia = DB::table($nombreTabla)
            ->where('id', $id)
            ->first();

        if (!$instancia) {
            Log::error("Regenerar Datos - ERROR: Instancia $id no encontrada en tabla $nombreTabla");
            return response()->json(['error' => 'Instancia de producto no encontrada'], 404);
        }

        // Determinar el tipo de producto real (podría ser un subproducto)
        $tipoProductoReal = $tipoProductoBase;
        if (isset($instancia->subproducto) && $instancia->subproducto) {
            // Intentar encontrar por ID, letras o nombre (el campo subproducto puede guardar cualquiera de estos)
            $found = DB::table('tipo_producto')
                ->where('id', $instancia->subproducto)
                ->orWhere('letras_identificacion', $instancia->subproducto)
                ->orWhere('nombre', $instancia->subproducto)
                ->first();
            
            if ($found) {
                $tipoProductoReal = $found;
            }
            Log::info("Regenerar Datos - Identificado subproducto: " . ($tipoProductoReal->id ?? 'desconocido'));
        }

        // NOTA: Se ha eliminado el bloque que sincronizaba físicamente la tabla 'campos' del subproducto con el padre.
        // La regeneración de una instancia no debe alterar la configuración global del producto, solo los datos de la instancia.


        $updateData = [];

        // 1. Rutas de plantillas (con fallback al padre si el subproducto no tiene propia)
        $parentReal = null;
        if ($tipoProductoReal && $tipoProductoReal->padre_id) {
            $parentReal = DB::table('tipo_producto')->where('id', $tipoProductoReal->padre_id)->first();
        }

        for ($i = 1; $i <= 8; $i++) {
            $colName = 'plantilla_path_' . $i;
            if (Schema::hasColumn($nombreTabla, $colName)) {
                $val = $tipoProductoReal->$colName ?? null;
                // Si el subproducto no tiene plantilla en esta posición, intentamos usar la del padre
                if (empty($val) && $parentReal) {
                    $colNameParent = 'plantilla_path_' . $i;
                    $val = $parentReal->$colNameParent ?? null;
                }
                $updateData[$colName] = $val;
            }
        }


        // 2. Logo Sociedad y Datos del Socio
        if (Schema::hasColumn($nombreTabla, 'logo_sociedad_path')) {
            $updateData['logo_sociedad_path'] = DB::table('sociedad')->where('id', $instancia->sociedad_id)->value('logo');
        }

        if (isset($instancia->socio_id) && $instancia->socio_id) {
            $socio = DB::table('socios')->where('id', $instancia->socio_id)->first();
            if ($socio) {
                if (Schema::hasColumn($nombreTabla, 'nombre_socio')) $updateData['nombre_socio'] = $socio->nombre_socio;
                if (Schema::hasColumn($nombreTabla, 'apellido_1')) $updateData['apellido_1'] = $socio->apellido_1;
                if (Schema::hasColumn($nombreTabla, 'apellido_2')) $updateData['apellido_2'] = $socio->apellido_2;
                if (Schema::hasColumn($nombreTabla, 'dni')) $updateData['dni'] = $socio->dni;
                if (Schema::hasColumn($nombreTabla, 'telefono')) $updateData['telefono'] = $socio->telefono;
                if (Schema::hasColumn($nombreTabla, 'email')) $updateData['email'] = $socio->email;
                if (Schema::hasColumn($nombreTabla, 'sexo')) $updateData['sexo'] = $socio->sexo;
                if (Schema::hasColumn($nombreTabla, 'dirección')) $updateData['dirección'] = $socio->direccion;
                if (Schema::hasColumn($nombreTabla, 'población')) $updateData['población'] = $socio->poblacion;
                if (Schema::hasColumn($nombreTabla, 'provincia')) $updateData['provincia'] = $socio->provincia;
                if (Schema::hasColumn($nombreTabla, 'codigo_postal')) $updateData['codigo_postal'] = $socio->codigo_postal;
                if (Schema::hasColumn($nombreTabla, 'fecha_de_nacimiento')) $updateData['fecha_de_nacimiento'] = $socio->fecha_de_nacimiento;
            }
        }

        // 3. Precios y Tarifas
        $tarifa = DB::table('tarifas_producto')
                    ->where('id_sociedad', $instancia->sociedad_id)
                    ->where('tipo_producto_id', $tipoProductoReal->id)
                    ->first();

        // Fallback: si no hay tarifa para la sociedad del producto, buscar en SOCIEDAD_ADMIN
        if (!$tarifa) {
            $tarifa = DB::table('tarifas_producto')
                        ->where('id_sociedad', env('SOCIEDAD_ADMIN_ID', 1))
                        ->where('tipo_producto_id', $tipoProductoReal->id)
                        ->first();
        }

        // Si no hay tarifa específica para el subproducto, intentar con la del padre
        if (!$tarifa && $parentReal) {
            $tarifa = DB::table('tarifas_producto')
                        ->where('id_sociedad', $instancia->sociedad_id)
                        ->where('tipo_producto_id', $parentReal->id)
                        ->first();

            // Fallback para padre también
            if (!$tarifa) {
                $tarifa = DB::table('tarifas_producto')
                            ->where('id_sociedad', env('SOCIEDAD_ADMIN_ID', 1))
                            ->where('tipo_producto_id', $parentReal->id)
                            ->first();
            }
        }

        if ($tarifa) {
            $caceriasSubproductIds = [10237, 10252, 223];
            $caceriasSubproductLetras = ['PRODUCTO_C3', 'PRODUCTO_C6E', 'PRODUCTO_C6P'];
            $esCaceriaConPuestos = in_array($tipoProductoReal->id, $caceriasSubproductIds)
                || in_array($tipoProductoReal->letras_identificacion, $caceriasSubproductLetras);

            $precioBaseCalculado = (float) ($tarifa->precio_base ?? 0);

            if ($esCaceriaConPuestos) {
                // Recalcular base por opciones (ej: puestos o numero_de_puestos)
                $opcionesPrecio = 0;
                $camposConOpciones = DB::table('campos')
                    ->where('tipo_producto_id', $tipoProductoReal->id)
                    ->whereNotNull('opciones')
                    ->get();

                foreach ($camposConOpciones as $campo) {
                    $colName = $campo->nombre_codigo ? $campo->nombre_codigo : strtolower(str_replace(' ', '_', $campo->nombre));
                    if (Schema::hasColumn($nombreTabla, $colName)) {
                        $selectedValue = $instancia->$colName ?? null;
                        if ($selectedValue && Schema::hasTable($campo->opciones)) {
                            $opcion = DB::table($campo->opciones)->where('nombre', $selectedValue)->first();
                            if ($opcion && isset($opcion->precio)) {
                                $opcionesPrecio += (float) $opcion->precio;
                            }
                        }
                    }
                }
                $precioBaseCalculado += $opcionesPrecio;
            }

            $extra1 = (float) ($tarifa->extra_1 ?? 0);
            $extra2 = (float) ($tarifa->extra_2 ?? 0);
            $extra3 = (float) ($tarifa->extra_3 ?? 0);
            $precioTotalCalculado = $precioBaseCalculado + $extra1 + $extra2 + $extra3;

            if (Schema::hasColumn($nombreTabla, 'precio_base')) {
                $updateData['precio_base'] = $precioBaseCalculado;
            }
            if (Schema::hasColumn($nombreTabla, 'extra_1')) {
                $updateData['extra_1'] = $extra1;
            }
            if (Schema::hasColumn($nombreTabla, 'extra_2')) {
                $updateData['extra_2'] = $extra2;
            }
            if (Schema::hasColumn($nombreTabla, 'extra_3')) {
                $updateData['extra_3'] = $extra3;
            }
            if (Schema::hasColumn($nombreTabla, 'precio_total')) {
                $updateData['precio_total'] = $precioTotalCalculado;
            }
        } else {
            // Si no hay tarifa, preservar el precio del producto clonado (original)
            if (Schema::hasColumn($nombreTabla, 'precio_total') && $instancia->precio_total !== null) {
                $updateData['precio_total'] = $instancia->precio_total;
            }
        }


        // 4. Metadatos adicionales
        if (Schema::hasColumn($nombreTabla, 'nombre_producto')) {
            $updateData['nombre_producto'] = $tipoProductoReal->nombre;
        }
        if (Schema::hasColumn($nombreTabla, 'subproducto_codigo')) {
            $updateData['subproducto_codigo'] = $tipoProductoReal->nombre;
        }

        if (Schema::hasColumn($nombreTabla, 'blob_name')) {
            $updateData['blob_name'] = '';
        }

        $updateData['updated_at'] = Carbon::now()->format('Y-m-d\TH:i:s');

        DB::table($nombreTabla)->where('id', $id)->update($updateData);

        // 5. Regenerar anexos
        $anexosTipos = DB::table('tipo_producto')
            ->where('tipo_producto_asociado', $tipoProductoTabla->id)
            ->get();

        $sumaAnexos = 0;

        foreach ($anexosTipos as $tipoAnexo) {
            $tablaAnexo = strtolower($tipoAnexo->letras_identificacion);
            if (Schema::hasTable($tablaAnexo)) {
                $anexosInstancia = DB::table($tablaAnexo)
                    ->where('producto_id', $id)
                    ->where('anulado', 0)
                    ->get();
                    
                foreach ($anexosInstancia as $anInst) {
                    $updateAnexo = [];
                    // Sincronizar plantillas
                    for ($i = 1; $i <= 8; $i++) {
                        $colName = 'plantilla_path_' . $i;
                        if (Schema::hasColumn($tablaAnexo, $colName)) {
                            $updateAnexo[$colName] = $tipoAnexo->$colName ?? null;
                        }
                    }

                    // Sincronizar precios
                    $sociedadAdmin = env('SOCIEDAD_ADMIN_ID', 1);
                    $tarifaAnexo = DB::table('tarifas_producto')
                        ->where('id_sociedad', $sociedadAdmin)
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

                    if (Schema::hasColumn($tablaAnexo, 'blob_name')) {
                        $updateAnexo['blob_name'] = '';
                    }

                    $updateAnexo['updated_at'] = Carbon::now()->format('Y-m-d\TH:i:s');

                    if (!empty($updateAnexo)) {
                        DB::table($tablaAnexo)->where('id', $anInst->id)->update($updateAnexo);
                    }
                }
            }
        }

        // 6. Actualizar precio_final global
        if (Schema::hasColumn($nombreTabla, 'precio_final')) {
            $baseTarifa = isset($precioTotalCalculado) ? $precioTotalCalculado : ($tarifa ? ($tarifa->precio_total ?? 0) : ($instancia->precio_total ?? 0));
            $nuevoPrecioFinal = $baseTarifa + $sumaAnexos;
            DB::table($nombreTabla)->where('id', $id)->update(['precio_final' => $nuevoPrecioFinal]);
        }

        $instanciaActualizada = DB::table($nombreTabla)->where('id', $id)->first();
        Log::info("Regenerar Datos - FIN exitoso.");
        
        return response()->json([
            'message' => 'Datos regenerados con éxito',
            'producto' => $instanciaActualizada
        ], 200);
    }
}
