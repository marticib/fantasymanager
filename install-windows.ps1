<#
  Fantasy Assistant - installador local per a Windows.

  Comprova (i installa si cal, via winget) tot el necessari per fer anar
  el projecte en local: Node.js, PostgreSQL, PHP i Composer. Despres
  configura el backend (Laravel) i el frontend (Vite/React), tal com
  documenta el README ("Installacio").

  Us:
    - Fes doble clic a install-windows.bat (recomanat), o
    - Clic dret sobre aquest fitxer > "Executar amb PowerShell", o
    - Des d'una terminal PowerShell:
        powershell -ExecutionPolicy Bypass -File .\install-windows.ps1

  Aquest script NO substitueix el pas manual d'onboarding de l'app (enganxar
  el token de LaLiga Fantasy) - nomes prepara l'entorn tecnic.

  NOTA: aquest fitxer es guarda expressament en ASCII pla (sense accents ni
  guions llargs). Windows PowerShell 5.1 llegeix un .ps1 sense BOM UTF-8 amb
  la codepage del sistema, i alguns caracters catalans es poden interpretar
  malament com a cometes tipografiques, que PowerShell tracta com a
  delimitadors de cadena valids -> "unexpected token". Evitar accents aqui
  evita aquest problema per complet, independentment de la codificacio.
#>

$ErrorActionPreference = 'Stop'

$RepoRoot    = Split-Path -Parent $MyInvocation.MyCommand.Path
$BackendDir  = Join-Path $RepoRoot 'backend'
$FrontendDir = Join-Path $RepoRoot 'frontend'

# Contrasenya local de desenvolupament que aquest script fa servir per a una
# installacio *propia* de PostgreSQL (via --override al winget install, mes
# avall) i per omplir un DB_PASSWORD buit a backend\.env - mai toca un
# DB_PASSWORD que ja tingui algun valor (un servidor PostgreSQL preexistent
# pot tenir una contrasenya real diferent).
$PgPassword = 'postgres'

function Write-Step {
    param([string]$Msg)
    Write-Host ""
    Write-Host "==> $Msg" -ForegroundColor Cyan
}

function Write-Ok {
    param([string]$Msg)
    Write-Host "    OK: $Msg" -ForegroundColor Green
}

function Write-Warn {
    param([string]$Msg)
    Write-Host "    Avis: $Msg" -ForegroundColor Yellow
}

function Write-ErrorMsg {
    param([string]$Msg)
    Write-Host "    Error: $Msg" -ForegroundColor Red
}

function Test-Command {
    param([string]$Name)
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Update-SessionPath {
    # Un winget install acabat de fer no es reflecteix al PATH d'aquesta
    # sessio de PowerShell fins que no el refresquem manualment.
    $machine = [System.Environment]::GetEnvironmentVariable('Path', 'Machine')
    $user    = [System.Environment]::GetEnvironmentVariable('Path', 'User')
    $env:Path = "$machine;$user"
}

function Test-PortOpen {
    param([string]$ComputerName, [int]$Port, [int]$TimeoutMs = 800)
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $result = $client.BeginConnect($ComputerName, $Port, $null, $null)
        $ok = $result.AsyncWaitHandle.WaitOne($TimeoutMs) -and $client.Connected
        $client.Close()
        return [bool]$ok
    } catch {
        return $false
    }
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
        Write-Ok "$FriendlyName ja instalat"
        return $true
    }

    Write-Warn "$FriendlyName no trobat. Installant amb winget ($WingetId)..."
    winget install --id $WingetId -e --accept-package-agreements --accept-source-agreements
    if ($LASTEXITCODE -ne 0) {
        Write-ErrorMsg "winget no ha pogut installar $FriendlyName. Installa'l manualment: $ManualUrl"
        return $false
    }

    Update-SessionPath
    if (Test-Command $Command) {
        Write-Ok "$FriendlyName instalat correctament"
        return $true
    }

    Write-Warn "$FriendlyName sembla instalat, pero encara no es visible en aquesta sessio."
    Write-Warn "Tanca aquesta finestra, obre'n una de nova i torna a executar l'script."
    return $false
}

# --- 0. winget ---------------------------------------------------------

Write-Step "Comprovant Windows Package Manager (winget)"
if (-not (Test-Command 'winget')) {
    Write-ErrorMsg "winget no esta disponible en aquest sistema."
    Write-Host "    Installa 'App Installer' des de la Microsoft Store i torna a executar aquest script:" -ForegroundColor Red
    Write-Host "    https://apps.microsoft.com/detail/9nblggh4nns1" -ForegroundColor Red
    exit 1
}
Write-Ok "winget disponible"

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Warn "Aquesta finestra no s'executa com a Administrador."
    Write-Warn "Instal.lar PostgreSQL o PHP amb winget sol fallar silenciosament sense permisos d'Administrador."
    $relaunch = Read-Host "    Vols reiniciar l'script com a Administrador ara (recomanat)? (S/n)"
    if ($relaunch -notmatch '^[nN]') {
        Write-Host "    Reiniciant com a Administrador en una finestra nova (accepta el dialeg UAC)..." -ForegroundColor DarkGray
        Write-Host "    Aquesta finestra ja no cal - la installacio continua a la finestra nova." -ForegroundColor DarkGray
        try {
            Start-Process powershell -Verb RunAs -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"")
            exit 0
        } catch {
            Write-Warn "No s'ha pogut reiniciar com a Administrador (UAC cancel.lat?). Continuant sense permisos elevats."
        }
    }
}

# --- 1. Prerequisits -----------------------------------------------------

$nodeOk = Install-IfMissing -Command 'node' -WingetId 'OpenJS.NodeJS.LTS' -FriendlyName 'Node.js' -ManualUrl 'https://nodejs.org/'

# PostgreSQL te un cas propi, no Install-IfMissing generic, per dos motius
# reals detectats amb winget:
#   1. L'instal.lador d'EDB (el que hi ha darrere del paquet de winget) pot
#      quedar-se esperant una contrasenya de superusuari interactiva encara
#      que winget s'executi amb --accept-*-agreements -> cal passar-li-la
#      explicitament amb --override en mode "unattended".
#   2. Confirmat amb un cas real: winget ha informat "no ha pogut installar
#      PostgreSQL" dues execucions seguides, pero un servidor real ja
#      escoltava al port 5432 (exigint contrasenya) - probablement winget
#      interpreta un codi de sortida no-zero benigne de l'instal.lador d'EDB
#      com a fallada. Comprovar nomes 'psql' al PATH no detecta aquest cas
#      (el PATH d'aquesta sessio pot no incloure'l igualment) i acaba
#      reintentant una installacio que, de fet, ja hi es.
Write-Step "Comprovant PostgreSQL"
$pgServerUp = Test-PortOpen -ComputerName '127.0.0.1' -Port 5432
if (Test-Command 'psql') {
    Write-Ok "PostgreSQL ja instalat"
    $pgOk = $true
} elseif ($pgServerUp) {
    Write-Warn "'psql' no es troba al PATH d'aquesta sessio, pero ja hi ha un servidor PostgreSQL actiu al port 5432."
    Write-Warn "S'assumeix que ja esta instal.lat (winget de vegades informa d'un error encara que la installacio hagi funcionat) - no es reinstal.la."
    $pgOk = $true
} else {
    Write-Warn "PostgreSQL no trobat. Installant amb winget (PostgreSQL.PostgreSQL.16)..."
    $pgOverride = "--mode unattended --unattendedmodeui minimal --superpassword $PgPassword --serverport 5432 --disable-components stackbuilder"
    $wingetOutput = & winget install --id 'PostgreSQL.PostgreSQL.16' -e --accept-package-agreements --accept-source-agreements --silent --override $pgOverride 2>&1 | Out-String
    Write-Host $wingetOutput
    Update-SessionPath
    Start-Sleep -Seconds 2
    if ((Test-Command 'psql') -or (Test-PortOpen -ComputerName '127.0.0.1' -Port 5432)) {
        Write-Ok "PostgreSQL instalat correctament (usuari 'postgres', contrasenya local per defecte: $PgPassword)"
        $pgOk = $true
    } else {
        Write-ErrorMsg "winget no ha pogut installar PostgreSQL. Installa'l manualment: https://www.postgresql.org/download/windows/"
        $pgOk = $false
    }
}

$phpOk      = Install-IfMissing -Command 'php'     -WingetId 'PHP.PHP.8.4'               -FriendlyName 'PHP'        -ManualUrl 'https://windows.php.net/download/'
$composerOk = Install-IfMissing -Command 'composer' -WingetId 'Composer.Composer'        -FriendlyName 'Composer'   -ManualUrl 'https://getcomposer.org/download/'

# --- 2. Extensions de PHP -------------------------------------------------

if ($phpOk) {
    Write-Step "Comprovant extensions de PHP (pdo_pgsql, mbstring, zip, fileinfo, openssl, curl)"

    $iniLine = (php --ini 2>$null | Select-String 'Loaded Configuration File:')
    $iniPath = $null
    if ($iniLine) {
        $iniPath = ($iniLine.ToString() -replace 'Loaded Configuration File:', '').Trim()
    }

    if ((-not $iniPath) -or ($iniPath -eq '(none)') -or (-not (Test-Path $iniPath))) {
        # Installacio nova de PHP (zip): encara no hi ha php.ini, nomes les plantilles.
        $phpDir = Split-Path -Parent (Get-Command php).Source
        $template = Join-Path $phpDir 'php.ini-development'
        if (Test-Path $template) {
            $iniPath = Join-Path $phpDir 'php.ini'
            Copy-Item $template $iniPath
            Write-Ok "php.ini creat a partir de php.ini-development"
        } else {
            Write-ErrorMsg "No s'ha trobat cap plantilla php.ini a $phpDir. Activa les extensions manualment."
            $iniPath = $null
        }
    }

    if ($iniPath -and (Test-Path $iniPath)) {
        # fileinfo/openssl/curl: exactament les que php.ini-development deixa
        # comentades per defecte i que Laravel sempre necessita (fileinfo el
        # fa servir league/flysystem - Composer no ho detecta fins l'instant
        # de fer composer install, no abans; openssl per key:generate/xifrat;
        # curl pels clients HTTP a l'API de LaLiga i futbolfantasy.com) -
        # confirmat amb un cas real: composer install fallant nomes per
        # fileinfo mentre pdo_pgsql/mbstring/zip ja hi eren actives.
        $required = @('pdo_pgsql', 'mbstring', 'zip', 'fileinfo', 'openssl', 'curl')
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
                Write-Ok "Totes les extensions necessaries estan actives ($iniPath)"
            }
        } else {
            Write-Ok "Totes les extensions necessaries ja estaven actives"
        }
    }
}

# --- 3. Base de dades ------------------------------------------------------

# Provem amb la contrasenya local per defecte que fem servir per a una
# installacio propia (pas anterior) - sense aixo, 'psql' es queda penjat
# demanant la contrasenya interactivament si el servidor l'exigeix, cosa que
# aqui no te sortida (no hi ha cap terminal esperant escriure-la).
$env:PGPASSWORD = $PgPassword

Write-Step "Comprovant la base de dades 'fantasy' a PostgreSQL"
if (Test-Command 'psql') {
    $exists = & psql -U postgres -tAc "SELECT 1 FROM pg_database WHERE datname='fantasy'" 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Warn "No s'ha pogut connectar a PostgreSQL amb l'usuari 'postgres' i la contrasenya per defecte ($PgPassword)."
        Write-Warn "Si el servidor ja existia d'abans amb una altra contrasenya, crea la base de dades manualment:"
        Write-Warn "    createdb -U postgres fantasy"
    } elseif ($exists -match '1') {
        Write-Ok "La base de dades 'fantasy' ja existeix"
    } else {
        & createdb -U postgres fantasy
        if ($LASTEXITCODE -eq 0) {
            Write-Ok "Base de dades 'fantasy' creada"
        } else {
            Write-Warn "No s'ha pogut crear la base de dades automaticament - crea-la manualment: createdb -U postgres fantasy"
        }
    }
} else {
    Write-Warn "PostgreSQL no disponible en aquesta sessio - crea la base de dades 'fantasy' manualment abans de continuar."
}

# --- 4. Backend (Laravel) -------------------------------------------------

Write-Step "Configurant el backend"
Push-Location $BackendDir
try {
    if (-not (Test-Path '.env')) {
        Copy-Item '.env.example' '.env'
        Write-Ok ".env creat a partir de .env.example (DB_CONNECTION=pgsql, DB_DATABASE=fantasy per defecte)"
    } else {
        Write-Ok ".env ja existeix - no es sobreescriu"
    }

    # .env.example porta DB_PASSWORD buit -> un servidor PostgreSQL que
    # exigeix contrasenya (el cas normal) fa fallar "migrate" amb
    # "no password supplied" encara que tot la resta estigui be. Nomes
    # s'omple si esta buida - mai se sobreescriu una contrasenya real que
    # l'usuari ja hagi posat (p. ex. un servidor PostgreSQL preexistent amb
    # una altra contrasenya).
    $envContent = Get-Content '.env'
    if ($envContent -match '^DB_PASSWORD=\s*$') {
        ($envContent -replace '^DB_PASSWORD=\s*$', "DB_PASSWORD=$PgPassword") | Set-Content '.env'
        Write-Ok "DB_PASSWORD buida a .env -> establerta a la contrasenya local per defecte ($PgPassword)"
    }

    if (Test-Command 'composer') {
        Write-Host "    Installant dependencies PHP (composer install)..." -ForegroundColor DarkGray
        composer install --no-interaction
        if ($LASTEXITCODE -eq 0) {
            Write-Ok "Dependencies del backend instalades"
        } else {
            Write-Warn "composer install ha acabat amb errors - revisa el missatge de Composer mes amunt."
        }
    } else {
        Write-ErrorMsg "Composer no disponible - salta't la installacio de dependencies PHP."
    }

    $vendorAutoload = Join-Path $BackendDir 'vendor\autoload.php'
    if ((Test-Command 'php') -and (Test-Path $vendorAutoload)) {
        php artisan key:generate --ansi
        if ($LASTEXITCODE -eq 0) {
            Write-Ok "Clau d'aplicacio generada"
        } else {
            Write-Warn "No s'ha pogut generar la clau d'aplicacio."
        }

        php artisan migrate --force
        if ($LASTEXITCODE -eq 0) {
            Write-Ok "Migracions executades"
        } else {
            Write-Warn "No s'han pogut executar les migracions - revisa backend\.env (DB_HOST, DB_USERNAME, DB_PASSWORD) contra la contrasenya real del teu servidor PostgreSQL."
            Write-Warn "Si el missatge de dalt diu 'no password supplied' o 'password authentication failed', DB_PASSWORD a backend\.env no coincideix amb la contrasenya real del servidor - edita'l manualment."
        }
    } elseif (Test-Command 'php') {
        # composer install ha fallat mes amunt -> no hi ha vendor/autoload.php.
        # Cridar artisan igualment nomes tira un fatal error de PHP en cascada
        # (confirmat amb un cas real) sense afegir cap informacio nova.
        Write-Warn "No hi ha vendor/autoload.php - salta't key:generate i migrate fins que 'composer install' funcioni."
        Write-Warn "Un cop arreglat l'error de Composer de mes amunt, torna a executar aquest script."
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
        Write-Ok ".env ja existeix - no es sobreescriu"
    }

    if (Test-Command 'npm') {
        Write-Host "    Installant dependencies de Node (npm install)..." -ForegroundColor DarkGray
        npm install
        if ($LASTEXITCODE -eq 0) {
            Write-Ok "Dependencies del frontend instalades"
        } else {
            Write-Warn "npm install ha acabat amb errors - revisa el missatge de npm mes amunt."
        }
    } else {
        Write-ErrorMsg "npm no disponible - salta't la installacio de dependencies del frontend."
    }
} finally {
    Pop-Location
}

# --- 6. Resum ---------------------------------------------------------

Write-Step "Installacio completada"
Write-Host ""
Write-Host "Per arrencar l'aplicacio manualment:" -ForegroundColor Cyan
Write-Host "  1) Una terminal:  cd backend  ; php artisan serve"
Write-Host "  2) Una altra:     cd frontend ; npm run dev"
Write-Host "  3) Obre http://localhost:5173"
Write-Host ""
Write-Host "Primer us: crea un compte a l'app i segueix l'assistent d'onboarding" -ForegroundColor DarkGray
Write-Host "(necessitaras l'access_token d'una sessio ja iniciada a LaLiga Fantasy - mai la contrasenya)." -ForegroundColor DarkGray
Write-Host ""

$launch = Read-Host "Vols que obri les dues terminals ara mateix i arrenqui els servidors? (s/N)"
if ($launch -match '^[sSyY]') {
    Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd `"$BackendDir`"; php artisan serve"
    Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd `"$FrontendDir`"; npm run dev"
    Start-Sleep -Seconds 3
    Start-Process "http://localhost:5173"
}
