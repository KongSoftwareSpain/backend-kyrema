#!/bin/bash
set -e

# Instalar dependencias necesarias para pdo_sqlsrv y sqlsrv
apt-get update
apt-get install -y unixodbc unixodbc-dev

# Instalar pdo_sqlsrv y sqlsrv usando PECL
pecl install pdo_sqlsrv
pecl install sqlsrv

# Habilitar las extensiones
echo "extension=pdo_sqlsrv.so" >> /etc/php/8.2/cli/php.ini
echo "extension=sqlsrv.so" >> /etc/php/8.2/cli/php.ini
