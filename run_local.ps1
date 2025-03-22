# Directorios de kiosco
$backendPath = "C:\Users\peric\Desktop\backend-kyrema"
$frontendPath = "C:\Users\peric\Desktop\Kyrema\frontend"
$logPath = "$backendPath\storage\logs\laravel.log"

# Función para verificar si un comando esta disponible
function Check-Command {
    param ([string]$cmd)
    $commandExists = $false
    try {
        $null = Get-Command $cmd -ErrorAction Stop
        $commandExists = $true
    } catch {
        $commandExists = $false
    }
    return $commandExists
}

# Verificar que PHP, Composer, Node.js y Angular CLI esten instalados
Write-Host "[BACKEND/FRONTEND] Verificando dependencias del sistema..."
if (-not (Check-Command "php")) { Write-Host "[BACKEND] PHP no esta instalado."; exit }
if (-not (Check-Command "composer")) { Write-Host "[BACKEND] Composer no esta instalado."; exit }
if (-not (Check-Command "node")) { Write-Host "[FRONTEND] Node.js no esta instalado."; exit }
if (-not (Check-Command "ng")) { Write-Host "[FRONTEND] Angular CLI no esta instalado."; exit }

Write-Host "[BACKEND] Todas las dependencias del sistema estan presentes."

Write-Host "[BACKEND] Verificando dependencias de Laravel..."
if (-not (Test-Path "vendor")) {
    Write-Host "[BACKEND] No se encontró la carpeta 'vendor', ejecutando 'composer install'..."
    composer install
} else {
    Write-Host "[BACKEND] Dependencias de Laravel OK."
}

Write-Host "[NAV] Navegando al backend"
Set-Location $backendPath

# Limpiar caches de Laravel
Write-Host "[BACKEND] Limpiando caches de Laravel..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Reiniciar las colas
Write-Host "[BACKEND] Reiniciando colas de Laravel..."
php artisan queue:restart

# Verificar dependencias de Angular
Write-Host "[NAV] Navegando al frontend"
Set-Location $frontendPath
Write-Host "[FRONTEND] Verificando dependencias de Angular..."
if (-not (Test-Path "node_modules")) {
    Write-Host "[FRONTEND] No se encontro la carpeta 'node_modules', ejecutando 'npm install'..."
    npm install
} else {
    Write-Host "[FRONTEND] Dependencias de Angular OK."
}

# Abrir una nueva pestaña de PowerShell para Angular
Write-Host "[FRONTEND] Iniciando Angular en un nuevo terminal..."
Start-Process powershell -ArgumentList "-NoExit", "-Command cd `"$frontendPath`"; ng serve"

# Iniciar Laravel
Set-Location $backendPath
Write-Host "[BACKEND] Levantando servidor Laravel..."
$process = Start-Process -NoNewWindow -FilePath "php" -ArgumentList "artisan serve" -PassThru

# Mostrar los logs en tiempo real
Write-Host "[BACKEND] Monitoreando logs de Laravel..."
while ($process.HasExited -eq $false) {
    if (Test-Path $logPath) {
        Get-Content -Path $logPath -Wait -Tail 0
    } else {
        Write-Host "[BACKEND] Aun no existe el archivo de log. Esperando..."
    }
    Start-Sleep -Seconds 1
}