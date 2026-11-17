@echo off
C:\moodle\server\mysql\bin\mysqladmin.exe -h 127.0.0.1 -P 3307 -u root shutdown
taskkill /F /IM httpd.exe /FI "MODULES eq php8apache2_4.dll"
echo Server Moodle sudah dimatikan.
pause
