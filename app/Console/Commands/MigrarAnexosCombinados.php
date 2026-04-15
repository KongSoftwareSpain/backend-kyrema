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
    protected $signature = 'migrate:anexos-combinados';

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
            ->select('seguros_combinados.*', 'socios.dni as socio_dni')
            ->get()
            ->keyBy('id_seguro');

        $productosNew = DB::connection($newConnection)
            ->table('producto_k')
            ->get();
            
        $productosNewByPoliza = $productosNew->keyBy('codigo_producto');
        $productosNewByDni = $productosNew->groupBy('dni');

        $migradosAcompaniantes = 0;
        foreach ($acompaniantes as $ac) {
            $oldSeguro = $segurosOld->get($ac->id_seguro_combinado);
            if (!$oldSeguro) continue;
            
            // Try matching by policy number first
            $newProducto = $productosNewByPoliza->get($oldSeguro->poliza_seguro);
            
            // Fallback: search by DNI and Date
            $dni = trim($oldSeguro->socio_dni);
            if (!$newProducto && $dni) {
                $potentials = $productosNewByDni->get($dni);
                if ($potentials) {
                    $oldStart = Carbon::parse($oldSeguro->fecha_inicio)->toDateString();
                    $newProducto = $potentials->first(function($p) use ($oldStart) {
                        return Carbon::parse($p->fecha_de_inicio)->toDateString() === $oldStart;
                    });
                }
            }
            
            if (!$newProducto) continue;
            
            // Check if it already exists
            $exists = DB::connection($newConnection)
                ->table('anexos_ka')
                ->where('producto_id', $newProducto->id)
                ->where('nombre_socio', $ac->nombre)
                ->where('dni', $ac->dni)
                ->exists();
                
            if ($exists) continue;

            $fechaStr = $this->sanitizeDate($ac->fecha_nacimiento);

            DB::connection($newConnection)->table('anexos_ka')->insert([
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
            ]);
            $migradosAcompaniantes++;
        }
        $this->info("Acompañantes migrados correctamente: " . $migradosAcompaniantes);

        $migradosPerros = 0;
        foreach ($perros as $pr) {
            $oldSeguro = $segurosOld->get($pr->id_seguro);
            if (!$oldSeguro) continue;
            
            // Try matching by policy number first
            $newProducto = $productosNewByPoliza->get($oldSeguro->poliza_seguro);

            // Fallback: search by DNI and Date
            $dni = trim($oldSeguro->socio_dni);
            if (!$newProducto && $dni) {
                $potentials = $productosNewByDni->get($dni);
                if ($potentials) {
                    $oldStart = Carbon::parse($oldSeguro->fecha_inicio)->toDateString();
                    $newProducto = $potentials->first(function($p) use ($oldStart) {
                        return Carbon::parse($p->fecha_de_inicio)->toDateString() === $oldStart;
                    });
                }
            }

            if (!$newProducto) continue;
            
            // Check if exists
            $exists = DB::connection($newConnection)
                ->table('anexos_ap')
                ->where('producto_id', $newProducto->id)
                ->where('nombre_perro', $pr->nombre)
                ->where('nº_de_chip', $pr->microchip)
                ->exists();
                
            if ($exists) continue;

            DB::connection($newConnection)->table('anexos_ap')->insert([
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
            ]);
            $migradosPerros++;
        }
        $this->info("Perros migrados correctamente: " . $migradosPerros);
        
        $this->info("Migración completada.");
    }
}
