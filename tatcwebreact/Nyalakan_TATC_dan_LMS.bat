@echo off
title Server Terpadu TATC Portal (5173) & Moodle LMS AMTO (8080)
echo ====================================================================
echo   MENYALAKAN SERVER DATABASE MARIADB, MOODLE LMS, DAN TATC REACT
echo ====================================================================

echo [1/3] Menyalakan Database MariaDB (Port 3307)...
del /f /q "C:\moodle\server\mysql\data\ib_buffer_pool" 2>nul
del /f /q "C:\moodle\server\mysql\data\multi-master.info" 2>nul
start "" /b "C:\moodle\server\mysql\bin\mysqld.exe" --defaults-file="C:\moodle\server\mysql\bin\my.ini" --standalone --innodb-buffer-pool-load-at-startup=0 --innodb-buffer-pool-dump-at-shutdown=0
timeout /t 3 /nobreak >nul

echo [2/3] Menyalakan Apache Moodle LMS (http://localhost:8080)...
start "" /b "C:\moodle\server\apache\bin\httpd.exe"

echo [3/3] Menyalakan TATC Web React (http://localhost:5173 ^& API 3001)...
cd /d "C:\Users\Asus Tuf Gaming\tatcwebreact"
call npm run dev
pause
