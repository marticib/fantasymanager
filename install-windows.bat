@echo off
chcp 65001 >nul
setlocal

echo Fantasy Assistant - installador local per a Windows
echo.

where powershell >nul 2>nul
if errorlevel 1 (
    echo No s'ha trobat PowerShell en aquest sistema.
    pause
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-windows.ps1"

echo.
pause
