<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerificarMapeoComerciales extends Command
{
    protected $signature = 'migrate:verify-comerciales 
                            {--export-csv : Exportar resultado a CSV}
                            {--show-unmapped : Mostrar solo los no mapeados}';

    protected $description = 'Verificar mapeo de users antiguos → comerciales nuevos (solo por EMAIL)';

    public function handle()
    {
        $this->info('🔍 Verificando mapeo de users → comerciales...');
        $this->warn('⚠️  Mapeo solo posible por EMAIL (único campo común)');
        $this->newLine();

        // Obtener datos de ambas BDs
        $usersAntiguos = $this->obtenerUsersAntiguos();
        $comercialesNuevos = $this->obtenerComercialesNuevos();

        $this->info("📊 Users en BD antigua: {$usersAntiguos->count()}");
        $this->info("📊 Comerciales en BD nueva: {$comercialesNuevos->count()}");
        $this->newLine();

        // Realizar mapeo
        $resultados = [];
        $mapeados = 0;
        $noMapeados = 0;

        foreach ($usersAntiguos as $user) {
            $comercial = $this->buscarPorEmail($user, $comercialesNuevos);
            
            if ($comercial) {
                $mapeados++;
                $resultados[] = [
                    'id_antiguo' => $user->id_user,
                    'nickname' => $user->nickname ?? 'N/A',
                    'email_antiguo' => $user->email ?? 'N/A',
                    'rol' => $user->id_rol,
                    'id_nuevo' => $comercial->id,
                    'nombre_nuevo' => $comercial->nombre,
                    'email_nuevo' => $comercial->email,
                    'estado' => '✅',
                ];
            } else {
                $noMapeados++;
                $resultados[] = [
                    'id_antiguo' => $user->id_user,
                    'nickname' => $user->nickname ?? 'N/A',
                    'email_antiguo' => $user->email ?? 'N/A',
                    'rol' => $user->id_rol,
                    'id_nuevo' => 'SIN MAPEAR',
                    'nombre_nuevo' => '-',
                    'email_nuevo' => '-',
                    'estado' => '❌',
                ];
            }
        }

        // Mostrar resultados
        $this->info("✅ Users mapeados por email: {$mapeados}");
        $this->warn("❌ Users sin mapear: {$noMapeados}");
        $this->newLine();

        // Filtrar si solo quiere ver no mapeados
        if ($this->option('show-unmapped')) {
            $resultados = array_filter($resultados, fn($r) => $r['estado'] === '❌');
        }

        // Mostrar tabla
        if (count($resultados) > 0 && $this->confirm('¿Deseas ver el detalle?', true)) {
            $this->table(
                ['ID Ant.', 'Nickname', 'Email Ant.', 'Rol', 'ID Nuevo', 'Nombre Nuevo', 'Email Nuevo', 'Estado'],
                $resultados
            );
        }

        // Exportar a CSV si se solicita
        if ($this->option('export-csv')) {
            $this->exportarCSV($resultados);
        }

        // Análisis
        $this->newLine();
        $this->mostrarAnalisis($mapeados, $noMapeados, $usersAntiguos->count());

        // Verificar seguros sin comercial
        $this->verificarSegurosHuerfanos();

        return 0;
    }

    private function obtenerUsersAntiguos()
    {
        try {
            return DB::connection('mysql')
                ->table('users')
                ->select('id_user', 'nickname', 'email', 'id_rol')
                ->get();
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return collect([]);
        }
    }

    private function obtenerComercialesNuevos()
    {
        try {
            return DB::connection('sqlsrv')
                ->table('comercial')
                ->select('id', 'nombre', 'usuario', 'email', 'dni', 'telefono')
                ->get();
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return collect([]);
        }
    }

    private function buscarPorEmail($user, $comerciales)
    {
        if (!$user->email || trim($user->email) === '') {
            return null;
        }

        $emailUser = strtolower(trim($user->email));

        foreach ($comerciales as $comercial) {
            if (!$comercial->email) continue;
            
            $emailComercial = strtolower(trim($comercial->email));
            
            if ($emailUser === $emailComercial) {
                return $comercial;
            }
        }

        return null;
    }

    /**
     * Verificar cuántos seguros_cacerias tienen id_emisor sin mapeo
     */
    private function verificarSegurosHuerfanos(): void
    {
        $this->newLine();
        $this->info('🔍 Verificando seguros sin comercial mapeado...');

        try {
            // Obtener IDs de emisores únicos en seguros_cacerias
            $emisoresEnSeguros = DB::connection('mysql')
                ->table('seguro_cacerias')
                ->where('borrado', 0)
                ->whereNotNull('id_emisor')
                ->distinct()
                ->pluck('id_emisor');

            // Obtener IDs de users que existen
            $idsUsersExistentes = DB::connection('mysql')
                ->table('users')
                ->pluck('id_user');

            // Obtener emails de users que se pueden mapear
            $emailsUsers = DB::connection('mysql')
                ->table('users')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')
                ->map(fn($e) => strtolower(trim($e)));

            $emailsComerciales = DB::connection('sqlsrv')
                ->table('comercial')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')
                ->map(fn($e) => strtolower(trim($e)));

            // Contar seguros con problema
            $segurosConEmisorInexistente = 0;
            $segurosConEmisorSinMapear = 0;

            foreach ($emisoresEnSeguros as $idEmisor) {
                if (!$idsUsersExistentes->contains($idEmisor)) {
                    // El ID del emisor no existe en la tabla users
                    $countSeguros = DB::connection('mysql')
                        ->table('seguro_cacerias')
                        ->where('id_emisor', $idEmisor)
                        ->where('borrado', 0)
                        ->count();
                    $segurosConEmisorInexistente += $countSeguros;
                } else {
                    // El emisor existe, pero ¿tiene email que se pueda mapear?
                    $emailEmisor = DB::connection('mysql')
                        ->table('users')
                        ->where('id_user', $idEmisor)
                        ->value('email');
                    
                    if (!$emailEmisor || !$emailsComerciales->contains(strtolower(trim($emailEmisor)))) {
                        $countSeguros = DB::connection('mysql')
                            ->table('seguro_cacerias')
                            ->where('id_emisor', $idEmisor)
                            ->where('borrado', 0)
                            ->count();
                        $segurosConEmisorSinMapear += $countSeguros;
                    }
                }
            }

            $totalSeguros = DB::connection('mysql')
                ->table('seguro_cacerias')
                ->where('borrado', 0)
                ->count();

            $this->table(
                ['Concepto', 'Cantidad'],
                [
                    ['Total seguros', $totalSeguros],
                    ['Seguros con emisor inexistente', $segurosConEmisorInexistente],
                    ['Seguros con emisor sin mapear', $segurosConEmisorSinMapear],
                    ['Total seguros afectados', $segurosConEmisorInexistente + $segurosConEmisorSinMapear],
                ]
            );

            if ($segurosConEmisorInexistente + $segurosConEmisorSinMapear > 0) {
                $this->warn('⚠️  Estos seguros usarán el comercial ID por defecto en la migración');
            } else {
                $this->info('✅ Todos los seguros tienen emisor mapeado');
            }

        } catch (\Exception $e) {
            $this->error("Error verificando seguros: {$e->getMessage()}");
        }
    }

    private function exportarCSV(array $resultados): void
    {
        $filename = storage_path('app/mapeo_users_comerciales_' . date('Y-m-d_His') . '.csv');
        
        $file = fopen($filename, 'w');
        
        // BOM para UTF-8 en Excel
        fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Encabezados
        fputcsv($file, [
            'ID Antiguo', 
            'Nickname', 
            'Email Antiguo', 
            'Rol',
            'ID Nuevo', 
            'Nombre Nuevo', 
            'Email Nuevo', 
            'Estado'
        ], ';');
        
        // Datos
        foreach ($resultados as $row) {
            fputcsv($file, array_values($row), ';');
        }
        
        fclose($file);
        
        $this->info("✅ CSV exportado a: {$filename}");
    }

    private function mostrarAnalisis(int $mapeados, int $noMapeados, int $total): void
    {
        $porcentaje = $total > 0 ? round(($mapeados / $total) * 100, 2) : 0;
        
        $this->info('📊 Análisis de Cobertura:');
        $this->line("   • Cobertura: {$porcentaje}%");
        
        if ($porcentaje >= 95) {
            $this->info('   ✅ Excelente cobertura de mapeo');
        } elseif ($porcentaje >= 80) {
            $this->warn('   ⚠️  Buena cobertura, pero revisa los no mapeados');
        } else {
            $this->error('   ❌ Cobertura baja - muchos seguros usarán comercial por defecto');
        }

        if ($noMapeados > 0) {
            $this->newLine();
            $this->warn('💡 Razones por las que no se mapean:');
            $this->line('   • Email no existe en la tabla users antigua');
            $this->line('   • Email cambió al migrar a la nueva BD');
            $this->line('   • Usuario no es realmente un comercial');
            $this->newLine();
            $this->info('💡 Soluciones:');
            $this->line('   1. Usar --default-comercial=X en la migración');
            $this->line('   2. Actualizar emails en alguna BD para que coincidan');
            $this->line('   3. Crear mapeo manual en el código si conoces las correspondencias');
        }
    }
}