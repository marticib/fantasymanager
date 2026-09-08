<#
  Fantasy Assistant — instal·lador local per a Windows.

  Comprova (i instal·la si cal, via winget) tot el necessari per fer anar
  el projecte en local: Node.js, PostgreSQL, PHP i Composer. Després
  configura el backend (Laravel) i el frontend (Vite/React), tal com
  documenta el README ("Instal·lació").

  Ús:
    - Fes doble clic a install-windows.bat (recomanat), o
    - Clic dret sobre aquest fitxer > "Executar amb PowerShell", o
    - Des d'una terminal PowerShell:
        powershell -ExecutionPolicy Bypass -File .\install-windows.ps1

  Aquest script NO substitueix el pas manual d'onboarding de l'app (enganxar
  el token de LaLiga Fantasy) — només prepara l'entorn tècnic.
#>

$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch {}

$RepoRoot    = Split-Path -Parent $MyInvocation.MyCommand.Path
$BackendDir  = Join-Path $RepoRoot 'backend'
$FrontendDir = Join-Path $RepoRoot 'frontend'

function Write-Step    { param([string]$Msg) Write-Host "`n==> $Msg" -ForegroundColor Cyan }
function Write-Ok      { param([string]$Msg) Write-Host "    OK: $Msg" -ForegroundColor Green }
function Write-Warn    { param([string]$Msg) Write-Host "    Avís: $Msg" -ForegroundColor Yellow }
function Write-ErrorMsg { param([string]$Msg) Write-Host "    Error: $Msg" -ForegroundColor Red }

function Test-Command {
    param([string]$Name)
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Update-SessionPath {
    # Un winget install acabat de fer no es reflecteix al PATH d'aquesta
    # sessió de PowerShell fins que no el refresquem manualment.
    $machine = [System.Environment]::GetEnvironmentVariable('Path', 'Machine')
    $user    = [System.Environment]::GetEnvironmentVariable('Path', 'User')
    $env:Path = "$machine;$user"
}

function Install-IfMissing {
    param(
        [string]$Command,
        [string]$WingetId,
        [string]$FriendlyName,
        [string]$ManualUrl
    )
    Write-Step "Comprovant $FriendlyName"
    if (Test-Command $Command) {
        Write-Ok "$FriendlyName ja instal·lat"
        return $true
    }

    Write-Warn "$FriendlyName no trobat. Instal·lant amb winget ($WingetId)..."
    try {
        winget install --id $WingetId -e --accept-package-agreements --accept-source-agreements
    } catch {
        Write-ErrorMsg "winget no ha pogut instal·lar $FriendlyName. Instal·la'l manualment: $ManualUrl"
        return $false
    }

    Update-SessionPath
    if (Test-Command $Command) {
        Write-Ok "$FriendlyName instal·lat correctament"
        return $true
    }

    Write-Warn "$FriendlyName sembla instal·lat, però encara no és visible en aquesta sessió."
    Write-Warn "Tanca aquesta finestra, obre'n una de nova i torna a executar l'script."
    return $false
}

# --- 0. winget ---------------------------------------------------------

Write-Step "Comprovant Windows Package Manager (winget)"
if (-not (Test-Command 'winget')) {
    Write-ErrorMsg "winget no està disponible en aquest sistema."
    Write-Host "    Instal·la 'App Installer' des de la Microsoft Store i torna a executar aquest script:" -ForegroundColor Red
    Write-Host "    https://apps.microsoft.com/detail/9nblggh4nns1" -ForegroundColor Red
    exit 1
}
Write-Ok "winget disponible"

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Warn "Aquesta finestra no s'executa com a Administrador."
    Write-Warn "Si la instal·lació de PostgreSQL o PHP falla, torna a executar install-windows.bat amb 'Executar com a administrador'."
}

# --- 1. Prerequisits -----------------------------------------------------

$nodeOk     = Install-IfMissing -Command 'node'     -WingetId 'OpenJS.NodeJS.LTS'      -FriendlyName 'Node.js'    -ManualUrl 'https://nodejs.org/'
$pgOk       = Install-IfMissing -Command 'psql'      -WingetId 'PostgreSQL.PostgreSQL.16' -FriendlyName 'PostgreSQL' -ManualUrl 'https://www.postgresql.org/download/windows/'
$phpOk      = Install-IfMissing -Command 'php'       -WingetId 'PHP.PHP.8.4'             -FriendlyName 'PHP'        -ManualUrl 'https://windows.php.net/download/'
$composerOk = Install-IfMissing -Command 'composer'  -WingetId 'Composer.Composer'       -FriendlyName 'Composer'   -ManualUrl 'https://getcomposer.org/download/'

# --- 2. Extensions de PHP (pdo_pgsql, mbstring, zip) ---------------------

if ($phpOk) {
    Write-Step "Comprovant extensions de PHP (pdo_pgsql, mbstring, zip)"

    $iniLine = (php --ini 2>$null | Select-String 'Loaded Configuration File:')
    $iniPath = $null
    if ($iniLine) {
        $iniPath = ($iniLine.ToString() -replace 'Loaded Configuration File:', '').Trim()
    }

    if (-not $iniPath -or $iniPath -eq '(none)' -or -not (Test-Path $iniPath)) {
        # Instal·lació nova de PHP (zip): encara no hi ha php.ini, només les plantilles.
        $phpDir = Split-Path -Parent (Get-Command php).Source
        $template = Join-Path $phpDir 'php.ini-development'
        if (Test-Path $template) {
            $iniPath = Join-Path $phpDir 'php.ini'
            Copy-Item $template $iniPath
            Write-Ok "php.ini creat a partir de php.ini-development"
        } else {
            Write-ErrorMsg "No s'ha trobat cap plantilla php.ini a $phpDir. Activa les extensions manualment."
        }
    }

    if ($iniPath -and (Test-Path $iniPath)) {
        $required = @('pdo_pgsql', 'mbstring', 'zip')
        $loaded = @((php -m 2>$null))
        $missing = $required | Where-Object { $loaded -notcontains $_ }

        if ($missing.Count -gt 0) {
            Write-Warn "Activant a php.ini: $($missing -join ', ')"
            $content = Get-Content $iniPath
            foreach ($ext in $missing) {
                $pattern = "^\s*;\s*extension\s*=\s*$ext\s*$"
                if ($content -match $pattern) {
                    $content = $content -replace $pattern, "extension=$ext"
                } elseif (-not ($content -match "^\s*extension\s*=\s*$ext\s*$")) {
                    $content += "extension=$ext"
                }
            }
            Set-Content -Path $iniPath -Value $content -Encoding ASCII

            $loaded = @((php -m 2>$null))
            $stillMissing = $required | Where-Object { $loaded -notcontains $_ }
            if ($stillMissing.Count -gt 0) {
                Write-ErrorMsg "Encara falten extensions PHP: $($stillMissing -join ', ')."
                Write-Host "    Edita manualment $iniPath i treu el ';' de davant de cada 'extension=...'" -ForegroundColor Red
            } else {
                Write-Ok "Totes les extensions necessàries estan actives ($iniPath)"
            }
        } else {
            Write-Ok "Totes les extensions necessàries ja estaven actives"
        }
    }
}

# --- 3. Base de dades ------------------------------------------------------

Write-Step "Comprovant la base de dades 'fantasy' a PostgreSQL"
if (Test-Command 'psql') {
    $exists = & psql -U postgres -tAc "SELECT 1 FROM pg_database WHERE datname='fantasy'" 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Warn "No s'ha pogut connectar a PostgreSQL amb l'usuari 'postgres'."
        Write-Warn "PostgreSQL et pot demanar la contrasenya interactivament — crea la base de dades manualment si cal:"
        Write-Warn "    createdb -U postgres fantasy"
    } elseif ($exists -match '1') {
        Write-Ok "La base de dades 'fantasy' ja existeix"
    } else {
        & createdb -U postgres fantasy
        if ($LASTEXITCODE -eq 0) {
            Write-Ok "Base de dades 'fantasy' creada"
        } else {
            Write-Warn "No s'ha pogut crear la base de dades automàticament — crea-la manualment: createdb -U postgres fantasy"
        }
    }
} else {
    Write-Warn "PostgreSQL no disponible en aquesta sessió — crea la base de dades 'fantasy' manualment abans de continuar."
}

# --- 4. Backend (Laravel) -------------------------------------------------

Write-Step "Configurant el backend"
Push-Location $BackendDir
try {
    if (-not (Test-Path '.env')) {
        Copy-Item '.env.example' '.env'
        Write-Ok ".env creat a partir de .env.example (DB_CONNECTION=pgsql, DB_DATABASE=fantasy per defecte)"
    } else {
        Write-Ok ".env ja existeix — no es sobreescriu"
    }

    if (Test-Command 'composer') {
        Write-Host "    Instal·lant dependències PHP (composer install)..." -ForegroundColor DarkGray
        composer install --no-interaction
        if ($LASTEXITCODE -eq 0) {
            Write-Ok "Dependències del backend instal·lades"
        } else {
            Write-Warn "composer install ha acabat amb errors — revisa el missatge de Composer més amunt."
        }
    } else {
        Write-ErrorMsg "Composer no disponible — salta't la instal·lació de dependències PHP."
    }

    if (Test-Command 'php') {
        php artisan key:generate --ansi
        if ($LASTEXITCODE -eq 0) {
            Write-Ok "Clau d'aplicació generada"
        } else {
            Write-Warn "No s'ha pogut generar la clau d'aplicació."
        }

        php artisan migrate --force
        if ($LASTEXITCODE -eq 0) {
            Write-Ok "Migracions executades"
        } else {
            Write-Warn "No s'han pogut executar les migracions — revisa la connexió a PostgreSQL a backend\.env (DB_HOST, DB_USERNAME, DB_PASSWORD)."
        }
    }
} finally {
    Pop-Location
}

# --- 5. Frontend (Vite/React) ---------------------------------------------

Write-Step "Configurant el frontend"
Push-Location $FrontendDir
try {
    if (-not (Test-Path '.env')) {
        Copy-Item '.env.example' '.env'
        Write-Ok ".env creat a partir de .env.example"
    } else {
        Write-Ok ".env ja existeix — no es sobreescriu"
    }

    if (Test-Command 'npm') {
        Write-Host "    Instal·lant dependències de Node (npm install)..." -ForegroundColor DarkGray
        npm install
        if ($LASTEXITCODE -eq 0) {
            Write-Ok "Dependències del frontend instal·lades"
        } else {
            Write-Warn "npm install ha acabat amb errors — revisa el missatge de npm més amunt."
        }
    } else {
        Write-ErrorMsg "npm no disponible — salta't la instal·lació de dependències del frontend."
    }
} finally {
    Pop-Location
}

# --- 6. Resum ---------------------------------------------------------

Write-Step "Instal·lació completada"
Write-Host ""
Write-Host "Per arrencar l'aplicació manualment:" -ForegroundColor Cyan
Write-Host "  1) Una terminal:  cd backend  ; php artisan serve"
Write-Host "  2) Una altra:     cd frontend ; npm run dev"
Write-Host "  3) Obre http://localhost:5173"
Write-Host ""
Write-Host "Primer ús: crea un compte a l'app i segueix l'assistent d'onboarding" -ForegroundColor DarkGray
Write-Host "(necessitaràs l'access_token d'una sessió ja iniciada a LaLiga Fantasy — mai la contrasenya)." -ForegroundColor DarkGray
Write-Host ""

$launch = Read-Host "Vols que obri les dues terminals ara mateix i arrenqui els servidors? (s/N)"
if ($launch -match '^[sSyY]') {
    Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd `"$BackendDir`"; php artisan serve"
    Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd `"$FrontendDir`"; npm run dev"
    Start-Sleep -Seconds 3
    Start-Process "http://localhost:5173"
}
