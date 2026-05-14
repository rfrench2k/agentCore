@echo off
REM AgentCore — Initialize a new workspace from templates\workspace\
REM
REM Usage: scripts\init-workspace.bat C:\path\to\your\workspace
REM
REM Copies the example skeleton to the target directory, renaming *.example.md
REM to *.md and .env.example to .env. Existing destination files are left alone.

setlocal enabledelayedexpansion

if "%~1"=="" (
    echo Usage: %~nx0 ^<workspace-path^>
    exit /b 1
)

set "DEST=%~1"
set "SRC=%~dp0..\templates\workspace"

if not exist "%SRC%" (
    echo Template directory not found: %SRC%
    exit /b 1
)

if not exist "%DEST%" mkdir "%DEST%"

echo Initializing workspace at: %DEST%
echo Source: %SRC%
echo.

REM Top-level .example.md files
for %%F in ("%SRC%\*.example.md") do (
    set "NAME=%%~nxF"
    set "DST_NAME=!NAME:.example=!"
    if exist "%DEST%\!DST_NAME!" (
        echo   skip ^(exists^): !DST_NAME!
    ) else (
        copy /Y "%%F" "%DEST%\!DST_NAME!" >nul
        echo   created:       !DST_NAME!
    )
)

REM .env.example -> .env
if exist "%SRC%\.env.example" (
    if exist "%DEST%\.env" (
        echo   skip ^(exists^): .env
    ) else (
        copy /Y "%SRC%\.env.example" "%DEST%\.env" >nul
        echo   created:       .env
    )
)

REM Subdirectories
for %%D in (skills memory logs) do (
    if exist "%SRC%\%%D" (
        xcopy /E /I /Y /Q "%SRC%\%%D" "%DEST%\%%D" >nul
        echo   copied dir:    %%D\
    )
)

echo.
echo Done.
echo.
echo Next steps:
echo   1. Fill in %DEST%\USER.md, SOUL.md, MEMORY.md, TOOLS.md, TELEGRAM_INSTRUCTIONS.md
echo   2. Add credentials to %DEST%\.env
echo   3. In AgentCore's config\config.local.php set paths.project_root = "%DEST:\=/%"
echo   4. Run: php migrations\migrate.php

endlocal
