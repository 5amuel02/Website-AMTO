@echo off
if exist "C:\moodle\server\mysql\bin\mysqld.exe" (
    cd /d C:\moodle\server
    start "" /B C:\moodle\server\mysql\bin\mysqld.exe --defaults-file=C:\moodle\server\mysql\bin\my.ini --standalone
    start "" /B C:\moodle\server\apache\bin\httpd.exe
) else (
    start "" /B "D:\AMTO\.runtime\mariadb\bin\mysqld.exe" --defaults-file="D:\AMTO\.runtime\mariadb\my.ini"
    timeout /t 2 /nobreak >nul
    start "" /B "D:\AMTO\.runtime\php\php.exe" -c "D:\AMTO\.runtime\php\php.ini" -S 0.0.0.0:8080 -t "D:\AMTO\Website-AMTO\public" "D:\AMTO\.runtime\router.php"
)
echo Server Moodle AMTO sudah menyala di http://localhost:8080 (MariaDB Port 3307)
start http://localhost:8080
pause
