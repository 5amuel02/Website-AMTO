@echo off
title Server Terpadu TATC Portal (5173) & Moodle LMS AMTO (8080)
echo ====================================================================
echo   MENYALAKAN SERVER DATABASE MARIADB, MOODLE LMS, DAN TATC REACT
echo ====================================================================

echo [1/3] Memeriksa Database MariaDB (Port 3307)...
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="0" (
    echo       MariaDB sudah aktif di Port 3307.
) else (
    if exist "C:\moodle\server\mysql\bin\mysqld.exe" (
        del /f /q "C:\moodle\server\mysql\data\ib_buffer_pool" 2>nul
        del /f /q "C:\moodle\server\mysql\data\multi-master.info" 2>nul
        del /f /q "C:\moodle\server\mysql\data\*.pid" 2>nul
        start "" /b "C:\moodle\server\mysql\bin\mysqld.exe" --defaults-file="C:\moodle\server\mysql\bin\my.ini" --standalone --innodb-buffer-pool-load-at-startup=0 --innodb-buffer-pool-dump-at-shutdown=0
    ) else (
        start "" /b "D:\AMTO\.runtime\mariadb\bin\mysqld.exe" --defaults-file="D:\AMTO\.runtime\mariadb\my.ini"
    )
    timeout /t 4 /nobreak >nul
    echo       MariaDB berhasil dinyalakan di Port 3307.
)

echo [2/3] Memeriksa Web Server Moodle LMS (http://localhost:8080)...
if exist "C:\moodle\server\apache\bin\httpd.exe" (
    tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe">NUL
    if "%ERRORLEVEL%"=="0" (
        echo       Apache sudah aktif di Port 8080.
    ) else (
        start "" /b "C:\moodle\server\apache\bin\httpd.exe"
        echo       Apache berhasil dinyalakan di Port 8080.
    )
) else (
    start "" /b "D:\AMTO\.runtime\php\php.exe" -c "D:\AMTO\.runtime\php\php.ini" -S 0.0.0.0:8080 -t "D:\AMTO\Website-AMTO\public" "D:\AMTO\.runtime\router.php"
    echo       PHP 8.3 Moodle Server berhasil dinyalakan di Port 8080.
)

echo [3/3] Menyalakan TATC Web React (http://localhost:5173 ^& API 3001)...
if exist "D:\AMTO\.runtime\node\node.exe" (
    set "PATH=D:\AMTO\.runtime\node;%PATH%"
)
if exist "C:\Users\Asus Tuf Gaming\tatcwebreact" (
    cd /d "C:\Users\Asus Tuf Gaming\tatcwebreact"
) else (
    cd /d "D:\AMTO\Website-AMTO\tatcwebreact"
)
call npm run dev
pause
