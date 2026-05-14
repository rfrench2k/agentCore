@echo off
REM AgentCore Scheduler — Windows Task Scheduler wrapper
REM Schedule this to run every 5 minutes.
REM
REM Required environment (set either in your shell, in the scheduled task, or via
REM a sibling .agentcore.env file in this scripts\ directory):
REM   AGENTCORE_WORKSPACE  — full path to your workspace dir (where .env lives)
REM   AGENTCORE_PHP_BIN    — full path to php.exe (optional; defaults to "php")

setlocal enabledelayedexpansion

REM Load optional .agentcore.env from this scripts\ dir so users can set
REM AGENTCORE_WORKSPACE and AGENTCORE_PHP_BIN persistently without editing this file.
if exist "%~dp0.agentcore.env" call :load_env "%~dp0.agentcore.env"

REM Load the workspace .env so skills inherit credentials (TELEGRAM_BOT_TOKEN, DB creds, etc.).
if defined AGENTCORE_WORKSPACE (
  if exist "%AGENTCORE_WORKSPACE%\.env" call :load_env "%AGENTCORE_WORKSPACE%\.env"
)

if not defined AGENTCORE_PHP_BIN set "AGENTCORE_PHP_BIN=php"

"%AGENTCORE_PHP_BIN%" "%~dp0..\src\Scheduler.php" %*

endlocal
goto :eof

REM ---- subroutine: parse KEY=VALUE lines from %1, skipping comments and blanks ----
:load_env
for /f "usebackq tokens=1,* delims==" %%A in ("%~1") do (
    set "_KEY=%%A"
    set "_VAL=%%B"
    if defined _KEY if defined _VAL (
        if not "!_KEY:~0,1!"=="#" set "!_KEY!=!_VAL!"
    )
)
set "_KEY="
set "_VAL="
goto :eof
