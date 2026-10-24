@echo off
chcp 65001 >nul
setlocal
rem ============================================================
rem  Sync key tool for the ai_sync endpoint (see lw-sync-key.ps1)
rem  Run this file without arguments to see the usage text.
rem  Keep this file ASCII-only: cmd.exe parses it in the OEM
rem  codepage, non-ASCII bytes would be garbled.
rem ============================================================
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0lw-sync-key.ps1" %*
set RC=%ERRORLEVEL%
if "%~1"=="" pause
if not "%RC%"=="0" pause
exit /b %RC%
