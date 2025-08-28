<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TransferSocios extends Command
{
    protected $signature = 'transfer:socios {--chunk=500} {--comercial=1} {--categoria=3}';
    protected $description = 'Insertar socios de MySQL a SQL Server (sin actualizar existentes), normalizando nombre/sexo y creando vínculo comercial';

    public function handle()
    {
        // Comprobaciones rápidas
        try {
            $mysqlCount = DB::connection('mysql')->table('socios')->count();
            $this->info("MySQL OK. Registros origen: {$mysqlCount}");
        } catch (\Throwable $e) {
            $this->error("Error MySQL: ".$e->getMessage());
            return self::FAILURE;
        }
        try {
            $sqlsrvCount = DB::connection('sqlsrv')->table('socios')->count();
            $this->info("SQL Server OK. Registros destino (previos): {$sqlsrvCount}");
        } catch (\Throwable $e) {
            $this->error("Error SQL Server: ".$e->getMessage());
            return self::FAILURE;
        }

        $chunk       = (int) $this->option('chunk');
        $comercialId = (int) $this->option('comercial') ?: 1;
        $categoriaId = (int) $this->option('categoria') ?: 3; // 3 - Kyrema

        DB::connection('mysql')->table('socios')
            ->orderBy('id_socio')
            ->chunk($chunk, function ($rows) use ($comercialId) {

                foreach ($rows as $row) {
                    [$nombre, $ap1, $ap2] = $this->parseNombreCompleto((string)($row->nombre_socio ?? ''));
                    $sexo = $this->mapSexo($row->sexo);

                    $nowIso = Carbon::now()->format('Y-m-d\TH:i:s');

                    $payload = [
                        'dni'                 => $row->dni ?: null,
                        'nombre_socio'        => $nombre ?: null,
                        'apellido_1'          => $ap1 ?: null,
                        'apellido_2'          => $ap2 ?: null,
                        'email'               => $row->email ?: null,
                        'telefono'            => $row->telefono ?: null,
                        'fecha_de_nacimiento' => $this->toDateOrNull($row->fecha_nacimiento ?? null),
                        'sexo'                => $sexo, // 'M', 'F' o null
                        'direccion'           => $row->direccion ?: null,
                        'poblacion'           => $row->poblacion ?: null,
                        'provincia'           => $row->provincia ?: null,
                        'codigo_postal'       => $row->codigo_postal ?: null,
                        'categoria_id'        => $row->id_sociedad ?? null, // ajusta si procede
                        'created_at'          => $nowIso,
                        'updated_at'          => $nowIso,
                    ];

                    try {
                        $sqlsrv = DB::connection('sqlsrv');

                        // --- SOLO INSERTAR: si existe por dni/email, saltar ---
                        $exists = null;
                        if (!empty($row->dni)) {
                            $exists = $sqlsrv->table('socios')->where('dni', $row->dni)->first();
                        }
                        if (!$exists && !empty($row->email)) {
                            $exists = $sqlsrv->table('socios')->where('email', $row->email)->first();
                        }

                        if ($exists) {
                            $this->line("Saltado id_socio origen {$row->id_socio} (ya existe en destino).");
                            continue;
                        }

                        // Insertar socio
                        $newId = (int) $sqlsrv->table('socios')->insertGetId($payload, 'id');

                        // Insertar vínculo comercial SOLO si no existe
                        $pivotExists = $sqlsrv->table('socios_comerciales')
                            ->where('id_socio', $newId)
                            ->where('id_comercial', $comercialId)
                            ->exists();

                        if (!$pivotExists) {
                            $sqlsrv->table('socios_comerciales')->insert([
                                'id_socio'     => $newId,
                                'id_comercial' => $comercialId,
                                'created_at'   => $nowIso,
                                'updated_at'   => $nowIso,
                            ]);
                        }

                    } catch (\Throwable $e) {
                        $this->error("Fallo insertando id_socio origen {$row->id_socio}: ".$e->getMessage());
                    }
                }
            });

        $this->info('Transferencia finalizada (solo inserts, sin updates).');
        return self::SUCCESS;
    }

    /** Normaliza SEXO (H/M/null). 'M' ambiguo en origen => null. */
    private function mapSexo($raw)
    {
        if ($raw === null) return null;
        $s = Str::lower(trim((string)$raw));

        $hombres = ['varon','v','h','hombre','var','masculino','masc'];
        $mujeres = ['mujer','f','femenino','fem','female','fémina','femenina'];

        if (in_array($s, $hombres, true)) return 'M';
        if (in_array($s, $mujeres, true)) return 'F';

        if ($s === 'm') {
            $this->warn("Sexo ambiguo 'M' -> NULL");
            return null;
        }
        return null;
    }

    /** Seguro para fechas: devuelve 'Y-m-d' o null si es inválida. */
    private function toDateOrNull($val): ?string
    {
        if (!$val) return null;
        try {
            return Carbon::parse($val)->format('Y-m-d');
        } catch (\Throwable $e) {
            $this->warn("Fecha inválida '{$val}' -> NULL");
            return null;
        }
    }

    /** Parte nombre completo en [nombre, apellido_1, apellido_2] (heurísticas ES/PT). */
    private function parseNombreCompleto(string $full): array
    {
        $full = trim(preg_replace('/\s+/', ' ', $full));
        if ($full === '') return [null, null, null];

        $tokens = explode(' ', $full);
        if (count($tokens) === 1) return [$tokens[0], null, null];

        $particles = ['de','del','la','las','los','san','santa','da','das','do','dos','van','von','y'];

        $takeSurname = function(array $parts) use ($particles) {
            $surnameParts = [];
            while (!empty($parts)) {
                $w = array_pop($parts);
                array_unshift($surnameParts, $w);
                while (!empty($parts)) {
                    $peek = Str::lower($parts[count($parts)-1]);
                    if (in_array($peek, $particles, true)) {
                        array_unshift($surnameParts, array_pop($parts));
                    } else break;
                }
                break;
            }
            return [trim(implode(' ', $surnameParts)), $parts];
        };

        [$ap2, $rest] = $takeSurname($tokens);
        [$ap1, $rest] = $takeSurname($rest);
        $nombre = trim(implode(' ', $rest));

        if ($nombre === '' && $ap1) {
            $ap1Tokens = explode(' ', $ap1);
            $nombre = array_shift($ap1Tokens);
            $ap1 = trim(implode(' ', $ap1Tokens)) ?: null;
        }

        return [$nombre ?: null, $ap1 ?: null, $ap2 ?: null];
    }
}
