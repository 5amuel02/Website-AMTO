@echo off
title Mematikan Server AMTO
color 0C
echo.
echo Mematikan semua server AMTO...
taskkill /f /im mysqld.exe >nul 2>&1
taskkill /f /im httpd.exe >nul 2>&1
echo OK - MariaDB dan Apache dimatikan.
echo.
echo Untuk mematikan React Portal, tutup jendela "TATC React Portal"
echo yang terpisah secara manual (tekan Ctrl+C di jendela tersebut).
echo.
pause
