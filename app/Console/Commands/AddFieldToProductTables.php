<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AddFieldToProductTables extends Command
{
    protected $signature = 'productos:add-field
        {--name= : Nombre de la columna a crear (obligatorio)}
        {--type=string : Tipo: string,text,integer,bigInteger,boolean,date,datetime,decimal,json}
        {--length=255 : Longitud para string/integer donde aplique}
        {--precision=12 : Precisión para decimal}
        {--scale=2 : Escala para decimal}
        {--nullable : Hace la columna NULL}
        {--default= : Valor por defecto}
        {--after= : Insertar después de esta columna}
        {--all : Aplicar a productos + subproductos + anexos}
        {--products : Solo productos (padre_id NULL, tipo_producto_asociado NULL)}
        {--subproducts : Solo subproductos (padre_id NOT NULL, tipo_producto_asociado NULL)}
        {--annexes : Solo anexos (padre_id NULL, tipo_producto_asociado NOT NULL)}
        {--annexs : Alias de --annexes}
        {--dry-run : Solo mostrar, no aplicar cambios}';

    protected $description = 'Añade un campo a todas las tablas de productos según letras_identificacion y filtros (productos/subproductos/anexos).';

    public function handle(): int
    {
        $name = $this->option('name');
        if (!$name) {
            $this->error('Debes indicar --name con el nombre de la columna.');
            return self::FAILURE;
        }

        // Determinar el conjunto de categorías a incluir
        $targets = $this->determineTargets();

        if (empty($targets)) {
            $this->warn('No se seleccionó ningún grupo. Usa --all o alguna combinación de --products/--subproducts/--annexes.');
            return self::INVALID;
        }

        $this->info(sprintf(
            "Añadiendo columna '%s' (type=%s) a tablas de: %s",
            $name,
            $this->option('type') ?? 'string',
            implode(', ', $targets)
        ));

        // Construir query base
        $query = DB::table('tipo_producto')->select([
            'id',
            'letras_identificacion',
            'padre_id',
            'tipo_producto_asociado',
            DB::raw('NULL as tabla') // por si existiera una columna real "tabla" en el futuro
        ]);

        // Filtrar según grupos
        $query->where(function ($q) use ($targets) {
            if (in_array('products', $targets)) {
                $q->orWhere(function ($qq) {
                    $qq->whereNull('padre_id')
                       ->whereNull('tipo_producto_asociado');
                });
            }
            if (in_array('subproducts', $targets)) {
                $q->orWhere(function ($qq) {
                    $qq->whereNotNull('padre_id')
                       ->whereNull('tipo_producto_asociado');
                });
            }
            if (in_array('annexes', $targets)) {
                $q->orWhere(function ($qq) {
                    $qq->whereNull('padre_id')
                       ->whereNotNull('tipo_producto_asociado');
                });
            }
        });

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->warn('No se encontraron registros en tipo_producto con esos filtros.');
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $added = 0; $skipped = 0; $errors = 0;

        foreach ($rows as $row) {
            $table = $this->resolveTableName($row);
            if (!$table) {
                $this->warn("No se pudo resolver/ubicar la tabla para letras_identificacion='{$row->letras_identificacion}' (id={$row->id}). Saltando.");
                $skipped++;
                continue;
            }

            if (!Schema::hasTable($table)) {
                $this->warn("La tabla '{$table}' no existe. Saltando.");
                $skipped++;
                continue;
            }

            if (Schema::hasColumn($table, $name)) {
                $this->line("({$table}) Ya existe la columna '{$name}'. Saltando.");
                $skipped++;
                continue;
            }

            $this->line(($dry ? '[dry-run] ' : '') . "Añadiendo '{$name}' a '{$table}'...");

            try {
                if (!$dry) {
                    Schema::table($table, function (Blueprint $t) use ($name) {
                        $col = $this->buildColumn($t, $name);

                        // nullable
                        if ($this->option('nullable')) {
                            $col->nullable();
                        }

                        // default (vacío es valor válido, usamos hasOptionValue)
                        if ($this->hasOptionValue('default')) {
                            $default = $this->option('default');
                            // Para JSON, evita default no válido
                            if (strtolower($this->option('type') ?? 'string') === 'json') {
                                // no establecer default para json en muchos motores
                            } else {
                                $col->default($default);
                            }
                        }

                        // after
                        if ($after = $this->option('after')) {
                            $col->after($after);
                        }
                    });
                }

                $added++;
            } catch (\Throwable $e) {
                $this->error("Error en tabla '{$table}': ".$e->getMessage());
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Resumen: añadidas={$added}, saltadas={$skipped}, errores={$errors}".($dry ? ' (dry-run)' : ''));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Determina qué grupos aplicar según flags.
     * @return array<string> products|subproducts|annexes
     */
    protected function determineTargets(): array
    {
        $targets = [];

        $annexes = $this->option('annexes') || $this->option('annexs');

        if ($this->option('all')) {
            return ['products','subproducts','annexes'];
        }
        if ($this->option('products')) {
            $targets[] = 'products';
        }
        if ($this->option('subproducts')) {
            $targets[] = 'subproducts';
        }
        if ($annexes) {
            $targets[] = 'annexes';
        }

        // sin flags ⇒ por seguridad, no hacer nada (obliga a ser explícito)
        return array_values(array_unique($targets));
    }


    protected function resolveTableName(object $row): ?string
    {
        return strtolower($row->letras_identificacion);
    }

    /**
     * Crea la columna según las opciones --type/--length/--precision/--scale.
     */
    protected function buildColumn(Blueprint $t, string $name)
    {
        $type = strtolower($this->option('type') ?? 'string');
        $length = (int) $this->option('length');
        $precision = (int) $this->option('precision');
        $scale = (int) $this->option('scale');

        return match ($type) {
            'string'      => $t->string($name, $length),
            'text'        => $t->text($name),
            'integer'     => $t->integer($name),
            'biginteger'  => $t->bigInteger($name),
            'boolean'     => $t->boolean($name),
            'date'        => $t->date($name),
            'datetime'    => $t->dateTime($name),
            'decimal'     => $t->decimal($name, $precision, $scale),
            'json'        => $t->json($name),
            default       => throw new \InvalidArgumentException("Tipo no soportado: {$type}"),
        };
    }

    /**
     * Diferencia entre opción no pasada y pasada vacía (p.ej. --default="").
     */
    protected function hasOptionValue(string $key): bool
    {
        // Laravel devuelve null si no se pasó, string (posiblemente vacía) si se pasó.
        return array_key_exists($key, $this->options()) && $this->option($key) !== null;
    }
}
