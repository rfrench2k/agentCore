@echo off
REM Install AgentCore scheduled tasks on Windows
REM Must be run from an elevated command prompt.
REM
REM Reads task-scheduler.example.xml and task-telegram.example.xml, substitutes
REM __AGENTCORE_PATH__ and __AGENTCORE_USER__ placeholders, writes the result
REM to a temp file, then registers each task via schtasks.

setlocal enabledelayedexpansion

set "AC_PATH=%~dp0.."
for %%I in ("%AC_PATH%") do set "AC_PATH=%%~fI"

if "%~1"=="" (
    set "AC_USER=%USERNAME%"
) else (
    set "AC_USER=%~1"
)

echo Installing AgentCore scheduled tasks...
echo   AgentCore path : %AC_PATH%
echo   Run-as user    : %AC_USER%
echo.

call :install_task "AgentCore Scheduler" "%~dp0task-scheduler.example.xml"
if errorlevel 1 exit /b 1
call :install_task "AgentCore Telegram"  "%~dp0task-telegram.example.xml"
if errorlevel 1 exit /b 1

echo.
echo Both tasks installed successfully.
echo View in Task Scheduler: taskschd.msc
exit /b 0

REM ---------- subroutine ----------
:install_task
set "TASK_NAME=%~1"
set "TPL=%~2"
set "TMP=%TEMP%\agentcore-task-%RANDOM%-%RANDOM%.xml"

REM Substitute placeholders. powershell handles the substitution and preserves
REM the file as UTF-8 (schtasks accepts UTF-8 XML).
powershell -NoProfile -Command "(Get-Content -Raw -LiteralPath '%TPL%') -replace '__AGENTCORE_PATH__','%AC_PATH%' -replace '__AGENTCORE_USER__','%AC_USER%' | Set-Content -NoNewline -LiteralPath '%TMP%' -Encoding utf8"
if errorlevel 1 (
    echo FAILED to render template %TPL%
    exit /b 1
)

schtasks /Create /XML "%TMP%" /TN "%TASK_NAME%" /F
set "RC=%ERRORLEVEL%"
del "%TMP%" >nul 2>&1
if not "%RC%"=="0" (
    echo FAILED to create task: %TASK_NAME%
    exit /b 1
)
echo [OK] %TASK_NAME% created
exit /b 0
