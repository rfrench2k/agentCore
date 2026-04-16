@echo off
REM AgentCore Scheduler — Windows Task Scheduler Wrapper
REM Schedule this to run every 5 minutes.
REM
REM Task Scheduler settings:
REM   Program: C:\PHP\php.exe
REM   Arguments: D:\AdvancedVentures\htdocs\agentcore\src\Scheduler.php
REM   Start in: D:\AdvancedVentures\htdocs\agentcore

setlocal

REM Load skoopix .env into this process so downstream skills see TELEGRAM_BOT_TOKEN etc.
if exist "V:\AgentCore\skoopix\.env" (
  for /f "usebackq tokens=1,* delims==" %%A in ("V:\AgentCore\skoopix\.env") do (
    if not "%%A"=="" if not "%%A:~0,1"=="#" set "%%A=%%B"
  )
)

C:\PHP\php.exe "%~dp0..\src\Scheduler.php" %*

endlocal
