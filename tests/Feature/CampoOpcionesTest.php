<?php

namespace Tests\Feature;

use App\Http\Controllers\CampoController;
use App\Models\Campos;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regresión del bug "los campos selector (p. ej. Ámbito de GT) no guardan sus opciones":
 * Campos::create() descartaba 'opciones' (no es fillable) y el campo quedaba sin enlace a su tabla
 * de opciones; además GET /campos se servía de una caché de 5 min que nadie invalidaba.
 *
 * Corre SIEMPRE sobre SQLite en memoria. No usa RefreshDatabase a propósito: el .env apunta a la
 * BD real, y migrate:fresh sobre ella sería un desastre.
 */
class CampoOpcionesTest extends TestCase
{
    private CampoController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        // Freno de seguridad: si por lo que sea no estamos en SQLite, no se toca NADA.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->fail('Estos tests solo pueden correr sobre SQLite en memoria (revisa phpunit.xml).');
        }

        Schema::create('tipo_producto', function (Blueprint $t) {
            $t->id();
            $t->string('nombre')->nullable();
            $t->string('letras_identificacion');
            $t->unsignedBigInteger('padre_id')->nullable();
            $t->unsignedBigInteger('tipo_producto_asociado')->nullable();
        });

        Schema::create('campos', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->string('nombre_codigo')->nullable();
            $t->string('tipo_producto_id')->nullable();
            $t->string('columna')->nullable();
            $t->string('fila')->nullable();
            $t->string('tipo_dato');
            $t->boolean('visible')->default(false);
            $t->boolean('obligatorio')->default(false);
            $t->string('grupo')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->string('opciones')->nullable();
            $t->string('page')->nullable();
            $t->boolean('copia')->nullable();
            $t->string('font_size')->nullable();
            $t->string('salto_linea_x')->nullable();
            $t->string('salto_linea_y')->nullable();
        });

        // Tabla física del producto (como producto_gt en producción: minúsculas)
        Schema::create('producto_gt', fn (Blueprint $t) => $t->id());

        DB::table('tipo_producto')->insert([
            'id' => 10287, 'nombre' => 'Guias Turisticos', 'letras_identificacion' => 'PRODUCTO_GT',
        ]);

        $this->controller = app(CampoController::class);
    }

    /** Un selector tal y como lo manda el configurador (tras ConvertEmptyStringsToNull). */
    private function selector(array $override = []): array
    {
        return array_merge([
            'id' => null,
            'nombre' => 'Ambito de cobertura',
            'tipo_dato' => 'select',
            'fila' => null, 'columna' => null, 'page' => null, 'font_size' => null,
            'salto_linea_x' => null, 'salto_linea_y' => null,
            'visible' => true, 'obligatorio' => true,
            'grupo' => 'datos_producto', 'copia' => false,
            'opciones' => [
                ['id' => null, 'nombre' => 'España', 'precio' => '132'],
                ['id' => null, 'nombre' => 'Europa', 'precio' => '147'],
            ],
        ], $override);
    }

    private function peticion(array $datos, string $metodo = 'POST'): Request
    {
        return Request::create('/x', $metodo, $datos);
    }

    private function nombresOpciones(string $tabla): array
    {
        return DB::table($tabla)->orderBy('id')->pluck('nombre')->all();
    }

    /** Camino del alta de producto (ProductoController::crearTipoProducto): el caso de GT. */
    public function test_alta_de_producto_enlaza_el_campo_con_su_tabla_de_opciones(): void
    {
        $campo = $this->controller->crearCampoConOpciones($this->selector(), 10287);

        $fila = DB::table('campos')->where('id', $campo->id)->first();

        $this->assertNotNull($fila->opciones, 'El campo se quedó sin enlace a su tabla de opciones (bug original).');
        $this->assertTrue(Schema::hasTable($fila->opciones));
        $this->assertSame(['España', 'Europa'], $this->nombresOpciones($fila->opciones));

        // Lo que consume el formulario de contratación
        $opciones = $this->controller->getOpcionesPorCampo($campo->id)->getData();
        $this->assertCount(2, $opciones);
        $this->assertSame(132.0, (float) $opciones[0]->precio);
    }

    /** Camino del configurador al añadir un selector a un producto que ya existe. */
    public function test_selector_nuevo_en_edicion_crea_enlace_codigo_y_columna_fisica(): void
    {
        $resp = $this->controller->createCampoConOpcionesHTTP($this->peticion($this->selector()), 10287);

        $this->assertSame(201, $resp->getStatusCode());

        $fila = DB::table('campos')->where('nombre', 'Ambito de cobertura')->first();
        $this->assertNotNull($fila->opciones);
        $this->assertSame('ambito_de_cobertura', $fila->nombre_codigo);
        $this->assertTrue(
            Schema::hasColumn('producto_gt', 'ambito_de_cobertura'),
            'Sin columna física el valor elegido se descartaría al contratar la póliza.'
        );

        // Y la respuesta ya trae las opciones (antes devolvía [])
        $this->assertCount(2, $resp->getData()->opciones);
    }

    /** El estado en el que está GT hoy: selector con el enlace en NULL. Es lo que el usuario veía. */
    public function test_guardar_opciones_de_un_selector_sin_enlace_las_persiste(): void
    {
        $id = DB::table('campos')->insertGetId([
            'nombre' => 'Ambito de cobertura', 'nombre_codigo' => 'ambito_de_cobertura',
            'tipo_producto_id' => '10287', 'tipo_dato' => 'select', 'grupo' => 'datos_producto',
            'visible' => 1, 'obligatorio' => 1, 'opciones' => null,
        ]);

        $payload = $this->selector(['id' => $id, 'opciones' => [['id' => null, 'nombre' => 'España', 'precio' => '132']]]);
        $resp = $this->controller->updateCampoConOpciones($this->peticion($payload, 'PUT'), $id);

        $this->assertSame(200, $resp->getStatusCode());
        $tabla = DB::table('campos')->where('id', $id)->value('opciones');
        $this->assertNotNull($tabla, 'El PUT devolvió 200 pero no guardó nada (síntoma original).');
        $this->assertSame(['España'], $this->nombresOpciones($tabla));
    }

    public function test_editar_y_borrar_opciones_de_un_selector_ya_enlazado(): void
    {
        $campo = $this->controller->crearCampoConOpciones($this->selector(), 10287);
        $tabla = DB::table('campos')->where('id', $campo->id)->value('opciones');
        $espana = DB::table($tabla)->where('nombre', 'España')->first();

        // Renombrar una, quitar otra, añadir una nueva
        $payload = $this->selector(['id' => $campo->id, 'opciones' => [
            ['id' => $espana->id, 'nombre' => 'España peninsular', 'precio' => '140'],
            ['id' => null, 'nombre' => 'Mundo', 'precio' => '200'],
        ]]);
        $this->controller->updateCampoConOpciones($this->peticion($payload, 'PUT'), $campo->id);

        $this->assertSame(['España peninsular', 'Mundo'], $this->nombresOpciones($tabla));
        $this->assertSame($tabla, DB::table('campos')->where('id', $campo->id)->value('opciones'), 'No debe crear otra tabla.');
    }

    public function test_una_opcion_con_id_obsoleto_no_se_pierde(): void
    {
        $campo = $this->controller->crearCampoConOpciones($this->selector(), 10287);
        $tabla = DB::table('campos')->where('id', $campo->id)->value('opciones');

        $payload = $this->selector(['id' => $campo->id, 'opciones' => [
            ['id' => 9999, 'nombre' => 'Fantasma', 'precio' => '1'],
        ]]);
        $this->controller->updateCampoConOpciones($this->peticion($payload, 'PUT'), $campo->id);

        $this->assertSame(['Fantasma'], $this->nombresOpciones($tabla));
    }

    public function test_enlace_a_una_tabla_que_ya_no_existe_se_repara(): void
    {
        $id = DB::table('campos')->insertGetId([
            'nombre' => 'Ambito', 'tipo_producto_id' => '10287', 'tipo_dato' => 'select',
            'visible' => 1, 'obligatorio' => 1, 'opciones' => 'opc_perdida',
        ]);

        $payload = $this->selector(['id' => $id, 'opciones' => [['id' => null, 'nombre' => 'España', 'precio' => '1']]]);
        $this->controller->updateCampoConOpciones($this->peticion($payload, 'PUT'), $id);

        $tabla = DB::table('campos')->where('id', $id)->value('opciones');
        $this->assertNotSame('opc_perdida', $tabla);
        $this->assertSame(['España'], $this->nombresOpciones($tabla));
    }

    private function listaDeCampos(): int
    {
        return $this->controller
            ->getByTipoProducto(Request::create('/campos', 'GET', ['id_tipo_producto' => '10287']))
            ->count();
    }

    /** Lo que hacía que, al recargar, el campo recién guardado "no estuviera". */
    public function test_anadir_campos_invalida_la_cache_de_la_lista(): void
    {
        $this->assertSame(0, $this->listaDeCampos()); // calienta la caché

        $resp = $this->controller->addCampos($this->peticion(['campos' => [[
            'nombre' => 'Dato1', 'tipo_dato' => 'text', 'visible' => true, 'obligatorio' => true,
            'grupo' => 'datos_producto', 'copia' => false,
        ]]]), 10287);

        $this->assertSame(201, $resp->getStatusCode());
        $this->assertTrue(Schema::hasColumn('producto_gt', 'dato1'));
        $this->assertSame(1, $this->listaDeCampos(), 'GET /campos devolvió la lista cacheada, sin el campo nuevo.');
    }

    public function test_el_modelo_invalida_la_cache_aunque_el_controlador_no_lo_haga(): void
    {
        $this->assertSame(0, $this->listaDeCampos());

        Campos::create(['nombre' => 'X', 'tipo_dato' => 'text', 'tipo_producto_id' => '10287']);

        $this->assertSame(1, $this->listaDeCampos());
    }
}
