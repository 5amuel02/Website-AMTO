@echo off
rem Simple script to run Moodle using PHP built-in server
cd /d "%~dp0"
php -S localhost:8080
pause