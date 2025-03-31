<?php

namespace App\Http\Controllers;

use App\Models\TipoProducto;
use Illuminate\Http\Request;
use App\Models\TipoProductoSociedad;
use App\Models\Sociedad;

class NavController extends Controller
{   
    const SOCIEDAD_ADMIN_ID = 1;

    public function getNavegacionSocio($categoria){
        $tiposProducto = TipoProducto::where('categoria_id', $categoria)->get();

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
        
        $tiposProducto = TipoProducto::where('categoria_id', $categoria)->whereNull('padre_id')->whereNull('tipo_producto_asociado')->get();

        
        $navegacion[1]["children"] = $tiposProducto->map(function($tipoProducto){
            return [
                "label" => $tipoProducto->nombre,
                "link" => "/contratar/" . strtolower($tipoProducto->letras_identificacion) . '/1'
            ];
        })->toArray();
        $navegacion[2]["children"] = $tiposProducto->map(function($tipoProducto){
            return [
                "label" => $tipoProducto->nombre,
                "link" => "/mis-productos/" . strtolower($tipoProducto->letras_identificacion)
            ];
        })->toArray();

        return response()->json($navegacion);
    }

    // Para coger las distintas rutas de la aplicación
    public function getNavegacion($id_sociedad, $responsable){
        // Coger los tipos de producto asociados con la sociedad
        $tipoProductoIds = TipoProductoSociedad::where('id_sociedad', $id_sociedad)->pluck('id_tipo_producto');

        // Coger los tipos de producto basados en los IDs obtenidos
        $tiposProducto = TipoProducto::whereIn('id', $tipoProductoIds)->whereNull('padre_id')->whereNull('tipo_producto_asociado')->get();

        //Devolver la navegación con el siguiente formato:
        
        // [
        //     {
        //         "label": "Administración",
        //         "children": [
        //             {
        //                 "label": "Informes seguros combinados",
        //                 "link": "/informes/sc"
        //             },
        //             {
        //                 "label": "Informes seguros eventos",
        //                 "link": "/informes/se"
        //             },
        //          ]
        //     },
        //     {
        //         "label": "Gestión",
        //         "children": [
        //             {
        //                 "label": "Sociedades",
        //                 "link": "/sociedades"
        //             },
        //             {
        //                 "label": "Tarifas",
        //                 "link": "/tarifas"
        //             },
        //             {
        //                 "label": "Comisiones",
        //                 "link": "/comisiones"
        //             },
        //             {
        //                 "label": "Productos",
        //                 "link": "/gestion-productos"
        //             }
        //         ]
        //     },
        //     {
        //         "label": "Productos",
        //         "children": [
        //             {
        //                 "label": "Seguros combinados",
        //                 "link": "/operaciones/sc"
        //             },
        //             {
        //                 "label": "Seguros eventos",
        //                 "link": "/operaciones/se"
        //             },
        //         ]
        //     }
        // ]

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


        $navegacion[0]["children"] = $tiposProducto->map(function($tipoProducto){
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
        $navegacion[2]["children"] = $tiposProducto->map(function($tipoProducto){
            return [
                "label" => $tipoProducto->nombre,
                "link" => "/operaciones/" . strtolower($tipoProducto->letras_identificacion)
            ];
        })->toArray();
        // La parte de gestion solo si el id es el mismo que la sociedad admin, despues coger tipos producto y en Administracion coger los nombres
        // y concatenarlos con Informes y en link meter /informes/:letrasIdentificacion, En Productos en el label el nombre directamente y en el link /operaciones/:letrasIdentificacion

        $sociedad = Sociedad::find($id_sociedad);
        $sociedadPadreId = $sociedad->sociedad_padre_id;

        // Condición para eliminar la parte de gestión solo si ambas condiciones se cumplen
        // if ($id_sociedad != self::SOCIEDAD_ADMIN_ID && $sociedadPadreId != self::SOCIEDAD_ADMIN_ID) {
        //     //Cambiar el navegacion[2] por el 1 y quitar el 2:
        //     if (isset($navegacion[2])) {
        //         $navegacion[1] = $navegacion[2];
        //         unset($navegacion[2]);
        //     }
        // }

        // Condición para filtrar las opciones en el array de navegación
        if ($sociedadPadreId == env('SOCIEDAD_ADMIN_ID') && isset($navegacion[2])) {
            $navegacion[1]["children"] = array_values(array_filter($navegacion[1]["children"], function($child) {
                return in_array($child["label"], ["Sociedades", "Comisiones", "Socios"]);
            }));
        }

        // Si no es responsable
        if($responsable != 1){
            // Quitar el apartado de Gestión excepto Socios.
            $navegacion[1]["children"] = array_values(array_filter($navegacion[1]["children"], function($child) {
                return in_array($child["label"], ["Socios"]);
            }));
        }

        return response()->json($navegacion);
    }
}
