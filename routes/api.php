<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CampoController;
use App\Http\Controllers\ValorController;
use App\Http\Controllers\TipoProductoController;
use App\Http\Controllers\SociedadController;
use App\Http\Controllers\TipoProductoSociedadController;
use App\Http\Controllers\ComercialController;
use App\Http\Controllers\ComercialComisionController;
use App\Http\Controllers\TipoAnexoController;
use App\Http\Controllers\CampoAnexoController;
use App\Http\Controllers\ValorAnexoController;
use App\Http\Controllers\TarifaProductoController;
use App\Http\Controllers\TarifaAnexoController;
use App\Http\Controllers\EscaladoAnexoController;
use App\Http\Controllers\NavegacionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\NavController;
use App\Http\Controllers\AnuladosController;
use App\Http\Controllers\AnexosController;



// Route::get('/productos/{letras_identificativas}', [ProductoController::class, 'getProductosPorTipo']);

Route::get('/productos/{letrasIdentificacion}', [ProductoController::class, 'getProductosByTipoAndSociedades']);

// ANULADOS
Route::get('anulados/{letrasIdentificacion}', [AnuladosController::class, 'getAnulados']);



Route::post('/crear-producto/{letrasIdentificacion}', [ProductoController::class, 'crearProducto']);
Route::post('/editar-producto/{letrasIdentificacion}', [ProductoController::class, 'editarProducto']);
Route::post('/anular-producto/{letrasIdentificacion}', [ProductoController::class, 'anularProducto']);
Route::delete('/eliminar-producto/{letrasIdentificacion}', [ProductoController::class, 'eliminarProducto']);


Route::post('/crear-tipo-producto', [ProductoController::class, 'crearTipoProducto']);
Route::post('/subir-plantilla/{letrasIdentificacion}', [ProductoController::class, 'subirPlantilla']);


Route::get('/descargar-plantilla/{letrasIdentificacion}', [ExportController::class, 'exportExcelToPdf']);


Route::apiResource('campos', CampoController::class);
Route::get('/campos', [CampoController::class, 'getByTipoProducto']);
Route::put('/campos-update/{id_tipo_producto}', [CampoController::class, 'updatePorTipoProducto']);
Route::post('/add-campos/{id_tipo_producto}', [ProductoController::class, 'addCampos']);


Route::get('tipos-producto/sociedad/{id_sociedad}', [TipoProductoController::class, 'getTiposProductoPorSociedad']);
Route::get('tipos-producto/all', [TipoProductoController::class, 'index']);
Route::get('tipo-producto/{letras}', [TipoProductoController::class, 'getByLetras']);
Route::get('tipo-producto/show/{ruta}', [TipoProductoController::class, 'show']);
Route::put('tipo-producto/{id}', [TipoProductoController::class, 'update']);
Route::put('tipo-producto/edit-nombre/{id}', [TipoProductoController::class, 'updateNombre']);
Route::delete('tipo-producto/delete/{id}', [TipoProductoController::class, 'deleteTipoProducto']);


Route::get('sociedad/{id}', [SociedadController::class, 'show']);
Route::get('sociedad/hijas/{id}', [SociedadController::class, 'getSociedadesHijas']);
Route::post('sociedad', [SociedadController::class, 'store']);
Route::delete('sociedad/{id}', [SociedadController::class, 'destroy']);
Route::put('sociedad/{id}/permisos', [SociedadController::class, 'updatePermisos']);
Route::get('sociedades/padres', [SociedadController::class, 'getSociedadesPadres']);


// Gestiona todas las solicitudes de la conexion entre TipoProducto y Sociedad
Route::post('tipo-producto-sociedad', [TipoProductoSociedadController::class, 'store']);
Route::post('sociedad/{sociedad_padre_id}/hija/{sociedad_hija_id}', [TipoProductoSociedadController::class, 'transferirTiposProductos']);

Route::apiResource('comerciales', ComercialController::class);
Route::get('comerciales/sociedad/{id_sociedad}', [ComercialController::class, 'getComercialesPorSociedad']);
Route::delete('comercial/{id}', [ComercialController::class, 'destroy']);

Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/comercial/{id}', [ComercialController::class, 'show']);
Route::put('/comercial/{id}', [ComercialController::class, 'update']);
Route::post('/comercial', [ComercialController::class, 'store']);

Route::apiResource('comercial-comisiones', ComercialComisionController::class);

// ANEXOS:
Route::get('/anexos/sociedad/{id_sociedad}', [AnexosController::class, 'getAnexosPorSociedad']);

Route::get('anexos/{id_tipo_producto}/producto/{id_producto}', [AnexosController::class, 'getAnexosPorProducto']);
// Conectar anexo con producto:
Route::post('/anexos/{id_producto}', [AnexosController::class, 'conectarAnexosConProducto']);

//Tipo anexo:
Route::delete('/anexos/{id}', [AnexosController::class, 'destroy']);

// Crear tipo anexo:
Route::post('/anexos', [AnexosController::class, 'createTipoAnexo']);
// Subir plantilla:
Route::post('subir-plantilla-anexo/{letrasIdentificacion}', [AnexosController::class, 'subirPlantillaAnexo']);

// Descargar plantilla anexo:
Route::get('/descargar-plantilla-anexo/{tipoAnexoId}', [ExportController::class, 'exportAnexoExcelToPdf']);


Route::get('/anexos/tipo-producto/{id_tipo_producto}', [AnexosController::class, 'getTipoAnexosPorTipoProducto']);

Route::apiResource('tipos-anexo', TipoAnexoController::class);
Route::get('tipos-anexo/all', [TipoAnexoController::class, 'index']);
Route::apiResource('campos-anexo', CampoAnexoController::class);

Route::get('campos-anexo/tipo-anexo/{id_tipo_anexo}', [CampoAnexoController::class, 'getCamposPorTipoAnexo']);

// Todas las tarifas por sociedad
Route::get('tarifas/sociedad/{id_sociedad}', [TarifaProductoController::class, 'getTarifaPorSociedad']);
// Tarifa por tipoProducto y Sociedad
Route::get('tarifas-producto/sociedad/{id_sociedad}', [TarifaProductoController::class, 'getTarifaPorSociedadAndTipoProducto']);
// Set tarifa por sociedad y tipoProducto
Route::post('tarifa-producto/sociedad', [TarifaProductoController::class, 'store']);

Route::put('tarifa/sociedad/{id_sociedad}', [TarifaProductoController::class, 'updateTarifaPorSociedad']);
Route::post('tarifa/sociedad/{id_sociedad}', [TarifaProductoController::class, 'createTarifaPorSociedad']);

Route::apiResource('tarifas-producto', TarifaProductoController::class);
Route::apiResource('tarifas-anexo', TarifaAnexoController::class);

Route::post('tarifa-anexo/sociedad', [TarifaAnexoController::class, 'store']);
Route::get('tarifa-anexo/sociedad/{id_sociedad}/tipo-anexo/{id_tipo_anexo}', [TarifaAnexoController::class, 'getTarifaPorSociedadAndTipoAnexo']);


Route::apiResource('escalado-anexos', EscaladoAnexoController::class);

Route::get('/nav/{id_sociedad}', [NavController::class, 'getNavegacion']);

