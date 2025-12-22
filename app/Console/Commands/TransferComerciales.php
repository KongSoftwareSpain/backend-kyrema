<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * ============================================================================
 * COMANDO: TransferComerciales
 * ============================================================================
 *
 * DESCRIPCIÓN:
 * Este comando migra los usuarios del sistema antiguo (tabla 'users' en MySQL)
 * hacia la tabla de comerciales del sistema nuevo ('comercial' en SQL Server).
 *
 * ESPECIFICACIONES CRÍTICAS:
 * 1. UNICIDAD: Se utiliza el 'email' como clave de búsqueda para evitar
 *    duplicar comerciales si se ejecuta el comando varias veces.
 * 2. RESOLUCIÓN DE SOCIEDAD: Cada usuario en el sistema antiguo solía tener
 *    una o varias sociedades asignadas. El script busca la primera sociedad
 *    válida en el destino para vincular al comercial.
 * 3. FALLBACKS: Si un email es inválido, se genera uno temporal. Si no
 *    tiene nickname, se usa la parte local del email.
 *
 * MODO DE USO:
 * php artisan transfer:comerciales
 * ============================================================================
 */
class TransferComerciales extends Command
{
    /**
     * @var string Firma del comando.
     */
    protected $signature = 'transfer:comerciales';

    /**
     * @var string Descripción del comando.
     */
    protected $description = 'Migración IDempotente de users → comerciales (deduplicada por email)';

    /**
     * Lógica de ejecución.
     */
    public function handle()
    {
        $this->info('====================================================');
        $this->info(' INICIO MIGRACIÓN DE COMERCIALES (ATOPE)');
        $this->info('====================================================');

        // Fecha base para registros de auditoría
        $nowSql = Carbon::now()->format('Y-m-d H:i:s');

        /**
         * --------------------------------------------------------------------
         * 1. VERIFICACIÓN DE INTEGRIDAD
         * --------------------------------------------------------------------
         * Comprobamos que las 4 tablas (2 origen, 2 destino) están accesibles.
         */
        try {
            DB::connection('mysql')->table('users')->count();
            DB::connection('mysql')->table('sociedades')->count();
            DB::connection('sqlsrv')->table('comercial')->count();
            DB::connection('sqlsrv')->table('sociedad')->count();

            $this->info('✔ Conexiones y tablas verificadas satisfactoriamente');
        } catch (\Throwable $e) {
            $this->error('✘ Error de conexión. Abortando...');
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        /**
         * --------------------------------------------------------------------
         * 2. OBTENCIÓN DE DATOS ORIGEN
         * --------------------------------------------------------------------
         */
        $users = DB::connection('mysql')->table('users')->get();
        $this->info('✔ Usuarios encontrados en MySQL: ' . $users->count());
        $this->newLine();

        /**
         * --------------------------------------------------------------------
         * 3. PROCESAMIENTO UNO A UNO
         * --------------------------------------------------------------------
         */
        foreach ($users as $user) {

            $this->line("Procesando USER: [ID={$user->id_user}] [Email={$user->email}]");

            try {
                /**
                 * 3.1 VALIDACIÓN Y LIMPIEZA DE EMAIL
                 * SQL Server requiere un email único. Si el origen es basura,
                 * generamos un email ficticio pero válido sintácticamente.
                 */
                $email = filter_var($user->email, FILTER_VALIDATE_EMAIL)
                    ? $user->email
                    : "invalid_{$user->id_user}@example.com";

                /**
                 * 3.2 CHEQUEO DE EXISTENCIA (DEDUPLICACIÓN)
                 */
                $existingId = DB::connection('sqlsrv')
                    ->table('comercial')
                    ->where('email', $email)
                    ->value('id');

                if ($existingId) {
                    $this->warn("  ⚠ Saltado: Ya existe comercial con este email (ID Destino: {$existingId})");
                    Log::channel('transfer_comerciales')->info('COMERCIAL_YA_EXISTE', [
                        'user_origen' => $user->id_user,
                        'email' => $email
                    ]);
                    continue;
                }

                /**
                 * 3.3 RESOLUCIÓN DE LA SOCIEDAD (El puente)
                 * Buscamos qué sociedades tenía este usuario en MySQL para
                 * encontrar su equivalente en SQL Server por 'codigo_sociedad'.
                 */
                $sociedadesOrigen = DB::connection('mysql')
                    ->table('sociedades')
                    ->where('id_user', $user->id_user)
                    ->pluck('codigo_sociedad');

                if ($sociedadesOrigen->isEmpty()) {
                    $this->warn('  ⚠ Saltado: Usuario sin sociedades en el origen.');
                    Log::channel('transfer_comerciales')->warning('USER_SIN_SOCIEDADES', ['user_id' => $user->id_user]);
                    continue;
                }

                $idSociedadDestino = null;
                $codigoUsado = null;

                // Recorremos sus códigos hasta encontrar uno que exista en el destino
                foreach ($sociedadesOrigen as $codigoSociedad) {
                    if (!$codigoSociedad)
                        continue;

                    $idSociedadDestino = DB::connection('sqlsrv')
                        ->table('sociedad')
                        ->where('codigo_sociedad', $codigoSociedad)
                        ->value('id');

                    if ($idSociedadDestino) {
                        $codigoUsado = $codigoSociedad;
                        break;
                    }
                }

                if (!$idSociedadDestino) {
                    $this->warn('  ⚠ Saltado: No se localizó ninguna de sus sociedades en el destino.');
                    Log::channel('transfer_comerciales')->warning('USER_SIN_SOCIEDAD_DESTINO', ['user_id' => $user->id_user]);
                    continue;
                }

                /**
                 * 3.4 PREPARACIÓN DE CAMPOS
                 */
                $nombre = trim($user->nickname ?: '');
                if ($nombre === '') {
                    // Si no hay apodo, usamos la primera parte del email
                    $nombre = Str::before($email, '@');
                }

                /**
                 * 3.5 INSERCIÓN FINAL
                 * Creamos el comercial con los datos mapeados.
                 */
                $comercialId = DB::connection('sqlsrv')
                    ->table('comercial')
                    ->insertGetId([
                        'nombre' => Str::limit($nombre, 255),
                        'id_sociedad' => $idSociedadDestino,
                        'usuario' => Str::limit($nombre, 255),
                        'email' => $email,
                        'contraseña' => $user->pass, // Mantenemos password original
                        'dni' => 'NO_DNI_' . $user->id_user, // Valor dummy obligatorio
                        'fecha_alta' => DB::raw("CONVERT(datetime, '1900-01-01', 120)"),
                        'created_at' => DB::raw("CONVERT(datetime, '{$nowSql}', 120)"),
                        'updated_at' => DB::raw("CONVERT(datetime, '{$nowSql}', 120)"),
                        'responsable' => 1,
                    ], 'id');

                $this->info("  ✔ Comercial creado con éxito! ID: {$comercialId} (Vinculado a Soc: {$codigoUsado})");

                Log::channel('transfer_comerciales')->info('COMERCIAL_CREADO', [
                    'user_id' => $user->id_user,
                    'new_id' => $comercialId,
                    'email' => $email
                ]);

            } catch (\Throwable $e) {
                /**
                 * ERROR INDIVIDUAL
                 */
                $this->error('  ✘ Error crítico procesando este usuario');
                Log::channel('transfer_comerciales')->error('ERROR_COMERCIAL_FAIL', [
                    'user_id' => $user->id_user,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->newLine();
        $this->info('====================================================');
        $this->info(' MIGRACIÓN DE COMERCIALES FINALIZADA CORRECTAMENTE');
        $this->info('====================================================');

        return self::SUCCESS;
    }
}
