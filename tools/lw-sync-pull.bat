@echo off
setlocal
rem ============================================================
rem  Pull all data from the online ai_sync endpoint and overwrite
rem  the local data/ directory.  Implementation: lw_sync_pull.php
rem  (this folder's parent is the site root).
rem
rem  Usage:  double-click this file, or run with an argument:
rem            lw-sync-pull.bat            -> pull and overwrite
rem            lw-sync-pull.bat --dry-run  -> preview only, no write
rem
rem  Keep this file ASCII-only: cmd.exe parses it in the OEM
rem  codepage, non-ASCII bytes would be garbled.
rem ============================================================
set "SCRIPT=%~dp0..\tools\lw_sync_pull.php"

rem --- locate a PHP that can run the script ---
set "PHP_EXE="
where php >nul 2>nul
if %ERRORLEVEL%==0 (
    for /f "delims=" %%i in ('where php') do (
        if not defined PHP_EXE set "PHP_EXE=%%i"
    )
)
if not defined PHP_EXE (
    for %%P in (
        "E:\php\runtime\php.exe"
        "C:\php\php.exe"
        "C:\tools\php\php.exe"
        "C:\tools\php85\php.exe"
        "C:\tools\php84\php.exe"
        "C:\xampp\php\php.exe"
        "D:\php\php.exe"
    ) do (
        if not defined PHP_EXE if exist "%%~P" set "PHP_EXE=%%~P"
    )
)
if not defined PHP_EXE (
    echo   [!] PHP not found. Please install PHP 8.0+ or add it to PATH.
    pause
    exit /b 1
)

"%PHP_EXE%" "%SCRIPT%" %*
set RC=%ERRORLEVEL%
echo.
pause
exit /b %RC%
