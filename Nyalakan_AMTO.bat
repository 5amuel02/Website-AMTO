@echo off
title Server AMTO - MariaDB 3307 ^| Moodle LMS 8081 ^| React Portal 5173
color 0B

echo.
echo ================================================================
echo   MENYALAKAN WEBSITE AMTO
echo   - Moodle LMS   : http://localhost:8081
echo   - Portal React : http://localhost:5173
echo ================================================================
echo.

:: Ambil folder tempat .bat ini berada (tanpa backslash di akhir)
set "PROJ_DIR=%~dp0"
if "%PROJ_DIR:~-1%"=="\" set "PROJ_DIR=%PROJ_DIR:~0,-1%"

:: Tentukan lokasi tatcwebreact
set "TATCWEB_DIR=%PROJ_DIR%\tatcwebreact"
if not exist "%TATCWEB_DIR%\package.json" (
    set "TATCWEB_DIR=C:\moodle\server\moodle\tatcwebreact"
)

echo Lokasi React : %TATCWEB_DIR%
echo.

:: ================================================================
:: STEP 1 - Hentikan proses lama
:: ================================================================
echo [1/4] Membersihkan proses server lama...
taskkill /f /im mysqld.exe >nul 2>&1
taskkill /f /im httpd.exe  >nul 2>&1
timeout /t 2 /nobreak >nul
echo   OK

:: ================================================================
:: STEP 2 - Jalankan MariaDB
:: ================================================================
echo [2/4] Menyalakan MariaDB (port 3307)...
del /f /q "C:\moodle\server\mysql\data\ib_buffer_pool" 2>nul
del /f /q "C:\moodle\server\mysql\data\multi-master.info" 2>nul
start "" /B "C:\moodle\server\mysql\bin\mysqld.exe" --defaults-file="C:\moodle\server\mysql\bin\my.ini" --standalone --innodb-buffer-pool-load-at-startup=0 --innodb-buffer-pool-dump-at-shutdown=0
timeout /t 4 /nobreak >nul
echo   OK - MariaDB port 3307

:: ================================================================
:: STEP 3 - Jalankan Apache
:: ================================================================
echo [3/4] Menyalakan Apache (Moodle http://localhost:8081)...
start "" /B "C:\moodle\server\apache\bin\httpd.exe"
timeout /t 2 /nobreak >nul
echo   OK - Apache port 8081

:: ================================================================
:: STEP 4 - Jalankan React Portal via file .bat permanen
:: ================================================================
echo [4/4] Menyalakan TATC React Portal (port 5173 + API 3001)...
if exist "%TATCWEB_DIR%\Jalankan_React.bat" (
    start "TATC React Portal" cmd /k "%TATCWEB_DIR%\Jalankan_React.bat"
    echo   OK - React Portal dimulai di jendela terpisah
) else (
    echo   PERINGATAN: File Jalankan_React.bat tidak ditemukan di %TATCWEB_DIR%
)

:: ================================================================
:: Buka browser
:: ================================================================
echo.
echo Menunggu server siap (4 detik)...
timeout /t 4 /nobreak >nul

start http://localhost:8081
start http://localhost:5173

echo.
echo ================================================================
echo   SEMUA SERVER BERJALAN!
echo.
echo   [Moodle LMS]   http://localhost:8081
echo     Login        : admin / Admin123@2
echo.
echo   [TATC Portal]  http://localhost:5173
echo     Admin        : admin@tatc.id      / Admin123@2
echo     Instruktur   : instruktur@tatc.id / Admin123@2
echo     Peserta      : peserta@tatc.id    / Admin123@2
echo.
echo   Ketik Y lalu Enter untuk matikan semua server.
echo   Atau tutup jendela ini saja (server tetap berjalan di background).
echo ================================================================
echo.
set /p SHUTDOWN="Matikan server sekarang? (Y/N): "
if /I "%SHUTDOWN%"=="Y" goto :shutdown
exit /b 0

:shutdown
echo Mematikan MariaDB dan Apache...
taskkill /f /im mysqld.exe >nul 2>&1
taskkill /f /im httpd.exe  >nul 2>&1
echo Semua server dimatikan.
echo Tutup juga jendela "TATC React Portal" jika masih terbuka.
pause
