@echo off
if exist "C:\moodle\server\mysql\bin\mysqladmin.exe" (
    C:\moodle\server\mysql\bin\mysqladmin.exe -h 127.0.0.1 -P 3307 -u root shutdown
    taskkill /F /IM httpd.exe /FI "MODULES eq php8apache2_4.dll"
) else (
    "D:\AMTO\.runtime\mariadb\bin\mysqladmin.exe" -h 127.0.0.1 -P 3307 -u root shutdown
    taskkill /F /IM php.exe >nul 2>&1
)
echo Server Moodle AMTO sudah dimatikan.
pause
