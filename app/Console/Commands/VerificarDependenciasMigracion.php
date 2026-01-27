<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerificarDependenciasMigracion extends Command
{
    protected $signature = 'migrate:verify-dependencies';

    protected $description = 'Verificar que todas las dependencias (sociedades, socios, tipos de pago) estén correctas';

    public function handle()
    {
        $this->info('🔍 Verificando dependencias para la migración...');
        $this->newLine();

        $this->verificarSociedades();
        $this->newLine();

        $this->verificarTiposPago();
        $this->newLine();

        $this->verificarSocios();
        $this->newLine();

        $this->verificarPagos();
        $this->newLine();

        $this->verificarTiposCaceria();
        $this->newLine();

        $this->verificarSubtiposCaceria();

        return 0;
    }

    private function collectionToRows($collection): array
    {
        return collect($collection)->map(fn ($row) => (array) $row)->toArray();
    }

    private function norm(string|null $text): string
    {
        return trim(mb_strtolower($text ?? ''));
    }

    /**
     * Detecta si existe un offset entre IDs MySQL y SQL Server.
     * Ej: si en MySQL hay 26 y en SQL Server está como 10026 => offset 10000
     */
    private function detectarOffsetIds($idsMysql, $idsSqlsrv): ?int
    {
        $idsMysql = collect($idsMysql)->filter()->map(fn ($v) => (int) $v)->values();
        $idsSqlsrv = collect($idsSqlsrv)->filter()->map(fn ($v) => (int) $v)->values();

        if ($idsMysql->isEmpty() || $idsSqlsrv->isEmpty()) {
            return null;
        }

        // Probar offsets típicos
        $offsetsProbar = [10000, 12000, 12600, 12628, 15000, 20000];

        foreach ($offsetsProbar as $offset) {
            $normalizados = $idsSqlsrv->map(fn ($id) => $id >= $offset ? $id - $offset : $id);
            $faltantes = $idsMysql->diff($normalizados);

            // Si el offset reduce muchísimo los faltantes, lo damos por válido
            if ($faltantes->count() < ($idsMysql->count() * 0.2)) {
                return $offset;
            }
        }

        return null;
    }

    private function verificarSociedades()
    {
        $this->info('🏢 VERIFICANDO SOCIEDADES');
        $this->line(str_repeat('=', 60));

        try {
            $sociedadesAntiguas = DB::connection('mysql')
                ->table('sociedades')
                ->select('id_sociedad', 'nombre')
                ->orderBy('id_sociedad')
                ->get();

            $sociedadesNuevas = DB::connection('sqlsrv')
                ->table('sociedad')
                ->select('id', 'nombre')
                ->orderBy('id')
                ->get();

            $this->info("Sociedades en BD antigua: {$sociedadesAntiguas->count()}");
            $this->info("Sociedades en BD nueva: {$sociedadesNuevas->count()}");
            $this->newLine();

            $sociedadesEnSeguros = DB::connection('mysql')
                ->table('seguro_cacerias')
                ->where('borrado', 0)
                ->distinct()
                ->pluck('id_sociedad')
                ->filter()
                ->values();

            $idsNuevas = $sociedadesNuevas->pluck('id')->filter()->values();

            $offset = $this->detectarOffsetIds($sociedadesEnSeguros, $idsNuevas);

            if ($offset !== null) {
                $this->info("ℹ️ Detectado offset en sociedades SQL Server: +{$offset}");
                $idsNuevasNormalizadas = $idsNuevas->map(fn ($id) => (int)$id >= $offset ? (int)$id - $offset : (int)$id);
                $faltantes = $sociedadesEnSeguros->diff($idsNuevasNormalizadas);
            } else {
                $faltantes = $sociedadesEnSeguros->diff($idsNuevas);
            }

            if ($faltantes->isEmpty()) {
                $this->info('✅ Todas las sociedades de seguros existen en la nueva BD (por ID normalizado)');
            } else {
                $this->error('❌ Sociedades faltantes en nueva BD (por ID):');
                foreach ($faltantes as $id) {
                    $nombre = $sociedadesAntiguas->firstWhere('id_sociedad', $id)->nombre ?? 'Desconocida';
                    $seguros = DB::connection('mysql')
                        ->table('seguro_cacerias')
                        ->where('id_sociedad', $id)
                        ->where('borrado', 0)
                        ->count();

                    $this->line("   • ID {$id}: {$nombre} ({$seguros} seguros)");
                }
            }

            $this->newLine();
            $this->info('Verificación por NOMBRE (sociedades usadas en seguros):');

            $nombresAntiguosUsados = $sociedadesAntiguas
                ->whereIn('id_sociedad', $sociedadesEnSeguros)
                ->pluck('nombre')
                ->map(fn ($n) => $this->norm($n))
                ->filter()
                ->unique()
                ->values();

            $nombresNuevos = $sociedadesNuevas
                ->pluck('nombre')
                ->map(fn ($n) => $this->norm($n))
                ->filter()
                ->unique()
                ->values();

            $faltantesPorNombre = $nombresAntiguosUsados->diff($nombresNuevos);

            if ($faltantesPorNombre->isEmpty()) {
                $this->info('✅ Todas las sociedades usadas en seguros existen en SQL Server (por nombre)');
            } else {
                $this->error("❌ Faltan sociedades por nombre: {$faltantesPorNombre->count()}");
                $this->line('Primeras 20: ' . $faltantesPorNombre->take(20)->implode(', '));
            }

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
        }
    }

    private function verificarTiposPago()
    {
        $this->info('💳 VERIFICANDO TIPOS DE PAGO');
        $this->line(str_repeat('=', 60));

        try {
            $tiposPagoEnSeguros = DB::connection('mysql')
                ->table('seguro_cacerias')
                ->where('borrado', 0)
                ->select('tipo_pago', DB::raw('COUNT(*) as total'))
                ->groupBy('tipo_pago')
                ->get();

            $this->info('Valores de tipo_pago en seguros antiguos:');

            $rows = $tiposPagoEnSeguros->map(fn ($t) => [
                $t->tipo_pago ?? 'NULL',
                $t->total
            ])->toArray();

            $this->table(['Tipo Pago', 'Cantidad'], $rows);

            $this->newLine();
            $this->info('Tipos de pago en nueva BD:');

            $tipos = DB::connection('sqlsrv')->table('tipos_pago')->get();
            $this->info("✅ Tabla encontrada: tipos_pago");

            $tabla = $this->collectionToRows($tipos);

            if (!empty($tabla)) {
                $this->table(array_keys($tabla[0]), $tabla);
            }

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
        }
    }

    private function verificarSocios()
    {
        $this->info('👤 VERIFICANDO SOCIOS');
        $this->line(str_repeat('=', 60));

        try {
            $segurosConSocio = DB::connection('mysql')
                ->table('seguro_cacerias')
                ->where('borrado', 0)
                ->whereNotNull('id_socio_asociado')
                ->count();

            $segurosTotal = DB::connection('mysql')
                ->table('seguro_cacerias')
                ->where('borrado', 0)
                ->count();

            $this->info("Seguros con socio asociado: {$segurosConSocio} / {$segurosTotal}");

            if ($segurosConSocio <= 0) {
                $this->info('ℹ️  No hay seguros con socio asociado');
                return;
            }

            $sociosEnSeguros = DB::connection('mysql')
                ->table('seguro_cacerias')
                ->where('borrado', 0)
                ->whereNotNull('id_socio_asociado')
                ->distinct()
                ->pluck('id_socio_asociado')
                ->filter()
                ->values();

            $this->info("IDs de socios únicos en seguros: {$sociosEnSeguros->count()}");

            // SQL Server: socios
            $totalSociosSql = DB::connection('sqlsrv')->table('socios')->count();
            $this->info("✅ Tabla de socios encontrada: socios ({$totalSociosSql} registros)");

            $idsExistentes = DB::connection('sqlsrv')->table('socios')->pluck('id')->filter()->values();

            $offset = $this->detectarOffsetIds($sociosEnSeguros, $idsExistentes);

            if ($offset !== null) {
                $this->info("ℹ️ Detectado offset en socios SQL Server: +{$offset}");

                $idsExistentesNormalizados = $idsExistentes->map(fn ($id) => (int)$id >= $offset ? (int)$id - $offset : (int)$id);
                $faltantes = $sociosEnSeguros->diff($idsExistentesNormalizados);
            } else {
                $faltantes = $sociosEnSeguros->diff($idsExistentes);
            }

            if ($faltantes->isEmpty()) {
                $this->info('✅ Todos los socios referenciados en seguros existen en SQL Server (por ID normalizado)');
            } else {
                $this->error("❌ {$faltantes->count()} IDs de socios NO existen en SQL Server (por ID)");
                $this->line('Primeros 20 IDs faltantes: ' . $faltantes->take(20)->implode(', '));
                $this->warn('⚠️ Ojo: si los socios se migraron con IDs regenerados, esta validación por ID no es fiable.');
            }

            $this->newLine();
            $this->info("ℹ️ Nota: has indicado que en SQL Server los socios empiezan desde 12628 hacia arriba.");
            $this->info("   Por tanto, lo correcto para validar socios es usar un identificador estable (DNI/NIF, email, etc.).");

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
        }
    }

    private function verificarPagos()
    {
        $this->info('💰 VERIFICANDO CAMPO PAGADO');
        $this->line(str_repeat('=', 60));

        try {
            $estadosPago = DB::connection('mysql')
                ->table('seguro_cacerias')
                ->where('borrado', 0)
                ->select('pagado', DB::raw('COUNT(*) as total'))
                ->groupBy('pagado')
                ->get();

            $this->info('Valores del campo "pagado" en seguros:');

            $rows = $estadosPago->map(fn ($p) => [
                $p->pagado,
                $p->total
            ])->toArray();

            $this->table(['Pagado', 'Cantidad'], $rows);

            $this->newLine();
            $this->warn('⚠️  IMPORTANTE: El campo "pagado" en BD antigua parece ser un flag (0/1)');
            $this->warn('   pero "pago_id" en BD nueva es una Foreign Key a tabla de pagos.');
            $this->line('   Opciones:');
            $this->line('   1. Dejar pago_id como NULL siempre');
            $this->line('   2. Crear registros de pago y vincularlos');
            $this->line('   3. Usar el valor de "pagado" como flag y no llenar pago_id');

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
        }
    }

    private function verificarTiposCaceria()
    {
        $this->info('🦌 VERIFICANDO TIPOS DE CACERÍA');
        $this->line(str_repeat('=', 60));

        try {
            $tiposAntiguos = DB::connection('mysql')
                ->table('tipo_caceria')
                ->get();

            $this->info("Tipos de cacería en BD antigua: {$tiposAntiguos->count()}");

            if ($tiposAntiguos->count() > 0) {
                $campos = array_keys((array) $tiposAntiguos->first());
                $rows = $this->collectionToRows($tiposAntiguos);
                $this->table($campos, $rows);
            }

            $this->newLine();

            $tiposUsados = DB::connection('mysql')
                ->table('seguro_cacerias')
                ->where('borrado', 0)
                ->whereNotNull('tipo_caceria')
                ->select('tipo_caceria', DB::raw('COUNT(*) as total'))
                ->groupBy('tipo_caceria')
                ->get();

            $this->info('Tipos de cacería usados en seguros:');

            $tabla = [];
            foreach ($tiposUsados as $tipo) {
                $info = $tiposAntiguos->firstWhere('id_tipo_caceria', $tipo->tipo_caceria);

                $nombreTipo =
                    $info->nombre_tipo_caceria
                    ?? $info->nombre
                    ?? $info->descripcion
                    ?? $info->tipo
                    ?? 'Desconocido';

                $tabla[] = [
                    $tipo->tipo_caceria,
                    $nombreTipo,
                    $tipo->total
                ];
            }
            $this->table(['ID', 'Nombre', 'Cantidad'], $tabla);


            $this->table(['ID', 'Nombre', 'Cantidad'], $tabla);

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
        }
    }

    private function verificarSubtiposCaceria()
    {
        $this->info('🎯 VERIFICANDO SUBTIPOS DE CACERÍA');
        $this->line(str_repeat('=', 60));

        try {
            $subtiposUsados = DB::connection('mysql')
                ->table('seguro_cacerias')
                ->where('borrado', 0)
                ->whereNotNull('subtipo_caceria')
                ->select('subtipo_caceria', DB::raw('COUNT(*) as total'))
                ->groupBy('subtipo_caceria')
                ->orderByDesc('total')
                ->limit(20)
                ->get();

            $this->info('Top 20 subtipos de cacería más usados:');

            try {
                $subtipos = DB::connection('mysql')
                    ->table('subtipo_caceria')
                    ->get()
                    ->keyBy('id_subtipo_caceria');

                $tabla = [];
                foreach ($subtiposUsados as $subtipo) {
                    $info = $subtipos->get($subtipo->subtipo_caceria);

                    $tabla[] = [
                        $subtipo->subtipo_caceria,
                        $info->nombre_subtipo_caceria ?? 'Desconocido',
                        $subtipo->total
                    ];
                }

                $this->table(['ID', 'Nombre/Descripción', 'Cantidad'], $tabla);


                $this->table(['ID', 'Nombre/Descripción', 'Cantidad'], $tabla);

            } catch (\Exception $e) {
                $rows = $subtiposUsados->map(fn ($s) => [
                    $s->subtipo_caceria,
                    $s->total
                ])->toArray();

                $this->table(['Subtipo ID', 'Cantidad'], $rows);
            }

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
        }
    }
}
