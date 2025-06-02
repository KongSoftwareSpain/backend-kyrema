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
use App\Http\Controllers\EscaladoAnexoController;
use App\Http\Controllers\NavegacionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\NavController;
use App\Http\Controllers\AnuladosController;
use App\Http\Controllers\AnexosController;
use App\Http\Controllers\TipoPagoController;
use App\Http\Controllers\TipoPagoProductoSociedadController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\CompaniaController;
use App\Http\Controllers\PolizaController;
use App\Http\Controllers\SocioController;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ReferenciaSecuenciaController;
use App\Http\Controllers\RemesaController;
use App\Http\Controllers\SociedadComisionController;
use App\Http\Controllers\TarifaAnexoController;
use App\Http\Controllers\PagoExportController;

// Route::get('/productos/{letras_identificativas}', [ProductoController::class, 'getProductosPorTipo']);
Route::post('password/email', [ForgotPasswordController::class, 'sendResetLinkEmail']);
Route::post('password/reset', [ResetPasswordController::class, 'reset']);
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/login/socio', [AuthController::class, 'loginSocio']);
Route::post('auth/register/socio', [AuthController::class, 'registerSocio']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('productos/{letrasIdentificacion}', [ProductoController::class, 'getProductosByTipoAndSociedades']);
    Route::get('historial/{letrasIdentificacion}', [ProductoController::class, 'getHistorialProductosByTipoAndSociedades']);
    Route::get('productos/{letrasIdentificacion}/comercial/{comercial_id}', [ProductoController::class, 'getProductosByTipoAndComercial']);
    Route::get('historial/{letrasIdentificacion}/comercial/{comercial_id}', [ProductoController::class, 'getHistorialProductosByTipoAndComercial']);

    // ANULADOS
    Route::get('anulados/{letrasIdentificacion}', [AnuladosController::class, 'getAnulados']);


    Route::post('crear-producto/{letrasIdentificacion}', [ProductoController::class, 'crearProducto']);
    Route::post('editar-producto/{letrasIdentificacion}', [ProductoController::class, 'editarProducto']);
    Route::post('anular-producto/{letrasIdentificacion}', [ProductoController::class, 'anularProducto']);
    Route::delete('eliminar-producto/{letrasIdentificacion}', [ProductoController::class, 'eliminarProducto']);
    Route::get('duraciones/{nombreTabla}', [ProductoController::class, 'getDuraciones']);


    Route::post('crear-tipo-producto', [ProductoController::class, 'crearTipoProducto']);
    Route::post('subir-plantilla/{id_tipo_producto}/{page}', [ProductoController::class, 'subirPlantilla']);


    Route::get('descargar-plantilla/{letrasIdentificacion}', [ExportController::class, 'exportToPdf']);
    Route::get('plantilla-base64', [ExportController::class, 'getPlantillaBase64']);


    Route::apiResource('campos', CampoController::class);
    Route::get('campos', [CampoController::class, 'getByTipoProducto']);
    Route::put('campos-update/{id_tipo_producto}', [CampoController::class, 'updatePorTipoProducto']);
    Route::post('add-campos/{id_tipo_producto}', [CampoController::class, 'addCampos']);
    Route::post('create-campo-opciones/{id_tipo_producto}', [CampoController::class, 'createCampoConOpcionesHTTP']);
    Route::put('update-campo-opciones/{id}', [CampoController::class, 'updateCampoConOpciones']);
    Route::get('opciones/{id_campo}', [CampoController::class, 'getOpcionesPorCampo']);
    Route::get('campos-certificado/{id}', [CampoController::class, 'getCamposCertificado']);
    Route::get('logos/tipo-producto/{id}', [CampoController::class, 'getCamposLogos']);

    Route::get('tipos-producto/sociedad/{id_sociedad}', [TipoProductoController::class, 'getTiposProductoPorSociedad']);
    Route::get('tipos-producto/all', [TipoProductoController::class, 'index']);
    Route::get('tipo-producto/{letras}', [TipoProductoController::class, 'getByLetras']);
    Route::get('tipo-producto/show/{id}', [TipoProductoController::class, 'show']);
    Route::put('tipo-producto/{id}', [TipoProductoController::class, 'update']);
    Route::put('tipo-producto/edit/{id}', [TipoProductoController::class, 'updateTipoProducto']);
    Route::get('logos/tipo-producto/{id_tipo_producto}', [TipoProductoController::class, 'getLogosPorTipoProducto']);
    Route::delete('tipo-producto/delete/{id}', [TipoProductoController::class, 'destroy']);
    Route::get('subproductos/padre/{id}', [TipoProductoController::class, 'getSubproductosPorPadreId']);
    Route::patch('tipo-producto/{id}/estado', [TipoProductoController::class, 'cambiarEstado']);


    Route::get('sociedad/{id}', [SociedadController::class, 'show']);
    Route::get('sociedad/{id_sociedad}/segundo-nivel', [SociedadController::class, 'getSocietySecondLevel']);
    Route::get('sociedad/hijas/{id}', [SociedadController::class, 'getSociedadesHijas']);
    Route::get('sociedad/{sociedad_id}/hijas/tipo-producto/{letras_identificacion}', [SociedadController::class, 'getSociedadesHijasPorTipoProducto']);
    Route::post('sociedad', [SociedadController::class, 'store']);
    Route::delete('sociedad/{id}', [SociedadController::class, 'destroy']);
    Route::put('sociedad/{id}', [SociedadController::class, 'update']);
    Route::put('sociedad/{id}/permisos', [SociedadController::class, 'updatePermisos']);
    Route::get('sociedades/padres', [SociedadController::class, 'getSociedadesPadres']);
    Route::get('sociedades', [SociedadController::class, 'index']);
    Route::get('sociedad/comercial/{comercial_id}', [SociedadController::class, 'getSociedadPorComercial']);


    // Gestiona todas las solicitudes de la conexion entre TipoProducto y Sociedad
    Route::post('tipo-producto-sociedad', [TipoProductoSociedadController::class, 'store']);
    Route::post('sociedad/{sociedad_padre_id}/hija/{sociedad_hija_id}', [TipoProductoSociedadController::class, 'transferirTiposProductos']);

    Route::get('comerciales/all', [ComercialController::class, 'getAllUsers']);
    Route::get('comerciales/sociedad/{id_sociedad}', [ComercialController::class, 'getComercialesPorSociedad']);
    Route::get('comerciales/responsables', [ComercialController::class, 'getResponsables']);
    Route::post('comercial', [ComercialController::class, 'store']);
    Route::put('comercial/{id}', [ComercialController::class, 'update']);
    Route::delete('comercial/{id}', [ComercialController::class, 'destroy']);

    Route::get('comercial/{id}', [ComercialController::class, 'show']);
    Route::put('comercial/{id}', [ComercialController::class, 'update']);
    Route::post('comercial', [ComercialController::class, 'store']);

    Route::get('comisiones/comercial/{id}', [ComercialComisionController::class, 'index']);
    Route::get('comisiones/sociedad/{id}', [SociedadComisionController::class, 'index']);
    Route::put('comisiones/comercial/{id}', [ComercialComisionController::class, 'store']);
    Route::put('comisiones/sociedad/{id}', [SociedadComisionController::class, 'store']);

    Route::post('comisiones-total-price/sociedad/{sociedadId}', [SociedadComisionController::class, 'getTotalPrice']);
    Route::post('comisiones-total-price/comercial/{sociedadId}', [SociedadComisionController::class, 'getTotalPriceForCommercial']);


    // ANEXOS:
    Route::get('anexos/sociedad/{id_sociedad}', [AnexosController::class, 'getAnexosPorSociedad']);

    Route::get('anexos/{id}', [AnexosController::class, 'show']);

    Route::get('anexos/{id_tipo_producto}/producto/{id_producto}', [AnexosController::class, 'getAnexosPorProducto']);
    // Conectar anexo con producto:
    Route::post('anexos/{id_producto}', [AnexosController::class, 'conectarAnexosConProducto']);

    //Tipo anexo:
    Route::delete('anexos/{id}', [AnexosController::class, 'destroy']);

    // Crear tipo anexo:
    Route::post('anexos', [AnexosController::class, 'createTipoAnexo']);
    // Subir plantilla:
    Route::post('subir-plantilla-anexo/{letrasIdentificacion}', [AnexosController::class, 'subirPlantillaAnexo']);

    // Descargar plantilla anexo:
    Route::get('descargar-plantilla-anexo/{tipoAnexoId}', [ExportController::class, 'exportAnexoExcelToPdf']);

    // LOGOS:
    Route::get('logo/{tipoLogo}/{entidad_id}', [ExportController::class, 'getLogoBase64']);

    //ANEXOS BLOQUEADOS
    Route::get('anexos-bloqueados/{tipo_producto_asociado}', [AnexosController::class, 'getAnexosBloqueados']);
    Route::post('anexos-bloqueados', [AnexosController::class, 'saveAnexosBloqueados']);


    Route::get('anexos/tipo-producto/{id_tipo_producto}', [AnexosController::class, 'getTipoAnexosPorTipoProducto']);


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

    // Route::apiResource('tarifas-producto', TarifaProductoController::class);
    // Route::apiResource('tarifas-anexo', TarifaAnexoController::class);

    Route::post('tarifa-anexo/sociedad', [TarifaAnexoController::class, 'store']);
    Route::get('tarifa-anexo/sociedad/{id_sociedad}/tipo-anexo/{id_tipo_anexo}', [TarifaAnexoController::class, 'getTarifaPorSociedadAndTipoAnexo']);

    // TIPOS PAGO:
    Route::get('tipos-pago/all', [TipoPagoController::class, 'index']);
    Route::post('tipo_pago_producto_sociedad', [TipoPagoProductoSociedadController::class, 'store']);
    Route::get('tipo_pago_producto_sociedad/sociedad/{id_sociedad}', [TipoPagoProductoSociedadController::class, 'getTiposPagoPorSociedad']);
    Route::get('tipo_pago_producto_sociedad/sociedad/{sociedad_id}/tipo-producto/{tipo_producto_id}', [TipoPagoProductoSociedadController::class, 'getTiposPagoPorSociedadYTipoProducto']);
    Route::post('sociedad/{sociedad_padre_id}/hija/{sociedad_hija_id}/tipos-pago', [TipoPagoProductoSociedadController::class, 'transferirTiposPago']);

    // PAGOS:
    // GENERAR LA REFERENCIA DURANTE EL PAGO (No puede haber un producto 'sin pagar')
    Route::get('generar-referencia/{letras}', [ReferenciaSecuenciaController::class, 'generateReference']);

    Route::post('pago/giro-bancario', [RemesaController::class, 'storeGiroBancario']);
    Route::post('pago/giro-bancario/fecha-cobro', [RemesaController::class, 'guardarFechaCobro']);

    Route::post('pago/generate-csv', [PagoExportController::class, 'exportarPagos']);
    Route::post('pago/generar-xml-q-19', [RemesaController::class, 'generarQ19']);

    Route::get('pago/downloads/{tipoPago}', [RemesaController::class, 'getDescargas']);

    // COMPAÑIAS:

    Route::get('companies', [CompaniaController::class, 'getAll']);
    Route::get('companies/{id}', [CompaniaController::class, 'getCompanyById']);
    Route::post('companies', [CompaniaController::class, 'createCompany']);
    Route::put('companies/{id}', [CompaniaController::class, 'updateCompany']);
    Route::delete('companies/{id}', [CompaniaController::class, 'deleteCompany']);

    // POLIZAS:

    Route::get('company/{id}/polizas', [PolizaController::class, 'getPolizasByCompany']);
    Route::get('poliza/{id}', [PolizaController::class, 'getPolizaById']);
    Route::post('poliza', [PolizaController::class, 'store']);
    Route::post('poliza/{id}', [PolizaController::class, 'update']);
    Route::delete('poliza/{id}', [PolizaController::class, 'destroy']);
    Route::get('polizas/tipo-producto/{id}', [PolizaController::class, 'getPolizasByTipoProducto']);
    Route::put('polizas/tipo-producto/{id}', [PolizaController::class, 'updatePolizas']);
    Route::get('descargar-poliza/{id}', [PolizaController::class, 'downloadPoliza']);


    Route::apiResource('escalado-anexos', EscaladoAnexoController::class);

    Route::get('/nav/{id_sociedad}/{responsable}', [NavController::class, 'getNavegacion']);
    Route::get('/nav-socio/{categoria}/socio/{socio_id}', [NavController::class, 'getNavegacionSocio']);
    Route::get('/exportar-pagos', [PagoExportController::class, 'exportarPagos']);

    //Pagos:
    Route::post('/payment/create', [PaymentController::class, 'createPayment']);


    // SOCIOS:
    Route::get('socios', [SocioController::class, 'index']);
    Route::get('socio/{id}', [SocioController::class, 'show']);
    Route::get('socio/{dni}/categoria/{categoria_id}', [SocioController::class, 'getAsegurado']);
    Route::post('socio/categoria/{categoria_id}', [SocioController::class, 'store']);
    Route::put('socio/{id}', [SocioController::class, 'update']);
    Route::delete('socio/{id}', [SocioController::class, 'destroy']);
    Route::get('socios/comercial/{id_comercial}', [SocioController::class, 'getSociosByComercial']);

    Route::get('socio/{id}/productos/{id_tipo_producto}', [SocioController::class, 'getProductosBySocio']);

    // CATEGORIAS:
    Route::get('categorias', [CategoriaController::class, 'index']);
    Route::post('categorias', [CategoriaController::class, 'store']);
    Route::get('categorias/{id}', [CategoriaController::class, 'show']);
    Route::post('categorias/{id}', [CategoriaController::class, 'update']);

    // INFORMES:
    Route::get('reports', [ExportController::class, 'getReportData']);
});
