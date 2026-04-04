@echo off
REM AgentCore Telegram Bot — Windows Task Scheduler Wrapper
REM Schedule this to run at startup with "Do not stop the task if it runs longer than"
REM
REM Task Scheduler settings:
REM   Program: C:\PHP\php.exe
REM   Arguments: D:\AdvancedVentures\htdocs\agentcore\src\TelegramBot.php
REM   Start in: D:\AdvancedVentures\htdocs\agentcore

:loop
C:\PHP\php.exe "%~dp0..\src\TelegramBot.php" %*
echo Bot exited. Restarting in 5 seconds...
timeout /t 5 /nobreak >nul
goto loop
