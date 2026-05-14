@echo off
REM AgentCore Telegram Bot — Windows Task Scheduler wrapper
REM Schedule this to run at startup. The bot loops forever; if it crashes the
REM loop relaunches it after 5 seconds. Disable "Stop the task if it runs
REM longer than" in Task Scheduler.
REM
REM Required environment (set either in your shell, in the scheduled task, or via
REM a sibling .agentcore.env file in this scripts\ directory):
REM   AGENTCORE_WORKSPACE  — full path to your workspace dir (where .env lives)
REM   AGENTCORE_PHP_BIN    — full path to php.exe (optional; defaults to "php")

setlocal enabledelayedexpansion

if exist "%~dp0.agentcore.env" call :load_env "%~dp0.agentcore.env"

if defined AGENTCORE_WORKSPACE (
  if exist "%AGENTCORE_WORKSPACE%\.env" call :load_env "%AGENTCORE_WORKSPACE%\.env"
)

if not defined AGENTCORE_PHP_BIN set "AGENTCORE_PHP_BIN=php"

:loop
"%AGENTCORE_PHP_BIN%" "%~dp0..\src\TelegramBot.php" %*
set "EXITCODE=%errorlevel%"
REM Log every restart attempt to a wrapper-level log. Without this, a silent crash
REM loop or a failed sleep is invisible — which is exactly how this script broke once
REM when "timeout" failed in a no-console Task Scheduler context.
>> "%~dp0..\logs\telegram-wrapper.log" echo [%DATE% %TIME%] Bot exited code=%EXITCODE%, restarting in 5s
REM Use PowerShell for the sleep — "timeout" requires a console handle and exits
REM immediately when run by Task Scheduler, breaking the restart loop.
powershell -NoProfile -NonInteractive -Command "Start-Sleep -Seconds 5"
goto loop

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
