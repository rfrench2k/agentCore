@echo off
REM AgentCore Telegram Bot — Windows Task Scheduler Wrapper
REM Schedule this to run at startup with "Do not stop the task if it runs longer than" disabled
REM
REM Task Scheduler settings:
REM   Program: %~dp0telegram-bot.bat
REM   Start in: D:\AdvancedVentures\htdocs\agentcore

setlocal

REM Load skoopix .env into this process so TelegramBot.php sees TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID.
if exist "V:\AgentCore\skoopix\.env" (
  for /f "usebackq tokens=1,* delims==" %%A in ("V:\AgentCore\skoopix\.env") do (
    if not "%%A"=="" if not "%%A:~0,1"=="#" set "%%A=%%B"
  )
)

:loop
C:\PHP\php.exe "%~dp0..\src\TelegramBot.php" %*
echo Bot exited. Restarting in 5 seconds...
timeout /t 5 /nobreak >nul
goto loop
