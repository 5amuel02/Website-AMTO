import React, { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../../context/AuthContext';
import { apiFetch } from '../../lib/api';

const ParticipantRegistration = () => {
  const { user } = useAuth();
  const [schedules, setSchedules] = useState([]);
  const [registrations, setRegistrations] = useState([]);
  const [lmsAccount, setLmsAccount] = useState(null);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [showForm, setShowForm] = useState(true);
  const [notice, setNotice] = useState(null);
  const [searchQuery, setSearchQuery] = useState('');

  const defaultUsername = (user?.email ? user.email.split('@')[0].replace(/[^a-zA-Z0-9]/g, '').toLowerCase() : '');
  const defaultFullname = user?.profile?.full_name || user?.name || '';

  const [form, setForm] = useState({
    fullname: defaultFullname,
    username: defaultUsername,
    nik: user?.profile?.nik || '',
    email: user?.email || '',
    phone: user?.profile?.phone || '',
    organization: user?.profile?.institution || '',
    amel_no: '',
    password: '',
    schedule_id: '0',
  });
  const [files, setFiles] = useState({
    doc_ktp: null,
    doc_ijazah: null,
    doc_pendukung: null,
  });

  useEffect(() => {
    if (user) {
      setForm(prev => ({
        ...prev,
        fullname: prev.fullname || user.profile?.full_name || user.name || '',
        username: prev.username || (user.email ? user.email.split('@')[0].replace(/[^a-zA-Z0-9]/g, '').toLowerCase() : ''),
        nik: prev.nik || user.profile?.nik || '',
        email: prev.email || user.email || '',
        phone: prev.phone || user.profile?.phone || '',
        organization: prev.organization || user.profile?.institution || '',
      }));
    }
  }, [user]);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('lms_status') === 'unregistered') {
      setNotice({
        type: 'error',
        text: 'Akun Portal TATC Anda belum terdaftar / belum disetujui di LMS E-Learning AMTO. Silakan lengkapi Formulir Pendaftaran & Verifikasi Kelayakan Peserta di bawah ini terlebih dahulu dan tunggu persetujuan (Approve) dari Admin.'
      });
    }
  }, []);

  const fetchRegistrationData = useCallback(async (q = '') => {
    setLoading(true);
    try {
      const url = q ? `/api/portal/amto-registration?q=${encodeURIComponent(q)}` : '/api/portal/amto-registration';
      const res = await apiFetch(url);
      const json = await res.json();
      if (json.success && json.data) {
        setSchedules(json.data.schedules || []);
        const lmsAcc = json.data.lms_account || null;
        setLmsAccount(lmsAcc);
        const regs = json.data.registrations || [];
        setRegistrations(regs);
        const alreadyReg = Boolean(lmsAcc) || regs.some(r => ['approved', 'accepted', 'pending'].includes(String(r.status || '').toLowerCase()));
        setShowForm(!alreadyReg);
      }
    } catch (err) {
      console.error('Failed to load AMTO registration data:', err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchRegistrationData();
  }, [fetchRegistrationData]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setNotice(null);

    if (!form.fullname.trim() || !form.username.trim() || !form.nik.trim() || !form.email.trim() || !form.phone.trim() || !form.organization.trim()) {
      setNotice({ type: 'error', text: 'Mohon lengkapi seluruh kolom wajib bertanda bintang (*).' });
      return;
    }
    if (form.password.length < 8) {
      setNotice({ type: 'error', text: 'Password Akun LMS wajib minimal 8 karakter sesuai standar keamanan IAM-03.' });
      return;
    }

    setSubmitting(true);
    try {
      const fd = new FormData();
      Object.entries(form).forEach(([k, v]) => fd.append(k, v));
      if (files.doc_ktp) fd.append('doc_ktp', files.doc_ktp);
      if (files.doc_ijazah) fd.append('doc_ijazah', files.doc_ijazah);
      if (files.doc_pendukung) fd.append('doc_pendukung', files.doc_pendukung);

      const res = await apiFetch('/api/portal/amto-registration', {
        method: 'POST',
        body: fd,
      });
      const json = await res.json();
      if (json.success) {
        setNotice({
          type: 'success',
          text: `Registrasi & berkas verifikasi kelayakan berhasil dikirim dengan Nomor Pendaftaran ${json.data?.registration_no || ''}. Silakan pantau status verifikasi Anda pada tabel di bawah ini.`
        });
        await fetchRegistrationData();
        setShowForm(false);
      } else {
        setNotice({ type: 'error', text: json.message || 'Gagal mengirim pendaftaran.' });
      }
    } catch (err) {
      setNotice({ type: 'error', text: 'Terjadi kesalahan jaringan saat mengirim berkas pendaftaran.' });
    } finally {
      setSubmitting(false);
    }
  };

  const renderStatusBadge = (status) => {
    const st = String(status || 'pending').toLowerCase();
    if (st === 'approved' || st === 'accepted') {
      return (
        <span style={{ display: 'inline-flex', alignItems: 'center', padding: '5px 12px', borderRadius: '999px', fontSize: '0.78rem', fontWeight: 700, background: '#dcfce7', color: '#15803d', border: '1px solid #bbf7d0' }}>
          Sudah Approve / Eligible (Disetujui)
        </span>
      );
    }
    if (st === 'rejected') {
      return (
        <span style={{ display: 'inline-flex', alignItems: 'center', padding: '5px 12px', borderRadius: '999px', fontSize: '0.78rem', fontWeight: 700, background: '#fee2e2', color: '#b91c1c', border: '1px solid #fecaca' }}>
          Perlu Revisi / Ditolak
        </span>
      );
    }
    return (
      <span style={{ display: 'inline-flex', alignItems: 'center', padding: '5px 12px', borderRadius: '999px', fontSize: '0.78rem', fontWeight: 700, background: '#fef3c7', color: '#b45309', border: '1px solid #fde68a' }}>
        Sedang Dicek / Menunggu Verifikasi Admin
      </span>
    );
  };

  const inputStyle = {
    width: '100%',
    padding: '10px 14px',
    borderRadius: '10px',
    border: '1px solid var(--border-color)',
    background: 'var(--background-main)',
    color: 'var(--text-main)',
    fontSize: '0.92rem',
    outline: 'none',
  };

  const labelStyle = {
    display: 'block',
    fontSize: '0.84rem',
    fontWeight: 700,
    color: 'var(--text-main)',
    marginBottom: '6px',
  };

  const isAlreadyRegistered = Boolean(lmsAccount) || registrations.some(r => ['approved', 'accepted', 'pending'].includes(String(r.status || '').toLowerCase()));

  return (
    <div style={{ padding: '1.75rem 1.5rem 3rem', maxWidth: '1140px', margin: '0 auto' }}>
      {/* HERO BANNER */}
      <div style={{
        background: 'linear-gradient(135deg, #b91c1c 0%, #dc2626 55%, #991b1b 100%)',
        color: '#ffffff',
        borderRadius: '18px',
        padding: '1.75rem 2rem',
        marginBottom: '1.5rem',
        display: 'flex',
        flexWrap: 'wrap',
        justifyContent: 'space-between',
        alignItems: 'center',
        gap: '1rem',
        boxShadow: '0 10px 25px rgba(185, 28, 28, 0.18)'
      }}>
        <div style={{ maxWidth: '720px' }}>
          <div style={{
            display: 'inline-flex', alignItems: 'center', gap: '8px',
            background: 'rgba(255,255,255,0.16)', border: '1px solid rgba(255,255,255,0.28)',
            padding: '4px 12px', borderRadius: '999px', fontSize: '0.76rem', fontWeight: 700,
            letterSpacing: '0.04em', textTransform: 'uppercase', marginBottom: '0.65rem'
          }}>
            <span>CASR Part 147 • Participant Eligibility &amp; Registration</span>
          </div>
          <h1 style={{ fontSize: '1.65rem', fontWeight: 800, marginBottom: '0.4rem', lineHeight: 1.25 }}>
            Registrasi &amp; Verifikasi Kelayakan Peserta (Eligibility Check)
          </h1>
          <p style={{ fontSize: '0.92rem', opacity: 0.92, lineHeight: 1.55 }}>
            Lengkapi biodata sesuai KTP dan unggah dokumen prasyarat (KTP, Ijazah, Lisensi AMEL/Surat Tugas) untuk diperiksa kelayakannya oleh Admin &amp; Training Manager AMTO, serta pantau status persetujuan (<em>Sedang Dicek</em> / <em>Sudah Approve</em>) secara real-time.
          </p>
        </div>

        <div style={{ display: 'flex', gap: '0.65rem', flexWrap: 'wrap' }}>
          <button
            type="button"
            onClick={() => fetchRegistrationData(searchQuery)}
            style={{
              padding: '9px 16px', borderRadius: '10px', border: '1px solid rgba(255,255,255,0.35)',
              background: 'rgba(255,255,255,0.15)', color: '#ffffff', fontWeight: 700, fontSize: '0.86rem', cursor: 'pointer'
            }}
          >
            Refresh Status Verifikasi
          </button>
          {!isAlreadyRegistered && (
            <button
              type="button"
              onClick={() => setShowForm(v => !v)}
              style={{
                padding: '9px 16px', borderRadius: '10px', border: 'none',
                background: '#ffffff', color: '#b91c1c', fontWeight: 700, fontSize: '0.86rem', cursor: 'pointer'
              }}
            >
              {showForm ? 'Sembunyikan Form' : '+ Buka Form Registrasi Baru'}
            </button>
          )}
        </div>
      </div>

      {/* NOTIFICATION ALERT */}
      {notice && (
        <div style={{
          padding: '1rem 1.25rem',
          borderRadius: '12px',
          marginBottom: '1.5rem',
          fontWeight: 600,
          fontSize: '0.92rem',
          background: notice.type === 'success' ? '#dcfce7' : '#fee2e2',
          color: notice.type === 'success' ? '#15803d' : '#b91c1c',
          border: `1px solid ${notice.type === 'success' ? '#86efac' : '#fca5a5'}`
        }}>
          {notice.text}
        </div>
      )}

      {/* STATUS AKUN LMS E-LEARNING BANNER */}
      <div style={{
        padding: '1.15rem 1.5rem',
        borderRadius: '14px',
        marginBottom: '1.5rem',
        display: 'flex',
        flexWrap: 'wrap',
        justifyContent: 'space-between',
        alignItems: 'center',
        gap: '1rem',
        background: lmsAccount ? '#f0fdf4' : '#fffbeb',
        border: `1px solid ${lmsAccount ? '#bbf7d0' : '#fde68a'}`
      }}>
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '4px' }}>
            <span style={{
              display: 'inline-block',
              padding: '3px 10px',
              borderRadius: '999px',
              fontSize: '0.75rem',
              fontWeight: 800,
              background: lmsAccount ? '#16a34a' : '#d97706',
              color: '#ffffff'
            }}>
              {lmsAccount ? 'TERDAFTAR & AKTIF DI LMS' : 'BELUM TERDAFTAR DI LMS E-LEARNING'}
            </span>
            <strong style={{ fontSize: '0.95rem', color: lmsAccount ? '#14532d' : '#78350f' }}>
              Status Akun LMS E-Learning AMTO (localhost:8080)
            </strong>
          </div>
          <div style={{ fontSize: '0.86rem', color: lmsAccount ? '#166534' : '#92400e', lineHeight: 1.5 }}>
            {lmsAccount ? (
              <>Akun Anda sudah terdaftar di LMS dengan username <strong>{lmsAccount.username}</strong> ({lmsAccount.email}) dan siap mengakses kelas, materi, ujian CBT, serta logbook.</>
            ) : (
              <>Akun Portal TATC Anda (<strong>{user?.email}</strong>) belum memiliki akun LMS E-Learning yang terdaftar. Silakan ajukan pendaftaran di bawah ini dan tunggu persetujuan (<strong>Approve</strong>) dari Admin agar akun LMS Anda otomatis diaktifkan.</>
            )}
          </div>
        </div>
        {lmsAccount && (
          <a
            href={`http://localhost:8080/amto/sso.php?email=${encodeURIComponent(user?.email || lmsAccount.email)}&role=PESERTA&target=/my/`}
            className="btn btn-primary"
            style={{ padding: '8px 16px', borderRadius: '10px', fontSize: '0.85rem', textDecoration: 'none', whiteSpace: 'nowrap' }}
          >
            Masuk ke LMS E-Learning
          </a>
        )}
      </div>

      {/* PANEL 1: TABEL PANTAU STATUS REGISTRASI & ELIGIBILITY PESERTA */}
      <div style={{
        background: 'var(--surface-color)',
        border: '1px solid var(--border-color)',
        borderRadius: '16px',
        padding: '1.5rem',
        marginBottom: '1.75rem',
        boxShadow: '0 4px 14px rgba(15, 23, 42, 0.03)'
      }}>
        <div style={{
          display: 'flex', flexWrap: 'wrap', justifyContent: 'space-between', alignItems: 'center',
          gap: '1rem', paddingBottom: '1rem', marginBottom: '1.25rem', borderBottom: '1px solid var(--border-color)'
        }}>
          <div>
            <h2 style={{ fontSize: '1.15rem', fontWeight: 800, color: 'var(--text-main)', marginBottom: '0.2rem' }}>
              Status &amp; Hasil Verifikasi Pendaftaran Saya
            </h2>
            <p style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>
              Pantau apakah berkas pendaftaran Anda sedang dicek oleh Admin, sudah disetujui (Approve), atau memerlukan revisi.
            </p>
          </div>
          <form
            onSubmit={(e) => { e.preventDefault(); fetchRegistrationData(searchQuery); }}
            style={{ display: 'flex', gap: '0.5rem' }}
          >
            <input
              type="text"
              placeholder="Cari No. Reg / Username..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              style={{ ...inputStyle, width: '210px', padding: '7px 12px', fontSize: '0.85rem' }}
            />
            <button type="submit" className="btn btn-primary" style={{ padding: '7px 14px', borderRadius: '8px', fontSize: '0.85rem' }}>
              Cek Status
            </button>
          </form>
        </div>

        {loading ? (
          <div style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>
            Memuat data status registrasi...
          </div>
        ) : registrations.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '2rem 1rem', color: 'var(--text-muted)' }}>
            <div style={{ fontWeight: 700, color: 'var(--text-main)', marginBottom: '0.35rem', fontSize: '1rem' }}>
              Belum Ada Pengajuan Registrasi / Verifikasi Kelayakan
            </div>
            <p style={{ fontSize: '0.88rem', maxWidth: '560px', margin: '0 auto' }}>
              Silakan isi <strong>Formulir Pendaftaran &amp; Verifikasi Kelayakan Peserta</strong> di bawah ini untuk mengajukan pemeriksaan berkas prasyarat Anda.
            </p>
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.88rem', textAlign: 'left' }}>
              <thead>
                <tr style={{ background: 'var(--surface-hover)', borderBottom: '2px solid var(--border-color)' }}>
                  <th style={{ padding: '12px 14px', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', fontSize: '0.76rem' }}>No. Registrasi</th>
                  <th style={{ padding: '12px 14px', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', fontSize: '0.76rem' }}>Data Peserta</th>
                  <th style={{ padding: '12px 14px', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', fontSize: '0.76rem' }}>Instansi &amp; AMEL</th>
                  <th style={{ padding: '12px 14px', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', fontSize: '0.76rem' }}>Tujuan / Program</th>
                  <th style={{ padding: '12px 14px', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', fontSize: '0.76rem' }}>Berkas Terunggah</th>
                  <th style={{ padding: '12px 14px', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', fontSize: '0.76rem' }}>Status Verifikasi</th>
                  <th style={{ padding: '12px 14px', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', fontSize: '0.76rem' }}>Aksi</th>
                </tr>
              </thead>
              <tbody>
                {registrations.map((r) => (
                  <tr key={r.id} style={{ borderBottom: '1px solid var(--border-color)' }}>
                    <td style={{ padding: '14px' }}>
                      <strong style={{ color: '#b91c1c', fontSize: '0.9rem' }}>{r.registration_no}</strong>
                      <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)', marginTop: '2px' }}>
                        {r.timecreated ? new Date(Number(r.timecreated) * 1000).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '-'}
                      </div>
                    </td>
                    <td style={{ padding: '14px' }}>
                      <div style={{ fontWeight: 700, color: 'var(--text-main)' }}>{r.fullname}</div>
                      <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                        Username: <code>{r.username}</code> • NIK: {r.nik}
                      </div>
                      <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                        {r.email} • {r.phone}
                      </div>
                    </td>
                    <td style={{ padding: '14px' }}>
                      <div style={{ fontWeight: 600 }}>{r.organization}</div>
                      <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)' }}>
                        {r.amel_no ? `AMEL: ${r.amel_no}` : 'Non-AMEL / Basic Baru'}
                      </div>
                    </td>
                    <td style={{ padding: '14px', fontWeight: 600, maxWidth: '220px' }}>
                      {r.program_name}
                    </td>
                    <td style={{ padding: '14px' }}>
                      <div style={{ display: 'flex', gap: '4px', flexWrap: 'wrap' }}>
                        {r.doc_ktp && <span style={{ padding: '3px 8px', borderRadius: '6px', background: '#f1f5f9', fontSize: '0.75rem', fontWeight: 600 }}>KTP ✓</span>}
                        {r.doc_ijazah && <span style={{ padding: '3px 8px', borderRadius: '6px', background: '#f1f5f9', fontSize: '0.75rem', fontWeight: 600 }}>Ijazah ✓</span>}
                        {r.doc_pendukung && <span style={{ padding: '3px 8px', borderRadius: '6px', background: '#f1f5f9', fontSize: '0.75rem', fontWeight: 600 }}>Surat/Foto ✓</span>}
                        {!r.doc_ktp && !r.doc_ijazah && !r.doc_pendukung && (
                          <span style={{ fontSize: '0.78rem', color: 'var(--text-muted)' }}>Data Digital</span>
                        )}
                      </div>
                    </td>
                    <td style={{ padding: '14px' }}>
                      {renderStatusBadge(r.status)}
                      {r.admin_notes && (
                        <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)', marginTop: '5px', maxWidth: '230px' }}>
                          {r.admin_notes}
                        </div>
                      )}
                    </td>
                    <td style={{ padding: '14px' }}>
                      {String(r.status).toLowerCase() === 'approved' ? (
                        <a
                          href={`http://localhost:8080/amto/sso.php?email=${encodeURIComponent(user?.email || r.email)}&role=PESERTA&target=/my/`}
                          className="btn btn-primary"
                          style={{ padding: '7px 14px', borderRadius: '8px', fontSize: '0.8rem', textDecoration: 'none', whiteSpace: 'nowrap' }}
                        >
                          Buka LMS Belajar
                        </a>
                      ) : (
                        <button
                          type="button"
                          onClick={() => fetchRegistrationData(searchQuery)}
                          style={{ padding: '6px 12px', borderRadius: '8px', border: '1px solid var(--border-color)', background: 'var(--background-main)', color: 'var(--text-main)', fontSize: '0.8rem', fontWeight: 600, cursor: 'pointer', whiteSpace: 'nowrap' }}
                        >
                          Cek Ulang Status
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* PANEL 2: FORMULIR PENDAFTARAN & VERIFIKASI KELAYAKAN PESERTA (HANYA MUNCUL JIKA BELUM TERDAFTAR) */}
      {!isAlreadyRegistered && showForm && (
        <div style={{
          background: 'var(--surface-color)',
          border: '1px solid var(--border-color)',
          borderRadius: '16px',
          padding: '1.75rem 2rem',
          boxShadow: '0 4px 14px rgba(15, 23, 42, 0.04)'
        }}>
          <div style={{
            display: 'flex', flexWrap: 'wrap', justifyContent: 'space-between', alignItems: 'center',
            gap: '1rem', paddingBottom: '1rem', marginBottom: '1.5rem', borderBottom: '1px solid var(--border-color)'
          }}>
            <div>
              <h2 style={{ fontSize: '1.2rem', fontWeight: 800, color: 'var(--text-main)', marginBottom: '0.25rem' }}>
                Formulir Pendaftaran &amp; Verifikasi Kelayakan Peserta (Eligibility Check AMTO)
              </h2>
              <p style={{ fontSize: '0.86rem', color: 'var(--text-muted)' }}>
                Lengkapi identitas sesuai KTP dan unggah dokumen prasyarat (KTP, Ijazah, AMEL/Surat Tugas — PDF/JPG/PNG, Maks. 5 MB) untuk diperiksa kelayakannya oleh Admin / Training Manager.
              </p>
            </div>
            <span style={{ padding: '5px 12px', borderRadius: '999px', fontSize: '0.78rem', fontWeight: 700, background: '#dcfce7', color: '#15803d' }}>
              Standar IAM-03 &amp; Validasi Berkas
            </span>
          </div>

          <form onSubmit={handleSubmit}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: '1.15rem', marginBottom: '1.15rem' }}>
              <div>
                <label style={labelStyle}>Nama Lengkap (Sesuai KTP &amp; Sertifikat) *</label>
                <input
                  type="text"
                  style={inputStyle}
                  placeholder="Contoh: Andi Pratama Wijaya"
                  value={form.fullname}
                  onChange={(e) => setForm({ ...form, fullname: e.target.value })}
                  required
                />
              </div>

              <div>
                <label style={labelStyle}>Username Akun LMS *</label>
                <input
                  type="text"
                  style={inputStyle}
                  placeholder="Contoh: andipratama (huruf kecil tanpa spasi)"
                  value={form.username}
                  onChange={(e) => setForm({ ...form, username: e.target.value.toLowerCase().replace(/\s+/g, '') })}
                  required
                />
              </div>

              <div>
                <label style={labelStyle}>NIK (Nomor Induk Kependudukan) *</label>
                <input
                  type="text"
                  style={inputStyle}
                  placeholder="16 Digit NIK KTP"
                  value={form.nik}
                  onChange={(e) => setForm({ ...form, nik: e.target.value })}
                  required
                />
              </div>

              <div>
                <label style={labelStyle}>Alamat Email Resmi *</label>
                <input
                  type="email"
                  style={inputStyle}
                  placeholder="nama@perusahaan.com"
                  value={form.email}
                  onChange={(e) => setForm({ ...form, email: e.target.value })}
                  required
                />
              </div>

              <div>
                <label style={labelStyle}>Nomor Telepon / WhatsApp *</label>
                <input
                  type="text"
                  style={inputStyle}
                  placeholder="0812xxxxxxxx"
                  value={form.phone}
                  onChange={(e) => setForm({ ...form, phone: e.target.value })}
                  required
                />
              </div>

              <div>
                <label style={labelStyle}>Asal Instansi / Maskapai / Mandiri *</label>
                <input
                  type="text"
                  style={inputStyle}
                  placeholder="Contoh: Telkom University / GMF AeroAsia"
                  value={form.organization}
                  onChange={(e) => setForm({ ...form, organization: e.target.value })}
                  required
                />
              </div>

              <div>
                <label style={labelStyle}>Nomor Lisensi AMEL / Basic Certificate (Opsional)</label>
                <input
                  type="text"
                  style={inputStyle}
                  placeholder="Kosongkan jika belum memiliki AMEL"
                  value={form.amel_no}
                  onChange={(e) => setForm({ ...form, amel_no: e.target.value })}
                />
              </div>

              <div>
                <label style={labelStyle}>Password Akun LMS (Min. 8 Karakter - Standar IAM-03) *</label>
                <input
                  type="password"
                  style={inputStyle}
                  placeholder="Min. 8 karakter kombinasi huruf & angka"
                  value={form.password}
                  onChange={(e) => setForm({ ...form, password: e.target.value })}
                  required
                />
              </div>
            </div>

            <div style={{ marginBottom: '1.15rem' }}>
              <label style={labelStyle}>Tujuan Verifikasi Kelayakan / Jadwal Pelatihan (Opsional jika jadwal belum dibuat)</label>
              <select
                style={inputStyle}
                value={form.schedule_id}
                onChange={(e) => setForm({ ...form, schedule_id: e.target.value })}
              >
                <option value="0">Verifikasi Kelayakan Peserta Umum (Eligibility Check AMTO CASR Part 147)</option>
                {schedules.map((s) => (
                  <option key={s.id} value={s.id}>
                    [{s.batch_code}] {s.program_name} — Mulai: {s.start_date} ({s.room_location})
                  </option>
                ))}
              </select>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: '1.15rem', marginBottom: '1.5rem' }}>
              <div>
                <label style={labelStyle}>Unggah Scan KTP / ID Card (PDF/JPG/PNG, Maks 5MB)</label>
                <input
                  type="file"
                  style={inputStyle}
                  accept=".pdf,.jpg,.jpeg,.png"
                  onChange={(e) => setFiles({ ...files, doc_ktp: e.target.files?.[0] || null })}
                />
              </div>

              <div>
                <label style={labelStyle}>Unggah Ijazah Terakhir / Basic Certificate (PDF/JPG/PNG)</label>
                <input
                  type="file"
                  style={inputStyle}
                  accept=".pdf,.jpg,.jpeg,.png"
                  onChange={(e) => setFiles({ ...files, doc_ijazah: e.target.files?.[0] || null })}
                />
              </div>

              <div>
                <label style={labelStyle}>Unggah Surat Tugas / Pas Foto (PDF/JPG/PNG)</label>
                <input
                  type="file"
                  style={inputStyle}
                  accept=".pdf,.jpg,.jpeg,.png"
                  onChange={(e) => setFiles({ ...files, doc_pendukung: e.target.files?.[0] || null })}
                />
              </div>
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.75rem' }}>
              <button
                type="submit"
                disabled={submitting}
                className="btn btn-primary"
                style={{ padding: '11px 24px', borderRadius: '10px', fontSize: '0.92rem', fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: '8px' }}
              >
                <span>{submitting ? 'Mengirim Berkas...' : 'Kirim Berkas Verifikasi Kelayakan Peserta'}</span>
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
};

export default ParticipantRegistration;
