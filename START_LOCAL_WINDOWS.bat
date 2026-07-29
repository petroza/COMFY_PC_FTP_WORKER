@echo off
setlocal
chcp 65001 >nul
cd /d "%~dp0"
title ComfyW local server

echo ================================================
echo   ComfyW local server
echo ================================================
echo.
where php >nul 2>nul
if errorlevel 1 (
  echo PHP neni v PATH. Nainstaluj PHP 8+ nebo nahraj slozku na PHP hosting.
  echo.
  pause
  exit /b 1
)
set URL=http://127.0.0.1:8788/
echo Startuji %URL%
echo Pro ukonceni zavri toto okno.

where chrome >nul 2>nul
if not errorlevel 1 (
  start "" chrome "%URL%"
) else (
  where msedge >nul 2>nul
  if not errorlevel 1 (
    start "" msedge "%URL%"
  ) else (
    start "" "%URL%"
  )
)

REM Bind na 0.0.0.0, aby web byl dostupny i z ostatnich stroju v siti.
php -S 0.0.0.0:8788 -t .
pause
