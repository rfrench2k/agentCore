@echo off
REM AgentCore Scheduler — Windows Task Scheduler Wrapper
REM Schedule this to run every 5 minutes.
REM
REM Task Scheduler settings:
REM   Program: C:\PHP\php.exe
REM   Arguments: D:\AdvancedVentures\htdocs\agentcore\src\Scheduler.php
REM   Start in: D:\AdvancedVentures\htdocs\agentcore

C:\PHP\php.exe "%~dp0..\src\Scheduler.php" %*
