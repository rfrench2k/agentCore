@echo off
REM Install AgentCore Task Scheduler entries
REM Must be run from an elevated command prompt

echo Installing AgentCore scheduled tasks...
echo.

schtasks /Create /XML "%~dp0task-scheduler.xml" /TN "AgentCore Scheduler" /F
if errorlevel 1 (
    echo FAILED to create AgentCore Scheduler task
    exit /b 1
)
echo [OK] AgentCore Scheduler created

schtasks /Create /XML "%~dp0task-telegram.xml" /TN "AgentCore Telegram" /F
if errorlevel 1 (
    echo FAILED to create AgentCore Telegram task
    exit /b 1
)
echo [OK] AgentCore Telegram created

echo.
echo Both tasks installed successfully.
echo View in Task Scheduler: taskschd.msc
