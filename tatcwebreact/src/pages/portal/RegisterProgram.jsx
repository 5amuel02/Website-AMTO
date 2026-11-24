import React, { useState, useEffect } from 'react';
import { useAuth } from '../../context/AuthContext';
import { useNavigate, useLocation } from 'react-router-dom';
import { apiFetch } from '../../lib/api';

const RegisterProgram = () => {
  const { user } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const searchParams = new URLSearchParams(location.search);
  const programId = searchParams.get('program_id');
  const batchId = searchParams.get('batch_id');

  const [fields, setFields] = useState([]);
  const [formData, setFormData] = useState({});
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState('');

  useEffect(() => {
    if (!programId || !batchId) {
      navigate('/');
      return;
    }

    const fetchFields = async () => {
      try {
        const res = await apiFetch(`/api/portal/programs/${programId}/form`);
        const json = await res.json();
        if (json.success) {
          setFields(json.data);
          // init form data
          const initData = {};
          json.data.forEach(f => {
            initData[`field_${f.id}`] = f.field_type === 'CHECKBOX' ? [] : '';
          });
          setFormData(initData);
        }
      } catch (err) {
        console.error(err);
      } finally {
        setLoading(false);
      }
    };
    fetchFields();
  }, [programId, batchId, navigate]);

  const handleInputChange = (fieldId, type, value, checked = false) => {
    const key = `field_${fieldId}`;
    if (type === 'CHECKBOX') {
      const current = Array.isArray(formData[key]) ? formData[key] : [];
      if (checked) {
        setFormData({ ...formData, [key]: [...current, value] });
      } else {
        setFormData({ ...formData, [key]: current.filter(v => v !== value) });
      }
    } else if (type === 'FILE') {
      setFormData({ ...formData, [key]: value });
    } else {
      setFormData({ ...formData, [key]: value });
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setMessage('');

    try {
      const submitData = new FormData();
      submitData.append('batch_id', batchId);

      for (const [key, value] of Object.entries(formData)) {
        if (Array.isArray(value)) {
          submitData.append(key, value.join(', '));
        } else {
          submitData.append(key, value);
        }
      }

      const res = await apiFetch('/api/portal/registrations', {
        method: 'POST',
        body: submitData // letting browser set content-type for multipart
      });
      const json = await res.json();
      if (json.success) {
        navigate('/portal/participant?tab=registration');
      } else {
        setMessage(json.message);
      }
    } catch (err) {
      setMessage('Gagal mensubmit pendaftaran.');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) return <div style={{ padding: '2rem', textAlign: 'center' }}>Memuat formulir pendaftaran...</div>;

  return (
    <div style={{ padding: '3rem 2rem', maxWidth: '800px', margin: '0 auto' }}>
      <h1 style={{ fontSize: '2rem', fontWeight: 'bold', marginBottom: '0.5rem', color: 'var(--text-main)' }}>Formulir Pendaftaran Program</h1>
      <p style={{ color: 'var(--text-muted)', marginBottom: '2rem' }}>Silakan lengkapi data dan dokumen di bawah ini untuk melanjutkan pendaftaran.</p>

      {message && (
        <div style={{ padding: '1rem', background: '#fee2e2', color: '#991b1b', borderRadius: '8px', marginBottom: '1.5rem' }}>
          {message}
        </div>
      )}

      <form onSubmit={handleSubmit} style={{ background: 'var(--surface-color)', padding: '2rem', borderRadius: '16px', border: '1px solid var(--border-color)', boxShadow: '0 4px 6px -1px rgba(0,0,0,0.05)' }}>
        {fields.length === 0 ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
            <div style={{ padding: '1rem 1.25rem', background: 'rgba(220, 38, 38, 0.06)', border: '1px solid rgba(220, 38, 38, 0.2)', borderRadius: '12px', color: 'var(--text-main)', fontSize: '0.92rem' }}>
              <strong>Konfirmasi Pendaftaran Peserta TATC – AMTO (CASR Part 147)</strong>
              <div style={{ color: 'var(--text-muted)', marginTop: '0.25rem' }}>
                Data profil akun Anda (<strong>{user?.profile?.full_name || user?.name || user?.email}</strong> — <code>{user?.email}</code>) akan otomatis digunakan untuk pengajuan pendaftaran program ini. Setelah disubmit, Anda dapat memantau status verifikasi (<em>Sedang Diverifikasi Admin / Diterima / Perlu Revisi</em>) langsung di Dashboard Portal Peserta TATC.
              </div>
            </div>
            <div>
              <label style={{ display: 'block', marginBottom: '0.4rem', fontWeight: '600', color: 'var(--text-main)' }}>Instansi / Maskapai / Universitas Asal</label>
              <input type="text" placeholder="Contoh: Telkom University / PT Dirgantara Indonesia / Umum" value={formData.notes_instansi || ''} onChange={e => setFormData({ ...formData, notes_instansi: e.target.value })} style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--border-color)', background: 'var(--background-main)', color: 'var(--text-main)' }} />
            </div>
            <div>
              <label style={{ display: 'block', marginBottom: '0.4rem', fontWeight: '600', color: 'var(--text-main)' }}>Nomor Lisensi AMEL / Catatan Prasyarat (Opsional)</label>
              <input type="text" placeholder="Kosongkan jika mendaftar Basic License baru" value={formData.notes_amel || ''} onChange={e => setFormData({ ...formData, notes_amel: e.target.value })} style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--border-color)', background: 'var(--background-main)', color: 'var(--text-main)' }} />
            </div>
          </div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '1.5rem' }}>
            {fields.map(field => {
              const key = `field_${field.id}`;
              const options = field.options_json ? field.options_json.split(',').map(s => s.trim()) : [];

              return (
                <div key={field.id}>
                  <label style={{ display: 'block', marginBottom: '0.5rem', fontWeight: '600', color: 'var(--text-main)' }}>
                    {field.label} {field.is_required && <span style={{ color: '#ef4444' }}>*</span>}
                  </label>

                  {field.field_type === 'TEXT' && (
                    <input type="text" required={!!field.is_required} value={formData[key] || ''} onChange={e => handleInputChange(field.id, 'TEXT', e.target.value)} style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--border-color)', background: 'var(--background-main)', color: 'var(--text-main)' }} />
                  )}

                  {field.field_type === 'TEXTAREA' && (
                    <textarea required={!!field.is_required} rows="4" value={formData[key] || ''} onChange={e => handleInputChange(field.id, 'TEXTAREA', e.target.value)} style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--border-color)', background: 'var(--background-main)', color: 'var(--text-main)', resize: 'vertical' }}></textarea>
                  )}

                  {field.field_type === 'SELECT' && (
                    <select required={!!field.is_required} value={formData[key] || ''} onChange={e => handleInputChange(field.id, 'SELECT', e.target.value)} style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--border-color)', background: 'var(--background-main)', color: 'var(--text-main)' }}>
                      <option value="">-- Pilih --</option>
                      {options.map((opt, i) => <option key={i} value={opt}>{opt}</option>)}
                    </select>
                  )}

                  {field.field_type === 'RADIO' && (
                    <div style={{ display: 'flex', gap: '1.5rem', flexWrap: 'wrap' }}>
                      {options.map((opt, i) => (
                        <label key={i} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer' }}>
                          <input type="radio" name={key} required={!!field.is_required} value={opt} checked={formData[key] === opt} onChange={e => handleInputChange(field.id, 'RADIO', e.target.value)} />
                          <span>{opt}</span>
                        </label>
                      ))}
                    </div>
                  )}

                  {field.field_type === 'CHECKBOX' && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                      {options.map((opt, i) => (
                        <label key={i} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer' }}>
                          <input type="checkbox" value={opt} checked={(formData[key] || []).includes(opt)} onChange={e => handleInputChange(field.id, 'CHECKBOX', e.target.value, e.target.checked)} />
                          <span>{opt}</span>
                        </label>
                      ))}
                    </div>
                  )}

                  {field.field_type === 'FILE' && (
                    <div>
                      <input type="file" required={!!field.is_required} accept={field.allowed_extensions || ''} onChange={e => handleInputChange(field.id, 'FILE', e.target.files[0])} style={{ width: '100%', padding: '8px', borderRadius: '8px', border: '1px dashed var(--border-color)', background: 'var(--background-main)', color: 'var(--text-main)' }} />
                      {field.allowed_extensions && <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)', marginTop: '0.25rem' }}>Format diizinkan: {field.allowed_extensions}</div>}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}

        <div style={{ marginTop: '2.5rem', display: 'flex', justifyContent: 'flex-end', gap: '1rem' }}>
          <button type="button" onClick={() => navigate('/portal/participant')} style={{ padding: '12px 24px', background: 'transparent', border: '1px solid var(--border-color)', borderRadius: '8px', cursor: 'pointer', color: 'var(--text-main)', fontWeight: '600' }}>Batal</button>
          <button type="submit" disabled={submitting} className="btn btn-primary" style={{ padding: '12px 32px', borderRadius: '8px', fontSize: '1rem' }}>
            {submitting ? 'Memproses...' : 'Submit Pendaftaran Program'}
          </button>
        </div>
      </form>
    </div>
  );
};

export default RegisterProgram;
