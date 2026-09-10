@echo off
chcp 65001 >nul
setlocal
title Fantasy Assistant

cd /d "%~dp0"

if not exist "backend\vendor\autoload.php" (
    echo El backend no sembla instalat ^(falta backend\vendor^).
    echo Executa install-windows.bat primer.
    pause
    exit /b 1
)

if not exist "frontend\node_modules" (
    echo El frontend no sembla instalat ^(falta frontend\node_modules^).
    echo Executa install-windows.bat primer.
    pause
    exit /b 1
)

echo Arrencant Fantasy Assistant...
echo.

start "Fantasy Assistant - Backend"   /D "%~dp0backend" cmd /k "php artisan serve"
start "Fantasy Assistant - Frontend"  /D "%~dp0frontend" cmd /k "npm run dev"

REM --whisper: el planificador comprova cada minut si toca sincronitzar res i,
REM la immensa majoria de minuts, no toca - sense --whisper imprimeix "No
REM scheduled commands are ready to run" cada vegada, que sembla un error pero
REM no ho es. Les frequencies reals (fantasy:sync-market cada 15 min,
REM fantasy:sync-players cada 60, fantasy:sync-clauses cada 120...) tambe
REM volen dir que la PRIMERA sincronitzacio automatica pot trigar fins a un
REM parell d'hores - per aixo la finestra de sota en fa una manual a l'instant.
start "Fantasy Assistant - Scheduler" /D "%~dp0backend" cmd /k "php artisan schedule:work --whisper"

start "Fantasy Assistant - Sincronitzacio inicial" /D "%~dp0backend" cmd /k "php artisan fantasy:sync & php artisan fantasy:sync-clauses & php artisan fantasy:sync-external-trends & echo. & echo Sincronitzacio inicial completada - pots tancar aquesta finestra."

echo Backend:  http://127.0.0.1:8000
echo Frontend: http://localhost:5173
echo.
echo S'obriran quatre finestres noves: backend, frontend, el planificador de
echo sincronitzacio (per anar-se actualitzant soles a partir d'ara) i una
echo sincronitzacio inicial (nomes triga la primera vegada - fes-la servir
echo per tenir dades a l'instant en lloc d'esperar el planificador). Per
echo aturar l'app, tanca les finestres de backend, frontend i planificador
echo (la de sincronitzacio inicial es pot tancar en acabar).
echo Obrint el navegador en 3 segons...

timeout /t 3 /nobreak >nul
start "" "http://localhost:5173"

exit /b 0
