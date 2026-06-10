<?php

namespace App\Http\Controllers;

use App\Models\TipoProducto;
use Illuminate\Http\Request;
use App\Models\TipoProductoSociedad;
use App\Models\Sociedad;
use App\Models\SocioComercial;
use App\Models\Categoria;
use App\Models\Comercial;

class NavController extends Controller
{
    const SOCIEDAD_ADMIN_ID = 1;

    public function getNavegacionSocio($categoria, $socio_id)
    {
        $tiposProducto = TipoProducto::activos()
            ->where('categoria_id', $categoria)
            ->whereNull('padre_id')
            ->whereNull('tipo_producto_asociado')
            ->get();

        $navegacion = [];
        $navegacion[] = [
            "label" => "Mis Datos",
            "link" => '/mis-datos'
        ];
        $navegacion[] = [
            "label" => "Contratar",
            "children" => []
        ];

        $navegacion[] = [
            "label" => "Mis Productos",
            "children" => []
        ];

        $comercial = SocioComercial::where('id_socio', $socio_id)->with('comercial')->first();
        $comercial_id = $comercial?->id_comercial;

        if (!$comercial_id) {
            $categoria_obj = Categoria::findOrFail($categoria);
            $comercial_id = $categoria_obj->comercial_responsable_id;
            $comercial = Comercial::find($comercial_id);
        } else {
            $comercial = $comercial->comercial;
        }

        if ($comercial && $comercial->id_sociedad) {
            $tipoProductoIds = TipoProductoSociedad::where('id_sociedad', $comercial->id_sociedad)->pluck('id_tipo_producto')->toArray();
            $tiposProducto = $tiposProducto->filter(function ($tp) use ($tipoProductoIds) {
                return in_array($tp->id, $tipoProductoIds);
            });
        }

        $navegacion[1]["children"] = $tiposProducto->map(function ($tipoProducto) use ($comercial_id) {
            return [
                "label" => 'Contratar - ' . $tipoProducto->nombre,
                "link" => "/contratacion/" . strtolower($tipoProducto->letras_identificacion) . '/' . $comercial_id
            ];
        })->toArray();
        $navegacion[2]["children"] = $tiposProducto->map(function ($tipoProducto) {
            return [
                "label" => $tipoProducto->nombre,
                "link" => "/mis-productos/" . strtolower($tipoProducto->letras_identificacion)
            ];
        })->toArray();

        return response()->json($navegacion);
    }

    // Para coger las distintas rutas de la aplicación
    public function getNavegacion($id_sociedad, $responsable)
    {
        // Coger los tipos de producto asociados con la sociedad
        $tipoProductoIds = TipoProductoSociedad::where('id_sociedad', $id_sociedad)->pluck('id_tipo_producto');

        // Coger los tipos de producto basados en los IDs obtenidos (específicos de la sociedad)
        $tiposProductoLinkeados = TipoProducto::activos()
            ->whereIn('id', $tipoProductoIds)
            ->whereNull('padre_id')
            ->whereNull('tipo_producto_asociado')
            ->get();

        // Coger TODOS los tipos de producto base activos (para los informes de administración)
        $tiposProductoTodos = TipoProducto::activos()
            ->whereNull('padre_id')
            ->whereNull('tipo_producto_asociado')
            ->get();


        $navegacion = [];
        $navegacion[] = [
            "label" => "Administración",
            "children" => []
        ];
        $navegacion[] = [
            "label" => "Gestión",
            "children" => []
        ];
        $navegacion[] = [
            "label" => "Productos",
            "children" => []
        ];



        // Si es admin (id 1), mostramos TODOS los tipos de producto, si no, solo los vinculados
        $productosParaInformes = ($id_sociedad == env('SOCIEDAD_ADMIN_ID', 1)) ? $tiposProductoTodos : $tiposProductoLinkeados;

        $navegacion[0]["children"] = $productosParaInformes->map(function ($tipoProducto) {
            return [
                "label" => "Informes " . $tipoProducto->nombre,
                "link" => "/informes/" . $tipoProducto->letras_identificacion
            ];
        })->toArray();
        $navegacion[1]["children"] = [
            [
                "label" => "Sociedades",
                "link" => "/sociedades"
            ],
            [
                "label" => "Tarifas",
                "link" => "/tarifas"
            ],
            [
                "label" => "Comisiones",
                "link" => "/comisiones"
            ],
            [
                "label" => "Categorías",
                "link" => "/categorias"
            ],
            [
                "label" => "Productos",
                "link" => "/gestion-productos"
            ],
            [
                "label" => "Compañías",
                "link" => "/companias"
            ],
            [
                "label" => "Socios",
                "link" => "/socios"
            ]
        ];
        // Si es admin (id 1), mostramos TODOS los tipos de producto en la gestión de productos
        $productosParaMenu = ($id_sociedad == env('SOCIEDAD_ADMIN_ID', 1)) ? $tiposProductoTodos : $tiposProductoLinkeados;

        $navegacion[2]["children"] = $productosParaMenu->map(function ($tipoProducto) {
            return [
                "label" => $tipoProducto->nombre,
                "link" => "/operaciones/" . strtolower($tipoProducto->letras_identificacion)
            ];
        })->toArray();
        // La parte de gestion solo si el id es el mismo que la sociedad admin, despues coger tipos producto y en Administracion coger los nombres
        // y concatenarlos con Informes y en link meter /informes/:letrasIdentificacion, En Productos en el label el nombre directamente y en el link /operaciones/:letrasIdentificacion

        $sociedad = Sociedad::find($id_sociedad);
        $sociedadPadreId = $sociedad->sociedad_padre_id;

        // Condición para filtrar las opciones en el array de navegación
        if ($id_sociedad == env('SOCIEDAD_ADMIN_ID')) {
            // Sociedad Admin: Ver todo
        } else {
            // Resto de sociedades: Sociedades, Comisiones, Socios
            $navegacion[1]["children"] = array_values(array_filter($navegacion[1]["children"], function ($child) {
                return in_array($child["label"], ["Sociedades", "Comisiones", "Socios"]);
            }));
        }

        // Gestión de pagos solo para la sociedad admin
        if ($id_sociedad == env('SOCIEDAD_ADMIN_ID', 1)) {
            array_unshift($navegacion[0]["children"], [
                "label" => "Gestión de pagos",
                "link" => "/gestion-pagos"
            ]);
        }

        // Si no es responsable y NO es la sociedad admin, solo Socios en Gestión
        if ($responsable != 1 && $id_sociedad != env('SOCIEDAD_ADMIN_ID', 1)) {
            $navegacion[1]["children"] = array_values(array_filter($navegacion[1]["children"], function ($child) {
                return in_array($child["label"], ["Socios"]);
            }));
        }

        return response()->json($navegacion);
    }
}
