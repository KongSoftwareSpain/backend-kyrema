<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class MigrarAnexosCombinados extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:anexos-combinados
                            {--corte=2026-10-20 : Solo anexos de seguros vigentes a esta fecha (expiration_date >= corte)}
                            {--dry-run : Cuenta lo que se insertaría sin escribir nada}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrar anexos (Acompañantes y Perros) de la bd antigua MySQL a ANEXOS_KA y ANEXOS_Ap';

    private function sanitizeDate($dateString) {
        if (!$dateString) return null;
        try {
            $date = Carbon::parse($dateString);
            if ($date->year < 1753) return null; // SQL Server minimum datetime
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Iniciando migración de Anexos Combinados...");

        $oldConnection = 'mysql';
        $newConnection = 'sqlsrv';

        // 1. Get all Acompañantes from old DB that are not deleted
        $acompaniantes = DB::connection($oldConnection)
            ->table('seguro_acompaniantes')
            ->where('borrado', 0)
            ->get();

        $this->info("Acompañantes encontrados: " . count($acompaniantes));

        // 2. Get all Perros from old DB that are not deleted and are type 2
        $perros = DB::connection($oldConnection)
            ->table('seguro_perros')
            ->where('id_tipo_seguro_perros', 2)
            ->where('borrado', 0)
            ->get();
            
        $this->info("Perros encontrados: " . count($perros));

        // We need to map old id_seguro_combinado/id_seguro to NEW producto_id
        $segurosOld = DB::connection($oldConnection)
            ->table('seguros_combinados')
            ->join('socios', 'seguros_combinados.id_socio', '=', 'socios.id_socio')
            // Solo seguros vigentes a la fecha de corte (los anexos de los demás se saltan en el bucle: no hay $oldSeguro)
            ->where('seguros_combinados.borrado', 0)
            ->where('seguros_combinados.finalizado', 0)
            ->where('seguros_combinados.expiration_date', '>=', $this->option('corte') . ' 00:00:00')
            ->select('seguros_combinados.*', 'socios.dni as socio_dni')
            ->get()
            ->keyBy('id_seguro');

        $productosNew = DB::connection($newConnection)
            ->table('producto_k')
            ->get();
            
        $productosNewByPoliza = $productosNew->keyBy('codigo_producto');
        $productosNewByDni = $productosNew->groupBy('dni');

        $erroresAnexos = 0;
        $migradosAcompaniantes = 0;
        foreach ($acompaniantes as $ac) {
          try {
            $oldSeguro = $segurosOld->get($ac->id_seguro_combinado);
            if (!$oldSeguro) continue;
            
            // Try matching by policy number first
            $newProducto = $productosNewByPoliza->get($oldSeguro->poliza_seguro);
            
            // Solo se cruza por número de póliza: el respaldo por DNI y fecha podía colgar anexos de una venta ajena.
            
            if (!$newProducto) continue;
            
            // Check if it already exists
            $exists = DB::connection($newConnection)
                ->table('anexos_ka')
                ->where('producto_id', $newProducto->id)
                ->where('nombre_socio', $ac->nombre)
                ->where('dni', $ac->dni)
                ->exists();
                
            if ($exists) continue;

            if ($this->option('dry-run')) {
                $migradosAcompaniantes++;
                continue;
            }

            $fechaStr = $this->sanitizeDate($ac->fecha_nacimiento);

            DB::connection($newConnection)->table('anexos_ka')->insert($this->ajustarLongitudes('anexos_ka', [
                'producto_id' => $newProducto->id,
                'nombre_socio' => $ac->nombre,
                'dni' => $ac->dni,
                'dirección' => $ac->direccion,
                'población' => $ac->poblacion,
                'codigo_postal' => $ac->codigo_postal,
                'provincia' => $ac->provincia,
                'telefono' => $ac->telefono,
                'fecha_de_nacimiento' => $fechaStr ? DB::raw("CONVERT(datetime, '{$fechaStr}', 120)") : null,
                'precio_total' => $ac->total_pagado ?? 0,
                'precio_base' => $ac->total_pagado ?? 0,
                'blob_name' => '',
                'created_at' => DB::raw("GETDATE()"),
                'updated_at' => DB::raw("GETDATE()")
            ]));
            $migradosAcompaniantes++;
          } catch (\Throwable $e) {
            $erroresAnexos++;
            $this->error('✘ Error en acompañante del seguro antiguo ' . $ac->id_seguro_combinado . ': ' . $e->getMessage());
          }
        }
        $this->info("Acompañantes migrados correctamente: " . $migradosAcompaniantes);

        $migradosPerros = 0;
        foreach ($perros as $pr) {
          try {
            $oldSeguro = $segurosOld->get($pr->id_seguro);
            if (!$oldSeguro) continue;
            
            // Try matching by policy number first
            $newProducto = $productosNewByPoliza->get($oldSeguro->poliza_seguro);

            // Solo se cruza por número de póliza: el respaldo por DNI y fecha podía colgar anexos de una venta ajena.

            if (!$newProducto) continue;
            
            // Check if exists
            $exists = DB::connection($newConnection)
                ->table('anexos_ap')
                ->where('producto_id', $newProducto->id)
                ->where('nombre_perro', $pr->nombre)
                ->where('nº_de_chip', $pr->microchip)
                ->exists();
                
            if ($exists) continue;

            if ($this->option('dry-run')) {
                $migradosPerros++;
                continue;
            }

            DB::connection($newConnection)->table('anexos_ap')->insert($this->ajustarLongitudes('anexos_ap', [
                'producto_id' => $newProducto->id,
                'nombre_perro' => $pr->nombre,
                'raza' => $pr->raza,
                'nº_de_chip' => $pr->microchip,
                'nombre_completo_propietario' => $pr->propietario,
                'dni_propietario' => $pr->dni_propietario,
                'precio_total' => $pr->total_pagado ?? 0,
                'precio_base' => $pr->total_pagado ?? 0,
                'blob_name' => '',
                'created_at' => DB::raw("GETDATE()"),
                'updated_at' => DB::raw("GETDATE()")
            ]));
            $migradosPerros++;
          } catch (\Throwable $e) {
            $erroresAnexos++;
            $this->error('✘ Error en perro del seguro antiguo ' . $pr->id_seguro . ': ' . $e->getMessage());
          }
        }
        $this->info("Perros migrados correctamente: " . $migradosPerros);
        
        $this->info("Migración completada. Errores: " . $erroresAnexos);

        return $erroresAnexos > 0 ? 1 : 0;
    }

    /** @var array<string, array<string,int>> */
    private $limitesColumnas = [];

    /**
     * Longitud maxima de cada columna de texto de la tabla nueva (INFORMATION_SCHEMA).
     */
    private function limitesDe(string $tabla): array
    {
        if (!isset($this->limitesColumnas[$tabla])) {
            $this->limitesColumnas[$tabla] = DB::connection('sqlsrv')
                ->table('INFORMATION_SCHEMA.COLUMNS')
                ->where('TABLE_NAME', $tabla)
                ->where('CHARACTER_MAXIMUM_LENGTH', '>', 0)
                ->pluck('CHARACTER_MAXIMUM_LENGTH', 'COLUMN_NAME')
                ->all();
        }

        return $this->limitesColumnas[$tabla];
    }

    /**
     * Recorta los textos que no caben en su columna (p. ej. una direccion con un correo pegado de 300+ caracteres)
     * en lugar de perder todo el anexo por "los datos binarios o de la cadena se truncan".
     */
    private function ajustarLongitudes(string $tabla, array $fila): array
    {
        $limites = $this->limitesDe($tabla);

        foreach ($fila as $columna => $valor) {
            if (is_string($valor) && isset($limites[$columna]) && mb_strlen($valor) > $limites[$columna]) {
                $fila[$columna] = mb_substr($valor, 0, (int) $limites[$columna]);
            }
        }

        return $fila;
    }
}
