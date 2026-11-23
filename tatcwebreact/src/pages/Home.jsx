import React, { useState, useEffect, useRef } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { apiFetch } from '../lib/api';

function Home() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [scrolled, setScrolled] = useState(false);
  const [activeSection, setActiveSection] = useState(() => {
    const h = window.location.hash ? window.location.hash.replace('#', '') : '';
    return ['home', 'programs', 'about', 'news', 'contacts'].includes(h) ? h : 'home';
  });
  const clickLockUntil = useRef(0);
  const [banners, setBanners] = useState([]);
  const [partners, setPartners] = useState([]);
  const [recentNews, setRecentNews] = useState([]);
  const [openPrograms, setOpenPrograms] = useState([]);
  const [selectedProgram, setSelectedProgram] = useState(null);

  useEffect(() => {
    const detectSectionFromScroll = () => {
      setScrolled(window.scrollY > 20);
      if (Date.now() < clickLockUntil.current) return;

      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 100) {
        setActiveSection('contacts');
        return;
      }
      if (window.scrollY < 220) {
        setActiveSection('home');
        return;
      }

      const sectionOrder = ['contacts', 'news', 'about', 'programs'];
      for (const sec of sectionOrder) {
        const el = document.getElementById(sec);
        if (el) {
          const rect = el.getBoundingClientRect();
          if (rect.top <= 200) {
            setActiveSection(sec);
            return;
          }
        }
      }
      setActiveSection('home');
    };

    const handleHashChange = () => {
      const h = window.location.hash ? window.location.hash.replace('#', '') : 'home';
      if (['home', 'programs', 'about', 'news', 'contacts'].includes(h)) {
        clickLockUntil.current = Date.now() + 700;
        setActiveSection(h);
      }
    };

    detectSectionFromScroll();
    window.addEventListener('scroll', detectSectionFromScroll, { passive: true });
    window.addEventListener('hashchange', handleHashChange);

    // Fetch CMS Data
    apiFetch('/api/cms/banners?public=true')
      .then(res => res.json())
      .then(json => { if (json.success) setBanners(json.data); });

    apiFetch('/api/cms/partners?public=true')
      .then(res => res.json())
      .then(json => { if (json.success) setPartners(json.data); });

    apiFetch('/api/cms/news?public=true')
      .then(res => res.json())
      .then(json => {
        if (json.success) {
          // Hanya ambil 3 berita terbaru untuk beranda
          setRecentNews(json.data.slice(0, 3));
        }
      });

    apiFetch('/api/public/open-programs')
      .then(res => res.json())
      .then(json => { if (json.success) setOpenPrograms(json.data); });

    return () => {
      window.removeEventListener('scroll', detectSectionFromScroll);
      window.removeEventListener('hashchange', handleHashChange);
    };
  }, []);

  const handleNavClick = (section) => {
    clickLockUntil.current = Date.now() + 750;
    setActiveSection(section);
  };

  const getNavItemStyle = (sectionKey) => {
    const isActive = activeSection === sectionKey;
    if (isActive) {
      return {
        display: 'inline-flex',
        alignItems: 'center',
        color: scrolled ? '#dc2626' : '#ffffff',
        background: scrolled ? 'rgba(220, 38, 38, 0.1)' : 'rgba(220, 38, 38, 0.88)',
        border: scrolled ? '1px solid rgba(220, 38, 38, 0.28)' : '1px solid rgba(255, 255, 255, 0.3)',
        padding: '6px 14px',
        borderRadius: '999px',
        textDecoration: 'none',
        fontSize: '0.9rem',
        fontWeight: '700',
        whiteSpace: 'nowrap',
        transition: 'all 0.2s ease'
      };
    }
    return {
      display: 'inline-flex',
      alignItems: 'center',
      color: scrolled ? 'var(--navbar-text)' : '#ffffff',
      opacity: scrolled ? 0.88 : 0.92,
      background: 'transparent',
      border: '1px solid transparent',
      padding: '6px 12px',
      borderRadius: '999px',
      textDecoration: 'none',
      fontSize: '0.9rem',
      fontWeight: '500',
      whiteSpace: 'nowrap',
      transition: 'all 0.2s ease'
    };
  };

  return (
    <>
      {/* Navigation */}
      <nav style={{
        height: '76px',
        display: 'flex',
        alignItems: 'center',
        background: scrolled ? 'var(--navbar-bg)' : 'rgba(15, 23, 42, 0.65)',
        backdropFilter: 'blur(16px)',
        WebkitBackdropFilter: 'blur(16px)',
        borderBottom: scrolled ? '1px solid var(--navbar-border)' : '1px solid rgba(255, 255, 255, 0.12)',
        boxShadow: scrolled ? '0 4px 20px -2px rgba(15,23,42,0.1)' : '0 4px 20px -2px rgba(0,0,0,0.2)',
        position: 'fixed', width: '100%', top: 0, zIndex: 50, transition: 'all 0.3s ease'
      }}>
        <div style={{ maxWidth: '1340px', width: '100%', margin: '0 auto', padding: '0 1.75rem', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '1.25rem', whiteSpace: 'nowrap' }}>
          {/* Left: Brand Logo */}
          <Link to="/" onClick={() => handleNavClick('home')} style={{ display: 'flex', alignItems: 'center', flexShrink: 0, textDecoration: 'none', background: scrolled ? 'transparent' : 'rgba(255, 255, 255, 0.95)', padding: scrolled ? '0' : '6px 14px', borderRadius: '10px', boxShadow: scrolled ? 'none' : '0 2px 10px rgba(0,0,0,0.15)', transition: 'all 0.3s ease' }}>
            <img src="/tatc.png" alt="Tel-U ATC Logo" style={{ height: '38px', objectFit: 'contain', display: 'block' }} />
          </Link>

          {/* Center: Navigation Links */}
          <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center', whiteSpace: 'nowrap' }}>
            <a href="#" onClick={() => handleNavClick('home')} style={getNavItemStyle('home')}>Home</a>
            <a href="#programs" onClick={() => handleNavClick('programs')} style={getNavItemStyle('programs')}>Programs</a>
            <a href="#about" onClick={() => handleNavClick('about')} style={getNavItemStyle('about')}>About Us</a>
            <a href="#news" onClick={() => handleNavClick('news')} style={getNavItemStyle('news')}>News</a>
            <a href="#contacts" onClick={() => handleNavClick('contacts')} style={getNavItemStyle('contacts')}>Contacts</a>
            {user && (
              <Link
                to={user.role === 'SUPERADMIN' || user.role === 'ADMIN' ? '/admin/registrations' : '/portal/participant/registration'}
                style={getNavItemStyle('registrasi')}
              >
                Registrasi &amp; Status
              </Link>
            )}
            <a
              href={user ? `http://localhost:8080/amto/sso.php?email=${encodeURIComponent(user.email || '')}&role=${encodeURIComponent(user.role || 'PESERTA')}&target=/my/` : "http://localhost:8080/amto/sso.php?target=/my/"}
              style={getNavItemStyle('lms')}
            >
              <span>LMS E-Learning</span>
            </a>
          </div>

          {/* Right: User Greeting & Action Buttons */}
          <div style={{ display: 'flex', gap: '0.65rem', alignItems: 'center', flexShrink: 0, whiteSpace: 'nowrap' }}>
            {user ? (
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.65rem', whiteSpace: 'nowrap' }}>
                <div style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '8px',
                  padding: '5px 12px 5px 6px',
                  borderRadius: '999px',
                  background: scrolled ? 'rgba(15, 23, 42, 0.05)' : 'rgba(255, 255, 255, 0.14)',
                  border: scrolled ? '1px solid rgba(15, 23, 42, 0.08)' : '1px solid rgba(255, 255, 255, 0.2)'
                }}>
                  <span style={{
                    width: '26px',
                    height: '26px',
                    borderRadius: '50%',
                    background: '#dc2626',
                    color: '#ffffff',
                    display: 'inline-flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: '0.78rem',
                    fontWeight: '700',
                    flexShrink: 0
                  }}>
                    {(user.profile?.full_name || user.name || user.email || 'U').trim().charAt(0).toUpperCase()}
                  </span>
                  <span style={{
                    color: scrolled ? 'var(--navbar-text)' : '#ffffff',
                    fontSize: '0.86rem',
                    fontWeight: '600',
                    maxWidth: '150px',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap'
                  }} title={user.profile?.full_name || user.name || user.email}>
                    {user.profile?.full_name || user.name || user.email.split('@')[0]}
                  </span>
                </div>
                <Link to={user.role === 'SUPERADMIN' || user.role === 'ADMIN' ? '/admin' : (user.role === 'INSTRUKTUR' ? '/portal/instructor' : '/portal/participant')} className="btn btn-primary" style={{ padding: '7px 15px', fontSize: '0.85rem', whiteSpace: 'nowrap', borderRadius: '8px' }}>Dashboard</Link>
                <button onClick={async () => { await logout(); navigate('/login'); }} className="btn" style={{ padding: '7px 14px', fontSize: '0.85rem', whiteSpace: 'nowrap', borderRadius: '8px', color: scrolled ? '#dc2626' : '#fecaca', border: `1px solid ${scrolled ? '#fca5a5' : 'rgba(254, 202, 202, 0.5)'}`, background: scrolled ? 'rgba(254, 226, 226, 0.4)' : 'rgba(15, 23, 42, 0.25)', cursor: 'pointer' }}>Logout</button>
              </div>
            ) : (
              <>
                <Link to="/login" className="btn" style={{ padding: '7px 18px', fontSize: '0.88rem', whiteSpace: 'nowrap', borderRadius: '8px', color: scrolled ? 'var(--primary-color)' : '#ffffff', border: `1px solid ${scrolled ? 'var(--primary-color)' : '#ffffff'}`, background: 'transparent' }}>Log In</Link>
                <Link to="/register" className="btn btn-primary" style={{ padding: '7px 18px', fontSize: '0.88rem', whiteSpace: 'nowrap', borderRadius: '8px' }}>Sign Up</Link>
              </>
            )}
          </div>
        </div>
      </nav>

      <main>
        {/* Hero Section */}
        <div className="hero-section" style={banners.length > 0 ? { backgroundImage: `linear-gradient(rgba(15, 23, 42, 0.75), rgba(15, 23, 42, 0.9)), url('${banners[0].image_url}')` } : {}}>
          <div style={{ maxWidth: '1200px', padding: '0 2rem' }}>
            <h1 className="hero-title animate-fade-in-up delay-100">
              {banners.length > 0 ? banners[0].title.split('\\n').map((line, i, arr) => <React.Fragment key={i}>{line}{i < arr.length - 1 && <br />}</React.Fragment>) : 'Telkom University Aviation Training Center'}
            </h1>

            <p className="hero-subtitle animate-fade-in-up delay-200">
              {banners[0]?.subtitle?.trim() || 'Category C IERA (Radio, Instrument, Electrical) Approved AMTO No.147D-19'}
            </p>

            <div className="animate-fade-in-up delay-300" style={{ display: 'flex', gap: '1rem', justifyContent: 'center', marginTop: '2.5rem' }}>
              {!user ? (
                <Link to="/login" className="btn btn-primary" style={{ padding: '12px 32px', fontSize: '1.1rem' }}>
                  Daftar Sekarang
                </Link>
              ) : (
                <Link to={user.role === 'SUPERADMIN' || user.role === 'ADMIN' ? '/admin' : (user.role === 'INSTRUKTUR' ? '/portal/instructor' : '/portal/participant')} className="btn btn-primary" style={{ padding: '12px 32px', fontSize: '1.1rem' }}>
                  Masuk Dashboard
                </Link>
              )}
            </div>
          </div>
        </div>

        {/* Media Partners - Moved up for Trust */}
        <div style={{ background: 'var(--surface-color)', padding: '3rem 2rem', borderBottom: '1px solid var(--border-color)' }}>
          <div style={{ maxWidth: '1200px', margin: '0 auto', textAlign: 'center' }}>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem', fontWeight: '600', textTransform: 'uppercase', letterSpacing: '1px', marginBottom: '1.5rem' }}>Dipercaya oleh</p>
            <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'center', alignItems: 'center', gap: '4rem' }}>
              {partners.length > 0 ? (
                partners.map(p => (
                  <img key={p.id} src={p.logo_url} alt={p.name} style={{ maxHeight: '50px', objectFit: 'contain' }} title={p.name} />
                ))
              ) : (
                <>
                  <img src="/LionGroupIndonesia.png" alt="Lion Group" style={{ maxHeight: '50px', objectFit: 'contain' }} />
                  <img src="/Turkish airlanes.jpg" alt="Turkish Airlines" style={{ maxHeight: '50px', objectFit: 'contain' }} />
                  <img src="/PT Dirgantara Indonesia.jpg" alt="PT Dirgantara Indonesia" style={{ maxHeight: '50px', objectFit: 'contain' }} />
                  <img src="/PT NTP.png" alt="PT NTP" style={{ maxHeight: '50px', objectFit: 'contain' }} />
                </>
              )}
            </div>
          </div>
        </div>

        {/* Decorative Divider */}
        <div style={{ width: '100%', height: '1px', background: 'linear-gradient(to right, transparent, rgba(0,0,0,0.1), transparent)', margin: '0 auto 4rem' }}></div>

        {/* Open Programs Section - Core Offerings */}
        <div id="programs" style={{ scrollMarginTop: '90px' }}>
          {openPrograms.length > 0 && (
            <>
              <div style={{ width: '100%', padding: '0 2rem 6rem', textAlign: 'center' }}>
                <h2 style={{ fontSize: '2.5rem', fontWeight: '800', color: 'var(--text-main)', marginBottom: '0.5rem' }}>Program yang Dibuka</h2>
                <p style={{ color: 'var(--text-muted)', fontSize: '1.1rem', marginBottom: '3rem' }}>Daftarkan dirimu segera pada program unggulan kami</p>
                <div className="animate-fade-in-up delay-200" style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'center', gap: '2rem', maxWidth: '1200px', width: '100%', margin: '0 auto' }}>
                  {openPrograms.map(prog => (
                    <div key={prog.batch_id} className="premium-card" style={{ width: '320px', padding: '2rem', textAlign: 'left', display: 'flex', flexDirection: 'column' }}>
                      <div style={{ padding: '6px 12px', background: 'rgba(34, 197, 94, 0.1)', color: '#22c55e', borderRadius: '20px', fontSize: '0.75rem', fontWeight: '700', letterSpacing: '1px', textTransform: 'uppercase', marginBottom: '1rem', alignSelf: 'flex-start' }}>
                        Pendaftaran Dibuka
                      </div>
                      <h3 style={{ fontSize: '1.25rem', fontWeight: '700', marginBottom: '0.5rem', color: 'var(--text-main)' }}>{prog.program_name}</h3>
                      <div style={{ fontSize: '0.9rem', color: 'var(--primary-color)', fontWeight: '600', marginBottom: '1rem' }}>Batch: {prog.batch_name}</div>
                      <p style={{ color: 'var(--text-muted)', fontSize: '0.95rem', marginBottom: '1.5rem', flex: 1, display: '-webkit-box', WebkitLineClamp: 3, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>
                        {prog.description || 'Program pelatihan aviasi dengan standar kurikulum terkini.'}
                      </p>
                      <div style={{ display: 'flex', gap: '0.5rem' }}>
                        <button onClick={() => setSelectedProgram(prog)} className="btn" style={{ flex: 1, padding: '10px 0', fontSize: '0.9rem', background: 'var(--surface-color)', border: '1px solid var(--border-color)', color: 'var(--text-main)' }}>
                          Persyaratan
                        </button>
                        <Link to={user ? `/portal/register?program_id=${prog.program_id}&batch_id=${prog.batch_id}` : "/login"} className="btn btn-primary" style={{ flex: 1, textAlign: 'center', padding: '10px 0', fontSize: '0.9rem' }}>
                          Daftar
                        </Link>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
              {/* Decorative Divider */}
              <div style={{ width: '100%', height: '1px', background: 'linear-gradient(to right, transparent, rgba(0,0,0,0.1), transparent)', margin: '0 auto 4rem' }}></div>
            </>
          )}

          {/* Feature Cards / Why Choose Us */}
          <div id="features" style={{ width: '100%', padding: '0 2rem', textAlign: 'center' }}>
            <h2 style={{ fontSize: '2.5rem', fontWeight: '800', color: 'var(--text-main)', marginBottom: '0.5rem' }}>Why Choose Us</h2>
            <p style={{ color: 'var(--text-muted)', fontSize: '1.1rem' }}>Discover the excellence of Tel-U Aviation Training Center</p>
          </div>
        </div>
        <div className="animate-fade-in-up delay-300" style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'center', gap: '2rem', maxWidth: '1200px', width: '100%', padding: '3rem 2rem 6rem', margin: '0 auto' }}>
          <div className="premium-card" style={{ width: '260px', padding: '2rem', textAlign: 'left' }}>
            <div style={{ width: '48px', height: '48px', background: 'rgba(239, 68, 68, 0.15)', borderRadius: '12px', display: 'flex', alignItems: 'center', justifyContent: 'center', marginBottom: '1.5rem', color: 'var(--primary-color)' }}>
              <svg width="24" height="24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            </div>
            <h3 style={{ fontSize: '1.25rem', fontWeight: '600', marginBottom: '1rem', color: 'var(--text-main)' }}>Certified Graduates</h3>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.95rem' }}>Basic Certificate Category C (C1, C2, and C4) approved by DKPPU.</p>
          </div>
          <div className="premium-card" style={{ width: '260px', padding: '2rem', textAlign: 'left' }}>
            <div style={{ width: '48px', height: '48px', background: 'rgba(239, 68, 68, 0.15)', borderRadius: '12px', display: 'flex', alignItems: 'center', justifyContent: 'center', marginBottom: '1.5rem', color: 'var(--primary-color)' }}>
              <svg width="24" height="24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path></svg>
            </div>
            <h3 style={{ fontSize: '1.25rem', fontWeight: '600', marginBottom: '1rem', color: 'var(--text-main)' }}>Experienced Instructors</h3>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.95rem' }}>Sharing rich practical experiences from the aviation industry.</p>
          </div>
          <div className="premium-card" style={{ width: '260px', padding: '2rem', textAlign: 'left' }}>
            <div style={{ width: '48px', height: '48px', background: 'rgba(239, 68, 68, 0.15)', borderRadius: '12px', display: 'flex', alignItems: 'center', justifyContent: 'center', marginBottom: '1.5rem', color: 'var(--primary-color)' }}>
              <svg width="24" height="24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
            </div>
            <h3 style={{ fontSize: '1.25rem', fontWeight: '600', marginBottom: '1rem', color: 'var(--text-main)' }}>Aircraft Familiarization</h3>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.95rem' }}>Options available: BELL 412, AH 145, ATR 72-600, BOEING 737 NG.</p>
          </div>
          <div className="premium-card" style={{ width: '260px', padding: '2rem', textAlign: 'left' }}>
            <div style={{ width: '48px', height: '48px', background: 'rgba(239, 68, 68, 0.15)', borderRadius: '12px', display: 'flex', alignItems: 'center', justifyContent: 'center', marginBottom: '1.5rem', color: 'var(--primary-color)' }}>
              <svg width="24" height="24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
            </div>
            <h3 style={{ fontSize: '1.25rem', fontWeight: '600', marginBottom: '1rem', color: 'var(--text-main)' }}>Comprehensive Curriculum</h3>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.95rem' }}>Avionic Fixed Wing, Rotary Wing, and ICT.</p>
          </div>
        </div>

        {/* Decorative Divider */}
        <div style={{ width: '100%', height: '1px', background: 'linear-gradient(to right, transparent, rgba(0,0,0,0.1), transparent)', margin: '0 auto' }}></div>

        {/* About Us Section */}
        <div id="about" style={{ width: '100%', padding: '6rem 2rem', background: 'var(--surface-color)' }}>
          <div style={{ maxWidth: '1200px', margin: '0 auto', display: 'flex', flexWrap: 'wrap', gap: '4rem', alignItems: 'center' }}>
            <div style={{ flex: '1 1 500px', paddingRight: '1rem' }}>
              <div style={{ display: 'inline-block', padding: '6px 16px', background: 'rgba(220, 38, 38, 0.1)', color: 'var(--primary-color)', borderRadius: '20px', fontSize: '0.85rem', fontWeight: '700', letterSpacing: '1px', textTransform: 'uppercase', marginBottom: '1.5rem' }}>
                Tentang Kami
              </div>
              <h2 style={{ fontSize: '2.5rem', fontWeight: '800', color: 'var(--text-main)', marginBottom: '1.5rem', lineHeight: '1.2' }}>
                Telkom University
              </h2>
              <p style={{ color: 'var(--text-muted)', fontSize: '1.1rem', lineHeight: '1.8', marginBottom: '1.5rem' }}>
                TATC adalah lembaga pelatihan aviasi bersertifikat yang didedikasikan untuk menghasilkan tenaga ahli perawatan pesawat udara yang unggul dan profesional. Sebagai institusi yang diakui secara nasional, kami memadukan standar industri penerbangan yang ketat dengan keunggulan akademik dari Telkom University.
              </p>
              <p style={{ color: 'var(--text-muted)', fontSize: '1.1rem', lineHeight: '1.8' }}>
                Basic Certificate C (C1, C2, dan C4) untuk Radio, Instrument, dan Electrical yang tersertifikasi langsung oleh Direktorat Jenderal Perhubungan Udara (DKPPU) melalui AMTO No. 147D-19.
              </p>
            </div>
            <div style={{ flex: '1 1 500px', position: 'relative', display: 'flex', justifyContent: 'center', alignItems: 'center' }}>
              <div style={{ width: '100%', maxWidth: '400px', height: '400px', borderRadius: '20px', background: 'var(--background-main)', boxShadow: '0 20px 40px -10px rgba(0,0,0,0.05)', display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', padding: '3rem', border: '1px solid var(--border-color)' }}>
                <img src="/Logo2.png" alt="TATC Logo" style={{ width: '100%', height: 'auto', maxHeight: '200px', objectFit: 'contain', marginBottom: '2rem' }} />
                <div style={{ textAlign: 'center', color: 'var(--text-main)' }}>
                  <div style={{ fontSize: '2.5rem', fontWeight: '800', lineHeight: 1, color: 'var(--primary-color)' }}>147D-19</div>
                  <div style={{ fontSize: '1rem', fontWeight: '600', opacity: 0.8, marginTop: '0.5rem' }}>Approved AMTO Certification</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Decorative Divider */}
        <div style={{ width: '100%', height: '1px', background: 'linear-gradient(to right, transparent, rgba(0,0,0,0.1), transparent)', margin: '0 auto 4rem' }}></div>

        {/* Latest News Section */}
        <div id="news" style={{ maxWidth: '1200px', width: '100%', margin: '0 auto', padding: '0 2rem 6rem' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: '3rem' }}>
            <div>
              <h2 style={{ fontSize: '2.5rem', fontWeight: '800', color: 'var(--text-main)', marginBottom: '0.5rem' }}>Berita Terbaru</h2>
              <p style={{ color: 'var(--text-muted)', fontSize: '1.1rem' }}>Informasi dan pengumuman seputar TATC</p>
            </div>
            <Link to="/news" style={{ color: 'var(--primary-color)', fontWeight: '600', textDecoration: 'none' }}>Lihat Semua Berita &rarr;</Link>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))', gap: '2rem' }}>
            {recentNews.length > 0 ? (
              recentNews.map(news => (
                <Link to={`/news?slug=${news.slug}`} key={news.id} className="premium-card" style={{ textDecoration: 'none', color: 'inherit', display: 'block', padding: 0 }}>
                  {news.image_url && (
                    <div style={{ height: '220px', width: '100%', overflow: 'hidden', borderBottom: '1px solid var(--border-color)' }}>
                      <img src={news.image_url} alt={news.title} style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                    </div>
                  )}
                  <div style={{ padding: '1.5rem' }}>
                    <div style={{ fontSize: '0.85rem', color: 'var(--primary-color)', fontWeight: '600', marginBottom: '0.75rem', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                      {new Date(news.created_at).toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' })}
                    </div>
                    <h3 style={{ fontSize: '1.35rem', fontWeight: '700', marginBottom: '1rem', lineHeight: '1.4', color: 'var(--text-main)' }}>{news.title}</h3>
                    <p style={{ color: 'var(--text-muted)', fontSize: '1rem', display: '-webkit-box', WebkitLineClamp: 3, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>
                      {news.content ? news.content.replace(/<[^>]+>/g, '') : ''}
                    </p>
                  </div>
                </Link>
              ))
            ) : (
              <p style={{ color: 'var(--text-muted)' }}>Belum ada berita yang dipublikasikan.</p>
            )}
          </div>
        </div>
      </main>

      {/* Requirements Modal */}
      {selectedProgram && (
        <div style={{ position: 'fixed', top: 0, left: 0, width: '100%', height: '100%', background: 'rgba(0,0,0,0.6)', backdropFilter: 'blur(4px)', zIndex: 9999, display: 'flex', justifyContent: 'center', alignItems: 'center', padding: '1rem' }}>
          <div className="animate-fade-in-up" style={{ background: 'var(--surface-color)', width: '100%', maxWidth: '600px', borderRadius: '16px', overflow: 'hidden', boxShadow: '0 25px 50px -12px rgba(0,0,0,0.25)' }}>
            <div style={{ padding: '1.5rem 2rem', borderBottom: '1px solid var(--border-color)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', background: 'var(--background-main)' }}>
              <h3 style={{ fontSize: '1.25rem', fontWeight: '700', color: 'var(--text-main)', margin: 0 }}>Persyaratan Program</h3>
              <button onClick={() => setSelectedProgram(null)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }}>
                <svg width="24" height="24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>
            <div style={{ padding: '2rem', maxHeight: '60vh', overflowY: 'auto' }}>
              <div style={{ marginBottom: '1.5rem' }}>
                <h4 style={{ fontSize: '1.1rem', color: 'var(--primary-color)', marginBottom: '0.5rem', fontWeight: '700' }}>{selectedProgram.program_name}</h4>
                <p style={{ color: 'var(--text-muted)', fontSize: '0.95rem' }}>Batch: {selectedProgram.batch_name}</p>
              </div>
              <div style={{ color: 'var(--text-main)', fontSize: '1rem', lineHeight: '1.6' }}>
                {selectedProgram.requirements ? (
                  <div>
                    {selectedProgram.requirements.split('\n').map((line, i) => (
                      <React.Fragment key={i}>
                        {line}
                        {i < selectedProgram.requirements.split('\n').length - 1 && <br />}
                      </React.Fragment>
                    ))}
                  </div>
                ) : (
                  <ul style={{ paddingLeft: '1.5rem', margin: 0, color: 'var(--text-muted)' }}>
                    <li style={{ marginBottom: '0.5rem' }}>Warga Negara Indonesia (WNI)</li>
                    <li style={{ marginBottom: '0.5rem' }}>Sehat jasmani dan rohani, serta tidak buta warna (melampirkan surat keterangan dokter)</li>
                    <li style={{ marginBottom: '0.5rem' }}>Lulusan SMA/SMK sederajat atau sedang menempuh pendidikan di perguruan tinggi</li>
                    <li style={{ marginBottom: '0.5rem' }}>Lolos seleksi administrasi dan wawancara</li>
                    <li>Melengkapi dokumen identitas diri (KTP/Kartu Pelajar)</li>
                  </ul>
                )}
              </div>
            </div>
            <div style={{ padding: '1.5rem 2rem', borderTop: '1px solid var(--border-color)', background: 'var(--background-main)', display: 'flex', justifyContent: 'flex-end', gap: '1rem' }}>
              <button onClick={() => setSelectedProgram(null)} className="btn" style={{ padding: '8px 16px', background: 'transparent', border: '1px solid var(--border-color)', color: 'var(--text-main)' }}>Tutup</button>
              <Link to={user ? "/portal/participant" : "/login"} className="btn btn-primary" style={{ padding: '8px 24px' }}>Daftar Sekarang</Link>
            </div>
          </div>
        </div>
      )}

      {/* Footer */}
      <footer id="contacts" style={{ background: 'var(--text-main)', color: 'var(--background-main)', padding: '4rem 2rem 2rem', width: '100%' }}>
        <div style={{ maxWidth: '1200px', margin: '0 auto', display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(250px, 1fr))', gap: '3rem', textAlign: 'left' }}>
          <div>
            <h5 style={{ fontWeight: '600', marginBottom: '1rem', color: 'var(--surface-color)', fontSize: '1.1rem' }}>Our Address</h5>
            <p style={{ fontSize: '0.9rem', color: '#94a3b8', lineHeight: '1.6' }}>
              <a href="https://maps.app.goo.gl/24TFvPCeZArUgqt66" target="_blank" rel="noopener noreferrer" style={{ color: 'inherit', textDecoration: 'none', transition: 'color 0.2s' }} onMouseOver={e => e.target.style.color = 'var(--primary-color)'} onMouseOut={e => e.target.style.color = 'inherit'}>
                Jl. Telekomunikasi 1 Terusan Buah Batu Bojongsoang<br />
                Kabupaten Bandung, Jawa Barat
              </a>
            </p>
          </div>
          <div>
            <h5 style={{ fontWeight: '600', marginBottom: '1rem', color: 'var(--surface-color)', fontSize: '1.1rem' }}>Contact Us</h5>
            <ul style={{ listStyle: 'none', padding: 0, margin: 0, fontSize: '0.9rem', color: '#94a3b8', lineHeight: '1.8' }}>
              <li>Phone: <a href="https://wa.me/6282318070048" target="_blank" rel="noopener noreferrer" style={{ color: 'inherit', textDecoration: 'none', transition: 'color 0.2s' }} onMouseOver={e => e.target.style.color = 'var(--primary-color)'} onMouseOut={e => e.target.style.color = 'inherit'}>0823-1807-0048</a></li>
              <li>Email: <a href="mailto:amto@telkomuniversity.ac.id" style={{ color: 'inherit', textDecoration: 'none', transition: 'color 0.2s' }} onMouseOver={e => e.target.style.color = 'var(--primary-color)'} onMouseOut={e => e.target.style.color = 'inherit'}>amto@telkomuniversity.ac.id</a></li>
              <li>Website: <a href="http://amto.telkomuniversity.ac.id" target="_blank" rel="noopener noreferrer" style={{ color: 'inherit', textDecoration: 'none', transition: 'color 0.2s' }} onMouseOver={e => e.target.style.color = 'var(--primary-color)'} onMouseOut={e => e.target.style.color = 'inherit'}>http://amto.telkomuniversity.ac.id</a></li>
            </ul>
          </div>

          <div>
            <h5 style={{ fontWeight: '600', marginBottom: '1rem', color: 'var(--surface-color)', fontSize: '1.1rem' }}>Social Media</h5>
            <p style={{ fontSize: '0.9rem', color: '#94a3b8', marginBottom: '1rem' }}>For news & updates follow us</p>
            <div style={{ display: 'flex', gap: '1rem' }}>
              <a href="https://www.instagram.com/teluatc/" target="_blank" rel="noopener noreferrer" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', width: '36px', height: '36px', background: 'rgba(255,255,255,0.1)', borderRadius: '50%', color: 'var(--surface-color)', textDecoration: 'none', transition: 'background 0.2s' }} onMouseOver={e => e.currentTarget.style.background = 'var(--primary-color)'} onMouseOut={e => e.currentTarget.style.background = 'rgba(255,255,255,0.1)'}>
                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z" /></svg>
              </a>
            </div>
          </div>
        </div>
        <div style={{ textAlign: 'center', marginTop: '4rem', paddingTop: '2rem', borderTop: '1px solid rgba(255,255,255,0.1)', fontSize: '0.9rem', color: 'var(--text-muted)' }}>
          &copy; {new Date().getFullYear()} Telkom University Aviation Training Center. All rights reserved.
        </div>
      </footer>
    </>
  );
}

export default Home;
