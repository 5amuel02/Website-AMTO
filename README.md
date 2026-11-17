# Website AMTO – Integrated Training, Learning & Certification Management System

Portal **LMS, Pelatihan, Evaluasi Kompetensi, Practical Logbook, dan Sertifikasi** untuk **Aircraft Maintenance Training Organization (AMTO)** berbasis **Moodle 5.3.1** (PHP 8.3 & MariaDB).

---

## 👥 Pembagian Tim Pengembang
1. **Full Stack Developer (Frontend, Backend & Database):**
   - Kustomisasi UI/UX Portal AMTO, Split-Screen Login Page, Role-Based Navigation & Dashboard (*Trainee* vs *Instructor* vs *Admin*), modul kursus, dan struktur database.
2. **Network & Infrastructure (Admin IT):**
   - Konfigurasi jaringan lokal/VPN (Tailscale), Web Server Apache, port & firewall, SSL/HTTPS, serta otomatisasi backup & restore sesuai SLA/RPO/RTO.
3. **Security & Compliance (Tech Lead / Security):**
   - Implementasi **8 Role RBAC (`R01`–`R08`)**, *Segregation of Duties (SoD)*, kebijakan autentikasi (`IAM-01` s.d. `IAM-11`: Password Policy, Brute-Force Lockout, Session Timeout, MFA), Audit Trail (`LOG-01` s.d. `LOG-09`), dan pengujian **15 Skenario Security UAT (`UAT-SEC-01` s.d. `UAT-SEC-15`)**.

---

## 📂 Struktur Repository
- `public/amto/index.html` — Showcase **UI/UX Prototype Interaktif (6 Layar)** sesuai dokumen spesifikasi AMTO (bisa diakses di `/amto/`).
- `public/theme/boost/templates/core/` — Template kustom **Split-Screen Login Page AMTO** (`login_layout.mustache`, `login_panel.mustache`, `loginform.mustache`).
- `public/theme/boost/classes/output/core_renderer.php` — Pengaturan tampilan saklar *Edit mode* berdasarkan role aktif.
- `public/lib/classes/navigation/` — Pemisahan menu bar atas otomatis untuk **Peserta (`student`)**, **Pengajar (`instructor`)**, dan **Admin (`admin`)**.
- `public/blocks/myoverview/classes/output/main.php` — Pemisahan tampilan *Dashboard Course Overview* untuk Peserta, Pengajar, dan Admin.
- `database/moodle_amto.sql` — Backup penuh database MariaDB `moodle` (termasuk konfigurasi tema, SCSS, dan akun awal).
- `scripts/` — Script otomatisasi penerapan tema, pemisahan role, dan sinkronisasi database.
- `config.php` — Konfigurasi koneksi database & dukungan akses multi-IP (LAN / Wi-Fi / Tailscale) serta fungsi helper `amto_get_effective_role()`.

---

## 🚀 Cara Menjalankan untuk Anggota Tim

### Opsi A: Akses Langsung via Jaringan Bersama (Wi-Fi / Tailscale)
1. Pastikan server Moodle di laptop utama sedang berjalan (`Start-Moodle.bat`).
2. Buka browser dan akses `http://<IP-Laptop-Server>:8080` (atau `http://localhost:8080` di laptop server).
3. Login menggunakan akun Admin:
   - **Username:** `admin`
   - **Password:** `Admin123@2`

### Opsi B: Clone & Pasang di Laptop Masing-Masing
1. Ekstrak `MoodleWindowsInstaller-latest-503.zip` ke `C:\moodle` dan pastikan menggunakan **PHP 8.3**.
2. Clone repository ini lalu salin seluruh isi folder ke dalam `C:\moodle\server\moodle\`.
3. Import file database `database/moodle_amto.sql` ke MariaDB (port `3307`, database `moodle`).
4. Jalankan `C:\moodle\Start-Moodle.bat` dan buka `http://localhost:8080`.
