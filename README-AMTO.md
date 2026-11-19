# Website AMTO – TATC Integrated Training, Learning & Certification System

Repositori resmi **Website AMTO (Aircraft Maintenance Training Organization) / Telkom University Aviation Training Center (TATC)** yang menggabungkan:
1. **Moodle 5.3.1 E-Learning LMS (`Port 8080`)** — Ruang kelas digital, modul **Registrasi & Verifikasi Peserta AMTO**, **Jadwal & Batch Pelatihan**, serta **LMS Repository Modul CASR Part 147**.
2. **TATC Web Portal React (`tatcwebreact/` – `Port 5173` & API `Port 3001`)** — Portal depan (*Landing Page*, Berita, Mitra, Portal Peserta/Instruktur, BAP, dan Admin Console) yang terisolasi rapi di dalam sub-folder `tatcwebreact/` tanpa mengganggu sistem LMS utama.

---

## Struktur Direktori Proyek (Modular & Tidak Saling Mengganggu)

```text
Website-AMTO/ (C:\moodle\server\moodle)
├── public/                                       # Web Root Moodle LMS (Port 8080)
│   ├── amto/                                     # Modul Khusus AMTO Bulan 1-2 di dalam LMS:
│   │   ├── registrasi.php                        # 1. Modul Registrasi & Verifikasi Berkas Peserta
│   │   ├── jadwal.php                            # 2. Modul Program, Batch & Jadwal Pelatihan
│   │   ├── repository.php                        # 3. Modul LMS Repository & Materi CASR Part 147
│   │   ├── lib.php                               # Skema Tabel Otomatis & Desain UI tatcwebreact
│   │   └── assets/                               # Logo Resmi TATC (tatc.png & Logo2.png)
│   └── theme/boost/templates/                    # Custom Navbar & Split-Screen Login TATC Red
│
├── tatcwebreact/                                 # Sub-folder Terpisah: Portal Web React + Next.js API
│   ├── src/                                      # Frontend React (Vite - Port 5173)
│   ├── backend/                                  # Backend Next.js API + Prisma ORM (Port 3001)
│   └── package.json
│
├── database/                                     # Backup Database MariaDB (Port 3307)
│   ├── moodle_amto.sql                           # Database Utama Moodle LMS (moodle)
│   ├── skema_amto_bulan_1_2.sql                  # Skema Khusus 3 Tabel Baru AMTO (mdl_amto_*)
│   └── tatc_web.sql                              # Database Portal React (tatc_web - 26 Tabel Prisma)
│
├── Dokumentasi_Rancangan_dan_Testing_AMTO/       # Diagram Arsitektur, ERD Database & Bukti Testing
│   ├── Konsep_Integrasi_Portal_TATC_dan_LMS_AMTO.jpg
│   ├── Struktur_Database_Website_AMTO.jpg
│   ├── Hasil_Testing_Database_Pendaftaran_AMTO.png
│   └── Hasil_Testing_Database_Repository_LMS.png
│
└── Nyalakan_TATC_dan_LMS.bat                     # Shortcut 1-Klik Menyalakan Seluruh Server
```

---

## Cara Menjalankan Sistem (Untuk Tim Full-Stack & Security)

### 1. Menyalakan Seluruh Server Sekaligus (1-Klik)
Klik 2x file **`Nyalakan_TATC_dan_LMS.bat`**, atau jalankan lewat PowerShell:
```powershell
Start-Process "C:\moodle\server\mysql\bin\mysqld.exe" -ArgumentList "--defaults-file=C:\moodle\server\mysql\bin\my.ini","--standalone","--innodb-buffer-pool-load-at-startup=0","--innodb-buffer-pool-dump-at-shutdown=0" -WindowStyle Hidden
Start-Process "C:\moodle\server\apache\bin\httpd.exe" -WindowStyle Hidden
cd "C:\moodle\server\moodle\tatcwebreact"
npm run install:all
npm run dev
```

### 2. Daftar URL & Akun Login Pengujian
- **Moodle LMS (`http://localhost:8080/my/`)**
  - **Username:** `admin` | **Password:** `Admin123@2`
- **TATC Web Portal React (`http://localhost:5173/login`)**
  - **Super Admin:** `admin@tatc.id` | **Password:** `Admin123@2`
  - **Instruktur:** `instruktur@tatc.id` | **Password:** `Admin123@2`
  - **Peserta:** `peserta@tatc.id` | **Password:** `Admin123@2`
