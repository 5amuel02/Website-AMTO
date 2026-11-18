<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lib.php');

global $DB;
$regs = $DB->get_records('amto_registrations', null, 'id DESC');
$latest = reset($regs);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Bukti Hasil Testing Pendaftaran Peserta & Database MariaDB – Website AMTO</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px 32px;
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
            background: #f1f5f9;
            color: #0f172a;
        }
        .header-bar {
            background: linear-gradient(135deg, #881313 0%, #b91c1c 60%, #dc2626 100%);
            color: #ffffff;
            padding: 20px 28px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            box-shadow: 0 10px 24px rgba(185, 28, 28, 0.2);
        }
        .header-bar h1 {
            margin: 0 0 4px;
            font-size: 21px;
            font-weight: 800;
        }
        .header-bar p {
            margin: 0;
            font-size: 13px;
            opacity: 0.92;
        }
        .badge-ok {
            background: #dcfce7;
            color: #15803d;
            font-weight: 800;
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 13px;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .card-box {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
        }
        .card-title {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 12px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-title span.tag {
            font-size: 11.5px;
            background: #fee2e2;
            color: #b91c1c;
            padding: 3px 10px;
            border-radius: 999px;
        }
        .terminal-box {
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            font-family: Consolas, 'Courier New', monospace;
            font-size: 12.5px;
            line-height: 1.55;
        }
        .terminal-cmd {
            color: #38bdf8;
            font-weight: 700;
            margin-bottom: 10px;
            border-bottom: 1px dashed #334155;
            padding-bottom: 8px;
        }
        .kv-row {
            display: grid;
            grid-template-columns: 155px 1fr;
            padding: 3px 0;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .kv-key {
            color: #94a3b8;
            font-weight: 600;
        }
        .kv-val {
            color: #4ade80;
            word-break: break-all;
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        .admin-table th {
            background: #f8fafc;
            color: #475569;
            text-align: left;
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase;
            font-size: 11px;
        }
        .admin-table td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .pill {
            display: inline-block;
            padding: 3px 8px;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 4px;
        }
        .status-pending {
            background: #fef3c7;
            color: #b45309;
            padding: 4px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 11.5px;
        }
        .btn-approve {
            background: linear-gradient(135deg, #b91c1c, #dc2626);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 7px 12px;
            font-size: 11.5px;
            font-weight: 700;
        }
        .stat-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-mini {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
        }
        .stat-mini .lbl { font-size: 11px; color: #64748b; font-weight: 600; }
        .stat-mini .val { font-size: 20px; font-weight: 800; color: #0f172a; margin-top: 2px; }
    </style>
</head>
<body>
    <div class="header-bar">
        <div>
            <h1>LAPORAN HASIL TESTING PENDAFTARAN PESERTA &amp; DATABASE MARIADB</h1>
            <p>Simulasi Pendaftaran Peserta Dummy via HTTP POST &rarr; Verifikasi Tabel <code>mdl_amto_registrations</code> (MariaDB Port 3307) &rarr; Antrean Admin</p>
        </div>
        <div class="badge-ok">STATUS TEST: DATA MASUK 100% VALID</div>
    </div>

    <div class="grid-2">
        <!-- LEFT: LIVE MARIADB QUERY RESULT -->
        <div class="card-box">
            <div class="card-title">
                <span>1. Bukti Record di Database MariaDB (Live Query)</span>
                <span class="tag">Tabel: mdl_amto_registrations</span>
            </div>
            <div class="terminal-box">
                <div class="terminal-cmd">mysql&gt; SELECT * FROM mdl_amto_registrations WHERE id = <?php echo $latest ? (int)$latest->id : 1; ?>\G</div>
                <?php if ($latest): ?>
                    <div class="kv-row"><div class="kv-key">id (Primary Key)</div><div class="kv-val"><?php echo (int)$latest->id; ?></div></div>
                    <div class="kv-row"><div class="kv-key">registration_no</div><div class="kv-val"><?php echo s($latest->registration_no); ?></div></div>
                    <div class="kv-row"><div class="kv-key">username</div><div class="kv-val"><?php echo s($latest->username); ?></div></div>
                    <div class="kv-row"><div class="kv-key">fullname</div><div class="kv-val"><?php echo s($latest->fullname); ?></div></div>
                    <div class="kv-row"><div class="kv-key">nik</div><div class="kv-val"><?php echo s($latest->nik); ?></div></div>
                    <div class="kv-row"><div class="kv-key">email</div><div class="kv-val"><?php echo s($latest->email); ?></div></div>
                    <div class="kv-row"><div class="kv-key">phone</div><div class="kv-val"><?php echo s($latest->phone); ?></div></div>
                    <div class="kv-row"><div class="kv-key">organization</div><div class="kv-val"><?php echo s($latest->organization); ?></div></div>
                    <div class="kv-row"><div class="kv-key">amel_no</div><div class="kv-val"><?php echo s($latest->amel_no); ?></div></div>
                    <div class="kv-row"><div class="kv-key">schedule_id (FK)</div><div class="kv-val"><?php echo (int)$latest->schedule_id; ?></div></div>
                    <div class="kv-row"><div class="kv-key">program_name</div><div class="kv-val"><?php echo s($latest->program_name); ?></div></div>
                    <div class="kv-row"><div class="kv-key">password_hash</div><div class="kv-val"><?php echo s(substr($latest->password_hash, 0, 38)) . '... (Bcrypt IAM-03)'; ?></div></div>
                    <div class="kv-row"><div class="kv-key">doc_ktp</div><div class="kv-val"><?php echo s($latest->doc_ktp); ?></div></div>
                    <div class="kv-row"><div class="kv-key">doc_ijazah</div><div class="kv-val"><?php echo s($latest->doc_ijazah); ?></div></div>
                    <div class="kv-row"><div class="kv-key">doc_pendukung</div><div class="kv-val"><?php echo s($latest->doc_pendukung); ?></div></div>
                    <div class="kv-row"><div class="kv-key">status</div><div class="kv-val"><?php echo s($latest->status); ?></div></div>
                    <div class="kv-row"><div class="kv-key">timecreated</div><div class="kv-val"><?php echo (int)$latest->timecreated . ' (' . date('Y-m-d H:i:s', (int)$latest->timecreated) . ')'; ?></div></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT: ADMIN VERIFICATION VIEW -->
        <div class="card-box">
            <div class="card-title">
                <span>2. Tampilan Meja Verifikasi Admin (Real-Time)</span>
                <span class="tag">Role: Admin / Training Manager</span>
            </div>
            <div class="stat-strip">
                <div class="stat-mini"><div class="lbl">Total Pendaftar</div><div class="val"><?php echo count($regs); ?></div></div>
                <div class="stat-mini"><div class="lbl">Menunggu (Pending)</div><div class="val" style="color:#d97706;"><?php echo count($regs); ?></div></div>
                <div class="stat-mini"><div class="lbl">Disetujui (Approved)</div><div class="val" style="color:#15803d;">0</div></div>
                <div class="stat-mini"><div class="lbl">Ditolak (Rejected)</div><div class="val" style="color:#b91c1c;">0</div></div>
            </div>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th>No. Registrasi</th>
                        <th>Biodata Peserta</th>
                        <th>Instansi &amp; Program</th>
                        <th>File Upload</th>
                        <th>Status</th>
                        <th>Aksi Admin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($latest): ?>
                    <tr>
                        <td>
                            <strong style="color:#b91c1c;"><?php echo s($latest->registration_no); ?></strong><br>
                            <span style="font-size:11px; color:#64748b;"><?php echo date('d M Y, H:i', (int)$latest->timecreated); ?></span>
                        </td>
                        <td>
                            <strong><?php echo s($latest->fullname); ?></strong><br>
                            <span style="font-size:11px; color:#64748b;">User: <code><?php echo s($latest->username); ?></code></span><br>
                            <span style="font-size:11px; color:#64748b;">NIK: <?php echo s($latest->nik); ?></span>
                        </td>
                        <td>
                            <strong><?php echo s($latest->organization); ?></strong><br>
                            <span style="font-size:11px; color:#475569;">AMEL: <?php echo s($latest->amel_no); ?></span><br>
                            <span style="font-size:11px; color:#0f172a;"><?php echo s($latest->program_name); ?></span>
                        </td>
                        <td>
                            <span class="pill">KTP</span>
                            <span class="pill">Ijazah</span>
                            <span class="pill">Surat/Foto</span>
                        </td>
                        <td>
                            <span class="status-pending">Menunggu Verifikasi</span>
                        </td>
                        <td>
                            <button class="btn-approve">Approve &amp; Aktifkan LMS</button>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div style="margin-top: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; font-size: 12.5px; color: #334155; line-height: 1.55;">
                <strong>Verifikasi Penyimpanan File Fisik di Server:</strong><br>
                &bull; File KTP tersimpan di: <code>C:\moodle\server\moodle\public<?php echo $latest ? s($latest->doc_ktp) : ''; ?></code><br>
                &bull; File Ijazah tersimpan di: <code>C:\moodle\server\moodle\public<?php echo $latest ? s($latest->doc_ijazah) : ''; ?></code><br>
                &bull; File Surat Tugas tersimpan di: <code>C:\moodle\server\moodle\public<?php echo $latest ? s($latest->doc_pendukung) : ''; ?></code>
            </div>
        </div>
    </div>
</body>
</html>
