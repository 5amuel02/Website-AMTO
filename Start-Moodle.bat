@echo off
cd /d C:\moodle\server
start "" /B C:\moodle\server\mysql\bin\mysqld.exe --defaults-file=C:\moodle\server\mysql\bin\my.ini --standalone
start "" /B C:\moodle\server\apache\bin\httpd.exe
echo Server Moodle sudah menyala di http://localhost:8080
start http://localhost:8080
pause
