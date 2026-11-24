@echo off
setlocal EnableDelayedExpansion
title Setup Awal Website AMTO - Jalankan Sekali
color 0A

echo.
echo ================================================================
echo   SETUP AWAL WEBSITE AMTO (Jalankan sekali sebelum pertama kali)
echo ================================================================
echo.

:: === Tentukan direktori proyek ini ===
set "PROJ_DIR=%~dp0"
set "PROJ_DIR=%PROJ_DIR:~0,-1%"
set "MOODLE_DIR=C:\moodle\server\moodle"
set "TATCWEB_DIR=%PROJ_DIR%\tatcwebreact"

echo Direktori proyek  : %PROJ_DIR%
echo Direktori Moodle  : %MOODLE_DIR%
echo Direktori React   : %TATCWEB_DIR%
echo.

:: ================================================================
:: LANGKAH 1 - Cek apakah proyek sudah ada di C:\moodle\server\moodle
:: ================================================================
echo [LANGKAH 1/5] Memastikan folder proyek terhubung ke C:\moodle\server\moodle...

if /I "%PROJ_DIR%"=="%MOODLE_DIR%" (
    echo   OK - Proyek sudah berada di C:\moodle\server\moodle
) else (
    echo   INFO: Proyek ada di %PROJ_DIR%
    echo   Memeriksa apakah C:\moodle\server\moodle perlu diperbarui...
    if exist "%MOODLE_DIR%\config.php" (
        echo   OK - C:\moodle\server\moodle sudah berisi Moodle.
    ) else (
        echo   PERINGATAN: %MOODLE_DIR% kosong atau tidak ada config.php.
        echo   Salin proyek ke sana secara manual jika diperlukan.
    )
)
echo.

:: ================================================================
:: LANGKAH 1b - Salin tatcwebreact ke C:\moodle\server\moodle jika belum ada
::              (hanya jika proyek ini bukan C:\moodle\server\moodle)
:: ================================================================
if /I NOT "%PROJ_DIR%"=="%MOODLE_DIR%" (
    if not exist "%MOODLE_DIR%\tatcwebreact\package.json" (
        echo   Menyalin tatcwebreact ke C:\moodle\server\moodle...
        xcopy "%TATCWEB_DIR%" "%MOODLE_DIR%\tatcwebreact" /E /I /H /Y /Q
        if %ERRORLEVEL% EQU 0 (
            echo   OK - tatcwebreact berhasil disalin ke %MOODLE_DIR%
        ) else (
            echo   ERROR: Gagal menyalin tatcwebreact
        )
    ) else (
        echo   OK - tatcwebreact sudah ada di %MOODLE_DIR%
    )
)
echo.

:: ================================================================
:: LANGKAH 2 - Buat folder moodledata jika belum ada
:: ================================================================
echo [LANGKAH 2/5] Memeriksa folder moodledata...
if not exist "C:\moodle\server\moodledata" (
    mkdir "C:\moodle\server\moodledata"
    echo   DIBUAT: C:\moodle\server\moodledata
) else (
    echo   OK - C:\moodle\server\moodledata sudah ada
)
echo.

:: ================================================================
:: LANGKAH 3 - Import database (jika belum pernah dilakukan)
:: ================================================================
echo [LANGKAH 3/5] Import database ke MariaDB (port 3307)...
echo.
echo   Memastikan MariaDB berjalan terlebih dahulu...
del /f /q "C:\moodle\server\mysql\data\ib_buffer_pool" 2>nul
start "" /B "C:\moodle\server\mysql\bin\mysqld.exe" --defaults-file="C:\moodle\server\mysql\bin\my.ini" --standalone --innodb-buffer-pool-load-at-startup=0 --innodb-buffer-pool-dump-at-shutdown=0
echo   Menunggu MariaDB siap (5 detik)...
timeout /t 5 /nobreak >nul

echo.
echo   Membuat database 'moodle' dan 'tatc_web'...
"C:\moodle\server\mysql\bin\mysql.exe" -h 127.0.0.1 -P 3307 -u root -e "CREATE DATABASE IF NOT EXISTS moodle CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>nul
"C:\moodle\server\mysql\bin\mysql.exe" -h 127.0.0.1 -P 3307 -u root -e "CREATE DATABASE IF NOT EXISTS tatc_web CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>nul

echo   Import moodle_amto.sql (database utama Moodle)...
"C:\moodle\server\mysql\bin\mysql.exe" -h 127.0.0.1 -P 3307 -u root moodle < "%PROJ_DIR%\database\moodle_amto.sql"
if %ERRORLEVEL% EQU 0 (
    echo   OK - moodle_amto.sql berhasil diimport
) else (
    echo   PERINGATAN: Ada error saat import moodle_amto.sql (mungkin sudah ada)
)

echo   Import skema_amto_bulan_1_2.sql (tabel AMTO khusus)...
"C:\moodle\server\mysql\bin\mysql.exe" -h 127.0.0.1 -P 3307 -u root moodle < "%PROJ_DIR%\database\skema_amto_bulan_1_2.sql"
if %ERRORLEVEL% EQU 0 (
    echo   OK - skema_amto_bulan_1_2.sql berhasil diimport
) else (
    echo   PERINGATAN: Ada error saat import skema tabel AMTO
)

echo   Import tatc_web.sql (database portal React)...
"C:\moodle\server\mysql\bin\mysql.exe" -h 127.0.0.1 -P 3307 -u root tatc_web < "%PROJ_DIR%\database\tatc_web.sql"
if %ERRORLEVEL% EQU 0 (
    echo   OK - tatc_web.sql berhasil diimport
) else (
    echo   PERINGATAN: Ada error saat import tatc_web.sql (mungkin sudah ada)
)
echo.

:: ================================================================
:: LANGKAH 4 - Install dependensi Node.js untuk tatcwebreact
:: ================================================================
echo [LANGKAH 4/5] Install dependensi Node.js untuk TATC React Portal...
if exist "%TATCWEB_DIR%\package.json" (
    cd /d "%TATCWEB_DIR%"
    echo   Menjalankan npm install di %TATCWEB_DIR%...
    call npm install
    if %ERRORLEVEL% EQU 0 (
        echo   OK - npm install frontend selesai
    ) else (
        echo   ERROR: npm install gagal. Pastikan Node.js terinstall.
    )
    echo   Menjalankan npm install di backend...
    call npm --prefix backend install
    if %ERRORLEVEL% EQU 0 (
        echo   OK - npm install backend selesai
    ) else (
        echo   ERROR: npm install backend gagal.
    )
) else (
    echo   PERINGATAN: Folder tatcwebreact tidak ditemukan di %TATCWEB_DIR%
)
echo.

:: ================================================================
:: LANGKAH 5 - Buat file .env untuk tatcwebreact jika belum ada
:: ================================================================
echo [LANGKAH 5/5] Menyiapkan file konfigurasi .env...
if not exist "%TATCWEB_DIR%\.env" (
    copy "%TATCWEB_DIR%\.env.example" "%TATCWEB_DIR%\.env" >nul
    echo   DIBUAT: tatcwebreact\.env dari .env.example
) else (
    echo   OK - tatcwebreact\.env sudah ada
)
if not exist "%TATCWEB_DIR%\backend\.env" (
    copy "%TATCWEB_DIR%\backend\.env.example" "%TATCWEB_DIR%\backend\.env" >nul
    echo   DIBUAT: tatcwebreact\backend\.env dari .env.example
) else (
    echo   OK - tatcwebreact\backend\.env sudah ada
)
echo.

:: ================================================================
:: SELESAI
:: ================================================================
echo ================================================================
echo   SETUP SELESAI!
echo.
echo   Selanjutnya, jalankan: Nyalakan_AMTO.bat
echo   untuk menyalakan semua server sekaligus.
echo ================================================================
echo.
pause
