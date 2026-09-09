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

start "Fantasy Assistant - Backend"  /D "%~dp0backend"  cmd /k "php artisan serve"
start "Fantasy Assistant - Frontend" /D "%~dp0frontend" cmd /k "npm run dev"

echo Backend:  http://127.0.0.1:8000
echo Frontend: http://localhost:5173
echo.
echo S'obriran dues finestres noves amb els servidors. Per aturar l'app, tanca-les totes dues.
echo Obrint el navegador en 3 segons...

timeout /t 3 /nobreak >nul
start "" "http://localhost:5173"

exit /b 0
