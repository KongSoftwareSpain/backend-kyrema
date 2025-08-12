<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestMysqlToSqlsrvConnection extends Command
{
    protected $signature = 'test:mysql-sqlsrv';
    protected $description = 'Prueba las conexiones a MySQL y SQL Server';

    public function handle()
    {
        try {
            $mysqlCount = DB::connection('mysql')->table('asegurados')->count();
            $this->info("Conexión MySQL correcta. Total usuarios: $mysqlCount");
        } catch (\Exception $e) {
            $this->error("Error conectando a MySQL: " . $e->getMessage());
        }

        try {
            $sqlsrvCount = DB::connection('sqlsrv')->table('socios')->count();
            $this->info("Conexión SQL Server correcta. Total users: $sqlsrvCount");
        } catch (\Exception $e) {
            $this->error("Error conectando a SQL Server: " . $e->getMessage());
        }

        return 0;
    }
}
