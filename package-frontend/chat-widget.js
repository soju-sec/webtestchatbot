(function () {
  const cfg = window.CHATBOT_CONFIG || {};
  const currentScript = document.currentScript || document.querySelector('script[data-webhook]') || document.querySelector('script[src*="chat-widget"]');
  const WEBHOOK_URL = (currentScript && currentScript.getAttribute('data-webhook')) || cfg.webhookUrl || `${window.location.origin}/api/webhook/chat-entry`;
  const BACKEND_BASE_URL = (currentScript && currentScript.getAttribute('data-backend-url')) || cfg.backendUrl || cfg.apiBase?.replace(/\/api\/v1$/, '')?.replace(/\/v1$/, '') || window.location.origin;
  const scriptSrc = (currentScript && currentScript.src) || '';
  const scriptBase = scriptSrc ? scriptSrc.substring(0, scriptSrc.lastIndexOf('/')) : '';
  const DEFAULT_LOGO = scriptBase ? `${scriptBase}/assets/logo-siskeudes-tnt.png` : `${BACKEND_BASE_URL}/vendor/chatbot/assets/logo-siskeudes-tnt.png`;
  const LOGO_URL = (currentScript && currentScript.getAttribute('data-logo-url')) || cfg.logoUrl || DEFAULT_LOGO;
  const BOT_NAME = (currentScript && currentScript.getAttribute('data-bot-name')) || cfg.title || cfg.botName || 'Asisten Digital Siskeudes TNT';
  const CUSTOM_GREETING = (currentScript && currentScript.getAttribute('data-greeting')) || cfg.greeting || '';

  // Auto-detect logged-in user from CHATBOT_CONFIG, Inertia.js (Velzon), window globals, or script attributes
  function detectLoggedInUser() {
    if (cfg.userId || cfg.userName) {
      return { id: cfg.userId || 'pelapor-web', name: cfg.userName || '' };
    }
    let rawId = currentScript && currentScript.getAttribute('data-user-id');
    let rawName = currentScript && currentScript.getAttribute('data-user-name');

    // 1. Auto-detect from Inertia.js dataset (Velzon / Laravel Inertia apps)
    try {
      const appEl = document.getElementById('app');
      if (appEl && appEl.dataset && appEl.dataset.page) {
        const pageData = JSON.parse(appEl.dataset.page);
        const user = pageData?.props?.auth?.user || pageData?.props?.user;
        if (user && user.name) {
          return { id: 'user-' + (user.id || '1'), name: user.name };
        }
      }
    } catch (e) {}

    // 2. Auto-detect from window.Laravel or window.authUser
    if (window.Laravel && window.Laravel.user && window.Laravel.user.name) {
      return { id: 'user-' + (window.Laravel.user.id || '1'), name: window.Laravel.user.name };
    }
    if (window.authUser && window.authUser.name) {
      return { id: 'user-' + (window.authUser.id || '1'), name: window.authUser.name };
    }

    // 3. Fallback to script attributes
    if (rawName && rawName.trim() && rawName !== 'Budi Pratama') {
      return { id: rawId || 'user-1', name: rawName.trim() };
    }

    return { id: rawId || 'pelapor-web', name: rawName || '' };
  }

  // Auto-detect Wilayah (Kabupaten & Desa) dan Tahun Anggaran secara dinamis untuk multi-kabupaten
  function detectSiskeudesContext() {
    let kabupaten = (currentScript && currentScript.getAttribute('data-kabupaten')) || cfg.kabupaten || '';
    let desa = (currentScript && currentScript.getAttribute('data-desa')) || cfg.desa || '';
    let tahun = (currentScript && currentScript.getAttribute('data-tahun')) || cfg.tahun || '';

    // Cek dropdown select desa di halaman (seperti di Pencairan SPP Manual: select#kd_desa)
    if (!desa) {
      const selectDesa = document.querySelector('select#kd_desa, select[name="kd_desa"]');
      if (selectDesa && selectDesa.selectedOptions && selectDesa.selectedOptions[0]) {
        const rawTxt = selectDesa.selectedOptions[0].innerText || '';
        const m = rawTxt.match(/(?:[0-9.]+\s+)?(?:Desa\s+|Kelurahan\s+)?([A-Za-z0-9\s]+)$/i);
        if (m) desa = m[1].trim();
      }
    }

    // 1. Cek objek global window aplikasi host
    const globalContexts = [
      window.siskeudesContext,
      window.siskeudesSession,
      window.SiskeudesConfig,
      window.siskeudesTNT,
      window.AppConfig,
      window.Laravel
    ];

    for (const g of globalContexts) {
      if (g && typeof g === 'object') {
        if (!kabupaten) kabupaten = g.kabupaten || g.nama_kabupaten || g.kab || '';
        if (!desa) desa = g.desa || g.nama_desa || g.village || '';
        if (!tahun) tahun = g.tahun || g.tahun_anggaran || g.year || '';
      }
    }

    // 2. Cek Inertia page dataset (Laravel Inertia / Vue / React)
    try {
      const appEl = document.getElementById('app');
      if (appEl && appEl.dataset && appEl.dataset.page) {
        const pageData = JSON.parse(appEl.dataset.page);
        const p = pageData?.props || {};
        if (!kabupaten) kabupaten = p.kabupaten || p.auth?.kabupaten || p.tenant?.kabupaten || p.selected_kabupaten || '';
        if (!desa) desa = p.desa || p.nama_desa || p.auth?.desa || p.tenant?.desa || p.selected_desa || '';
        if (!tahun) tahun = p.tahun || p.tahun_anggaran || p.fiscal_year || '';
      }
    } catch (e) {}

    // 3. Cek meta tags HTML
    if (!kabupaten) {
      const metaKab = document.querySelector('meta[name="kabupaten"], meta[name="region-kabupaten"], meta[data-kabupaten]');
      if (metaKab) kabupaten = metaKab.content || metaKab.getAttribute('data-kabupaten') || '';
    }
    if (!desa) {
      const metaDesa = document.querySelector('meta[name="desa"], meta[name="village"], meta[data-desa]');
      if (metaDesa) desa = metaDesa.content || metaDesa.getAttribute('data-desa') || '';
    }
    if (!tahun) {
      const metaTahun = document.querySelector('meta[name="tahun"], meta[name="tahun-anggaran"]');
      if (metaTahun) tahun = metaTahun.content || '';
    }

    // 4. Deteksi teks dari elemen DOM navbar / header desa
    if (!kabupaten || !desa) {
      try {
        const candidates = document.querySelectorAll('.header-desa, .nama-desa, #current-desa, .topbar-desa, .user-desa, .badge-desa, .breadcrumb, h1, h2, .sidebar-brand');
        for (const el of candidates) {
          const txt = (el.innerText || '').trim();
          if (!kabupaten) {
            const mKab = txt.match(/KAB(?:UPATEN)?\.?\s*([A-Za-z\s]+?)(?:[-–|,.\n]|$)/i);
            if (mKab && mKab[1].trim().length > 2) kabupaten = 'Kab. ' + mKab[1].trim();
          }
          if (!desa) {
            const mDesa = txt.match(/DESA\s+([A-Za-z0-9\s]+?)(?:[-–|,.\n]|$)/i);
            if (mDesa && mDesa[1].trim().length > 2) desa = mDesa[1].trim();
          }
        }
      } catch (e) {}
    }

    return {
      kabupaten: (kabupaten || '').trim(),
      desa: (desa || '').trim(),
      tahun: (tahun || new Date().getFullYear().toString()).trim()
    };
  }

  const siskeudesContext = detectSiskeudesContext();
  const userSession = detectLoggedInUser();
  const USER_ID = userSession.id;
  const USER_NAME = userSession.name;
  const CONVERSATION_ID = `conv-${USER_ID}-${Math.random().toString(36).substring(2, 9)}`;

  // Tentukan nama sapaan ramah sesuai birokrasi Siskeudes
  const contextRegion = siskeudesContext.desa ? `Desa ${siskeudesContext.desa}` : (siskeudesContext.kabupaten ? siskeudesContext.kabupaten : '');
  const displayName = USER_NAME 
    ? USER_NAME 
    : (contextRegion ? `Bpk/Ibu Kaur Keuangan / Operator ${contextRegion}` : 'Bpk/Ibu Kaur Keuangan / Operator Desa');

  // Client-side PII Masker (Lapisan Pertahanan Awal)
  function maskSensitivePII(text) {
    if (!text || typeof text !== 'string') return text;
    return text
      .replace(/\b(?:pin|otp|password|kata\s*sandi|token|secret)\s*[:=]\s*(\S+)/gi, (m, v) => m.replace(v, '[PIN/PASSWORD_DISAMARKAN]'))
      .replace(/\b\d{4}[ -]?\d{4}[ -]?\d{4}[ -]?\d{4}\b|\b\d{16}\b/g, '[NOMOR_KTP_DISAMARKAN]')
      .replace(/(?:\+?62|0)8[0-9]{1,3}[ -]?[0-9]{3,4}[ -]?[0-9]{3,5}\b/g, '[NOMOR_HP_DISAMARKAN]')
      .replace(/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/g, '[EMAIL_DISAMARKAN]')
      .replace(/\b(?:\d{4}[ -]?){3}\d{4}\b/g, '[KARTU_KREDIT_DISAMARKAN]');
  }

  // Buat floating chat button & container
  const container = document.createElement('div');
  container.id = 'ai-chat-root';
  container.innerHTML = `
    <style>
      #ai-chat-btn { position: fixed; bottom: 20px; right: 20px; width: 58px; height: 58px; border-radius: 50%; background: #2563eb; color: #fff; border: none; cursor: pointer; font-size: 26px; box-shadow: 0 4px 16px rgba(37,99,235,0.35); z-index: 99999; display: flex; align-items: center; justify-content: center; transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), background-color 0.2s; }
      #ai-chat-btn:hover { transform: scale(1.08); background: #1d4ed8; }
      #ai-chat-btn:active { transform: scale(0.95); }
      #ai-chat-box { display: none; position: fixed; bottom: 88px; right: 20px; width: 400px; max-width: calc(100vw - 32px); height: 580px; max-height: calc(100vh - 110px); background: #fff; border-radius: 14px; box-shadow: 0 10px 30px rgba(0,0,0,0.18); flex-direction: column; z-index: 99999; overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; border: 1px solid #e2e8f0; animation: ai-slide-up 0.25s ease-out; }
      @keyframes ai-slide-up { from { opacity: 0; transform: translateY(12px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
      #ai-chat-header { background: #2563eb; color: #fff; padding: 12px 14px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; border-top-left-radius: 14px; border-top-right-radius: 14px; }
      .ai-chat-title-box { display: flex; flex-direction: column; }
      .ai-chat-main-title { font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
      .ai-chat-privacy-tag { font-size: 11px; opacity: 0.9; margin-top: 2px; font-weight: normal; display: flex; align-items: center; gap: 4px; }
      .ai-header-btns { display: flex; align-items: center; gap: 6px; }
      .ai-header-action-btn { background: rgba(255,255,255,0.2); border: none; color: #fff; font-size: 11.5px; cursor: pointer; padding: 4px 8px; border-radius: 6px; font-weight: 600; transition: background 0.2s; display: flex; align-items: center; gap: 4px; }
      .ai-header-action-btn:hover { background: rgba(255,255,255,0.35); }
      #ai-chat-close-btn { background: none; border: none; color: #fff; font-size: 18px; cursor: pointer; padding: 0 4px; line-height: 1; opacity: 0.85; transition: opacity 0.2s; }
      #ai-chat-close-btn:hover { opacity: 1; }
      #ai-chat-msgs { flex: 1; padding: 14px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; font-size: 13px; background: #f8fafc; }
      #ai-chat-msgs::-webkit-scrollbar { width: 5px; }
      #ai-chat-msgs::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
      .ai-msg { max-width: 86%; padding: 9px 13px; border-radius: 10px; line-height: 1.45; word-break: break-word; font-size: 13px; }
      .ai-msg.user { align-self: flex-end; background: #2563eb; color: #fff; border-bottom-right-radius: 2px; }
      .ai-msg.bot { align-self: flex-start; background: #ffffff; color: #1e293b; border: 1px solid #e2e8f0; border-bottom-left-radius: 2px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
      .ai-msg.loading { align-self: flex-start; background: #ffffff; color: #64748b; font-style: italic; border: 1px solid #e2e8f0; }
      .ai-intent-pill { display: inline-block; font-size: 10px; background: #e0e7ff; color: #3730a3; padding: 2px 7px; border-radius: 4px; margin-bottom: 6px; font-weight: 700; text-transform: uppercase; }
      .ai-intent-pill.ticket { background: #fee2e2; color: #991b1b; }
      
      /* Quick Action Chips */
      .ai-quick-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
      .ai-chip-btn { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; font-size: 11.5px; font-weight: 600; padding: 5px 9px; border-radius: 6px; cursor: pointer; transition: all 0.15s ease; }
      .ai-chip-btn:hover { background: #dbeafe; transform: translateY(-1px); }
      .ai-chip-btn.ai-chip-issue { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
      .ai-chip-btn.ai-chip-issue:hover { background: #fee2e2; }

      /* Ticket Card */
      .ai-ticket-card { background: #fff5f5; border: 1.5px solid #fca5a5; border-radius: 10px; padding: 12px; margin-top: 8px; font-size: 12.5px; }
      .ai-ticket-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; border-bottom: 1px solid #fed7aa; padding-bottom: 4px; }
      .ai-ticket-num { font-weight: 800; color: #991b1b; font-size: 13.5px; font-family: monospace; }
      .ai-ticket-tag-open { background: #dc2626; color: #fff; font-size: 9.5px; font-weight: 700; padding: 2px 6px; border-radius: 4px; }
      .ai-ticket-meta { margin-bottom: 8px; line-height: 1.45; color: #334155; }
      .ai-ticket-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; background: #fff; padding: 6px 8px; border-radius: 6px; border: 1px solid #fed7aa; margin-bottom: 8px; font-size: 11.5px; }
      .ai-ticket-wa-note { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 7px 9px; font-size: 11.5px; color: #065f46; margin-bottom: 8px; line-height: 1.4; }
      .ai-ticket-magic-btn { background: #2563eb; color: #ffffff !important; text-decoration: none; font-size: 11.5px; font-weight: 700; padding: 6px 10px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; gap: 5px; box-shadow: 0 2px 6px rgba(37,99,235,0.25); transition: background 0.15s; }
      .ai-ticket-magic-btn:hover { background: #1d4ed8; color: #ffffff !important; }
      .ai-ticket-copy-btn { background: #fee2e2; border: 1px solid #f87171; color: #991b1b; font-size: 11px; font-weight: 600; padding: 5px 8px; border-radius: 6px; cursor: pointer; transition: background 0.15s; }
      .ai-ticket-copy-btn:hover { background: #fecaca; }
      .ai-ticket-jira-box { background: #f0f7ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 5px 8px; font-size: 11px; color: #0369a1; display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
      .ai-jira-badge-link { background: #0052cc; color: #ffffff !important; text-decoration: none; font-weight: 700; font-size: 10.5px; padding: 2px 6px; border-radius: 4px; }

      /* Issue Reporting Drawer (Siskeudes TNT Specific Form) */
      #ai-issue-drawer { display: none; background: #ffffff; border-bottom: 2px solid #e2e8f0; padding: 12px 14px; box-shadow: 0 4px 14px rgba(0,0,0,0.08); max-height: 480px; overflow-y: auto; }
      #ai-issue-drawer::-webkit-scrollbar { width: 5px; }
      #ai-issue-drawer::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
      .ai-drawer-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 13.5px; color: #0f172a; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px; }
      #ai-drawer-close { background: none; border: none; font-size: 16px; cursor: pointer; color: #64748b; }
      .ai-drawer-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
      .ai-drawer-label { font-size: 11px; font-weight: 700; color: #475569; margin-top: 6px; margin-bottom: 2px; display: block; text-transform: uppercase; letter-spacing: 0.3px; }
      .ai-drawer-input, .ai-drawer-textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 8px; font-size: 12px; font-family: inherit; outline: none; box-sizing: border-box; background: #fff; }
      .ai-drawer-input:focus, .ai-drawer-textarea:focus { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.1); }
      .ai-drawer-notice { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 7px 9px; font-size: 11px; color: #1e40af; margin-top: 8px; line-height: 1.4; }
      .ai-drawer-actions { display: flex; gap: 6px; margin-top: 10px; }
      .ai-drawer-submit-btn { flex: 1; background: #dc2626; color: #fff; border: none; border-radius: 6px; padding: 8px; font-size: 12px; font-weight: 700; cursor: pointer; transition: background 0.2s; }
      .ai-drawer-submit-btn:hover { background: #b91c1c; }
      .ai-drawer-cancel-btn { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 12px; cursor: pointer; }

      /* Chat Form */
      #ai-chat-form { display: flex; border-top: 1px solid #e2e8f0; padding: 10px; background: #fff; gap: 6px; align-items: center; }
      #ai-chat-input { flex: 1; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 12px; outline: none; font-size: 13px; transition: border-color 0.2s; }
      #ai-chat-input:focus { border-color: #2563eb; }
      #ai-chat-submit { background: #2563eb; color: #fff; border: none; border-radius: 8px; padding: 9px 14px; cursor: pointer; font-size: 13px; font-weight: 600; transition: background 0.2s; }
      #ai-chat-submit:hover { background: #1d4ed8; }
      #ai-chat-submit:disabled { opacity: 0.6; cursor: not-allowed; }
    </style>
    <button id="ai-chat-btn" title="Buka Percakapan Asisten Siskeudes TNT" style="padding: 0; overflow: hidden; border: 2px solid #2563eb; background: #ffffff;">
      <img src="${LOGO_URL}" alt="Logo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block;" onerror="this.style.display='none'; this.parentElement.innerHTML='🤖';" />
    </button>
    <div id="ai-chat-box">
      <div id="ai-chat-header">
        <div class="ai-chat-title-box">
          <span class="ai-chat-main-title">
            <img src="${LOGO_URL}" alt="Avatar" style="width: 26px; height: 26px; border-radius: 50%; object-fit: cover; border: 1.5px solid rgba(255,255,255,0.8); background: #fff;" onerror="this.style.display='none';" />
            ${BOT_NAME}
          </span>
          <span class="ai-chat-privacy-tag">🛡️ iMD Imtek Media Data &bull; Anti-PII</span>
        </div>
        <div class="ai-header-btns">
          <button type="button" id="ai-chat-report-btn" class="ai-header-action-btn" title="Buat Laporan Kendala Siskeudes TNT">🚨 Lapor Kendala</button>
          <button type="button" id="ai-chat-close-btn" title="Tutup Chat">✕</button>
        </div>
      </div>

      <!-- Quick Issue Report Drawer: Format Lengkap Siskeudes TNT -->
      <div id="ai-issue-drawer">
        <div class="ai-drawer-header">
          <span>🏛️ <b>Form Laporan Siskeudes TNT & CMS Bank</b></span>
          <button type="button" id="ai-drawer-close">✕</button>
        </div>
        <div>
          <label class="ai-drawer-label">No. WhatsApp Pelapor: *</label>
          <input type="tel" id="ai-issue-phone" class="ai-drawer-input" placeholder="Contoh: 08123456789 (Hasil dikirim ke sini)" required />

          <div class="ai-drawer-grid-2">
            <div>
              <label class="ai-drawer-label">Kabupaten: *</label>
              <input type="text" id="ai-issue-kabupaten" class="ai-drawer-input" placeholder="Contoh: Kab. Bogor" required />
            </div>
            <div>
              <label class="ai-drawer-label">Nama Desa: *</label>
              <input type="text" id="ai-issue-desa" class="ai-drawer-input" placeholder="Contoh: Desa Sukamaju" required />
            </div>
          </div>

          <div class="ai-drawer-grid-2">
            <div>
              <label class="ai-drawer-label">No. SPP:</label>
              <input type="text" id="ai-issue-spp" class="ai-drawer-input" placeholder="Contoh: 001/SPP/2026" />
            </div>
            <div>
              <label class="ai-drawer-label">No. Kwitansi:</label>
              <input type="text" id="ai-issue-kwitansi" class="ai-drawer-input" placeholder="Contoh: KW-042/2026" />
            </div>
          </div>

          <div class="ai-drawer-grid-2">
            <div>
              <label class="ai-drawer-label">Kode CMS Siskeudes TNT:</label>
              <input type="text" id="ai-issue-kode-siskeudes" class="ai-drawer-input" placeholder="Kode CMS di Siskeudes" />
            </div>
            <div>
              <label class="ai-drawer-label">Kode CMS App Bank:</label>
              <input type="text" id="ai-issue-kode-bank" class="ai-drawer-input" placeholder="Kode CMS di App Bank" />
            </div>
          </div>

          <label class="ai-drawer-label">Keterangan / Kendala: *</label>
          <textarea id="ai-issue-desc" class="ai-drawer-textarea" rows="2" placeholder="Jelaskan kendala transaksi atau error yang muncul..." required></textarea>

          <div class="ai-drawer-notice">
            📱 <b>Hemat Kuota WA & Bebas Spam:</b> Tanda terima & Link Lacak Mandiri akan dikirimkan ke No. WhatsApp Anda. Hasil akhir akan dikabari kembali saat kendala selesai.
          </div>

          <div class="ai-drawer-actions">
            <button type="button" id="ai-issue-submit" class="ai-drawer-submit-btn">🚀 Kirim Laporan Siskeudes</button>
            <button type="button" id="ai-issue-cancel" class="ai-drawer-cancel-btn">Batal</button>
          </div>
        </div>
      </div>

      <div id="ai-chat-msgs">
        <div class="ai-msg bot" id="ai-initial-greeting">
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
            <img src="${LOGO_URL}" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1.5px solid #2563eb; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.08);" onerror="this.style.display='none';" />
            <div>
              <div style="font-weight: 800; font-size: 13.5px; color: #1e3a8a; line-height: 1.2;">Asisten Digital Siskeudes TNT</div>
              <div style="font-size: 10px; color: #64748b; font-weight: 700; letter-spacing: 0.5px;">iMD IMTEK MEDIA DATA &bull; HELPDESK</div>
            </div>
          </div>
          ${CUSTOM_GREETING ? CUSTOM_GREETING : `Halo <b>${displayName}</b>! 👋 Selamat datang di Layanan Bantuan Siskeudes TNT & Transaksi Non-Tunai.<br><br>Pilih bantuan cepat di bawah, cek pencairan SPP, status tiket, atau laporkan kendala:`}
          <div class="ai-quick-chips">
            <button type="button" class="ai-chip-btn ai-chip-issue" id="ai-btn-open-issue-form">🚨 Form Lapor Kendala Siskeudes</button>
            <button type="button" class="ai-chip-btn" data-query="Cek status pencairan SPP">💸 Cek Pencairan SPP</button>
            <button type="button" class="ai-chip-btn" data-query="SOP verifikasi SPP dan upload SPTJB/SPP2 (Hal 10)">📋 SOP Verifikasi SPP</button>
            <button type="button" class="ai-chip-btn" data-query="Kenapa status akun berwarna merah saat verifikasi SPP? (Hal 10)">🔴 Solusi Akun Merah</button>
            <button type="button" class="ai-chip-btn" data-query="Bagaimana cara koreksi ID Billing Pajak kadaluarsa? (Hal 15)">📑 Koreksi ID Billing</button>
            <button type="button" class="ai-chip-btn" data-query="Penyebab transaksi masuk menu Pembayaran Gagal di CMS Bank Desa? (Hal 6)">❌ Menu Pembayaran Gagal</button>
            <button type="button" class="ai-chip-btn" data-query="Bagaimana alur SPP dari Siskeudes Online agar terbaca di Siskeudes TNT?">🔄 Alur SPP Online</button>
          </div>
        </div>
      </div>
      <form id="ai-chat-form">
        <input type="text" id="ai-chat-input" placeholder="Ketik no. SPP (0015/SPP/...), no. tiket (TCK-...), atau pertanyaan..." required autocomplete="off" />
        <button type="submit" id="ai-chat-submit">Kirim</button>
      </form>
    </div>
  `;
  document.body.appendChild(container);

  const btn = document.getElementById('ai-chat-btn');
  const box = document.getElementById('ai-chat-box');
  const closeBtn = document.getElementById('ai-chat-close-btn');
  const form = document.getElementById('ai-chat-form');
  const input = document.getElementById('ai-chat-input');
  const submitBtn = document.getElementById('ai-chat-submit');
  const msgContainer = document.getElementById('ai-chat-msgs');
  const reportBtn = document.getElementById('ai-chat-report-btn');
  const issueDrawer = document.getElementById('ai-issue-drawer');
  const drawerClose = document.getElementById('ai-drawer-close');
  const drawerCancel = document.getElementById('ai-issue-cancel');
  const drawerSubmit = document.getElementById('ai-issue-submit');
  const chipIssueBtn = document.getElementById('ai-btn-open-issue-form');

  function toggleChat() {
    const isVisible = box.style.display === 'flex';
    box.style.display = isVisible ? 'none' : 'flex';
    if (!isVisible) {
      input.focus();
    }
  }

  function toggleDrawer(show) {
    if (show === undefined) {
      issueDrawer.style.display = issueDrawer.style.display === 'block' ? 'none' : 'block';
    } else {
      issueDrawer.style.display = show ? 'block' : 'none';
    }
    if (issueDrawer.style.display === 'block') {
      // Auto-fill context Kabupaten & Desa dari project secara dinamis
      const ctx = detectSiskeudesContext();
      const kabInput = document.getElementById('ai-issue-kabupaten');
      const desaInput = document.getElementById('ai-issue-desa');
      if (kabInput && !kabInput.value && ctx.kabupaten) {
        kabInput.value = ctx.kabupaten;
      }
      if (desaInput && !desaInput.value && ctx.desa) {
        desaInput.value = ctx.desa;
      }
      const phoneInput = document.getElementById('ai-issue-phone');
      if (phoneInput && !phoneInput.value) {
        phoneInput.focus();
      } else if (kabInput && !kabInput.value) {
        kabInput.focus();
      }
    }
  }

  btn.addEventListener('click', toggleChat);
  if (closeBtn) {
    closeBtn.addEventListener('click', () => {
      box.style.display = 'none';
    });
  }

  if (reportBtn) {
    reportBtn.addEventListener('click', () => toggleDrawer(true));
  }
  if (chipIssueBtn) {
    chipIssueBtn.addEventListener('click', () => toggleDrawer(true));
  }
  if (drawerClose) {
    drawerClose.addEventListener('click', () => toggleDrawer(false));
  }
  if (drawerCancel) {
    drawerCancel.addEventListener('click', () => toggleDrawer(false));
  }

  // Handle Quick Chip Queries
  msgContainer.addEventListener('click', (e) => {
    const chip = e.target.closest('.ai-chip-btn');
    if (!chip) return;

    if (chip.classList.contains('ai-chip-spp-item') && chip.dataset.spp) {
      const sppNumber = chip.dataset.spp;
      input.value = sppNumber;
      handleSppInquiry(sppNumber, sppNumber);
      return;
    }

    if (chip.dataset.query) {
      const query = chip.dataset.query;
      if (isGeneralSppInquiry(query)) {
        askForSppNumber(query);
      } else {
        input.value = query;
        form.requestSubmit();
      }
    } else if (chip.id === 'ai-btn-open-issue-form') {
      toggleDrawer(true);
    }
  });

  // Submit Laporan Siskeudes TNT via Drawer
  if (drawerSubmit) {
    drawerSubmit.addEventListener('click', async () => {
      const phone = document.getElementById('ai-issue-phone').value.trim();
      const kabupaten = document.getElementById('ai-issue-kabupaten').value.trim();
      const desa = document.getElementById('ai-issue-desa').value.trim();
      const spp = document.getElementById('ai-issue-spp').value.trim();
      const kwitansi = document.getElementById('ai-issue-kwitansi').value.trim();
      const kodeSiskeudes = document.getElementById('ai-issue-kode-siskeudes').value.trim();
      const kodeBank = document.getElementById('ai-issue-kode-bank').value.trim();
      const desc = document.getElementById('ai-issue-desc').value.trim();

      if (!phone) {
        alert('Mohon isi Nomor WhatsApp Pelapor.');
        document.getElementById('ai-issue-phone').focus();
        return;
      }
      if (!kabupaten) {
        alert('Mohon isi Nama Kabupaten.');
        document.getElementById('ai-issue-kabupaten').focus();
        return;
      }
      if (!desa) {
        alert('Mohon isi Nama Desa.');
        document.getElementById('ai-issue-desa').focus();
        return;
      }
      if (!desc) {
        alert('Mohon isi Keterangan / Kendala yang dialami.');
        document.getElementById('ai-issue-desc').focus();
        return;
      }

      toggleDrawer(false);
      drawerSubmit.disabled = true;

      appendMsg('user', `Mengirim Laporan Siskeudes TNT untuk Desa ${desa}, Kab. ${kabupaten}...`);

      const loadingId = 'loading-' + Date.now();
      const loadingDiv = document.createElement('div');
      loadingDiv.id = loadingId;
      loadingDiv.className = 'ai-msg loading';
      loadingDiv.innerText = 'Mencatat laporan, mendaftarkan tiket di Jira SUP & mengirim bukti tanda terima ke WhatsApp...';
      msgContainer.appendChild(loadingDiv);
      msgContainer.scrollTop = msgContainer.scrollHeight;

      const payload = {
        user_id: phone,
        user_name: `Desa ${desa}`,
        reporter_phone: phone,
        kabupaten: kabupaten,
        nama_desa: desa,
        no_spp: spp,
        no_kwitansi: kwitansi,
        kode_cms_siskeudes: kodeSiskeudes,
        kode_cms_bank: kodeBank,
        description: desc,
        feature_name: 'Siskeudes TNT / CMS Bank',
        category: 'siskeudes_tnt',
        urgency: 'tinggi',
        channel: 'web'
      };

      try {
        // Coba kirim langsung ke endpoint backend tiket
        const backendUrl = BACKEND_BASE_URL.replace(/\/$/, '');
        const endpoints = [
          `${backendUrl}/api/v1/tickets/public-submit`,
          `${backendUrl}/v1/tickets/public-submit`,
          `${backendUrl}/tickets/public-submit`
        ];

        let resultData = null;
        for (const ep of endpoints) {
          try {
            const resp = await fetch(ep, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload)
            });
            if (resp.ok) {
              resultData = await resp.json();
              break;
            }
          } catch (err) {}
        }

        const el = document.getElementById(loadingId);
        if (el) el.remove();

        if (resultData && (resultData.ticket || resultData.ticket_number)) {
          const t = resultData.ticket || resultData;
          const ticketNumber = t.ticket_number;
          const magicLink = t.magic_link || `${backendUrl}/api/v1/tickets/track/${ticketNumber}?token=${t.tracking_token || ''}`;

          let botText = `🎫 <b>Laporan Kendala Siskeudes Berhasil Dibuat!</b><br><br>`
            + `Halo <b>Desa ${desa}</b>, laporan Anda telah terdaftar resmi dalam antrean penanganan teknis tim kami.`;

          appendMsg('bot', botText, 'report_customer_issue', {
            is_ticket_report: true,
            ticket_number: ticketNumber,
            ticket: t,
            magic_link: magicLink,
            jira: resultData.jira || null
          });

          // Reset form fields
          document.getElementById('ai-issue-spp').value = '';
          document.getElementById('ai-issue-kwitansi').value = '';
          document.getElementById('ai-issue-kode-siskeudes').value = '';
          document.getElementById('ai-issue-kode-bank').value = '';
          document.getElementById('ai-issue-desc').value = '';
        } else {
          // Fallback via n8n orchestrator
          sendChatMessage(`Min, saya mau lapor kendala Siskeudes TNT: Kab ${kabupaten}, Desa ${desa}, No WA: ${phone}. SPP: ${spp}, Kwitansi: ${kwitansi}, CMS Siskeudes: ${kodeSiskeudes}, CMS Bank: ${kodeBank}. Kendala: ${desc}`);
        }
      } catch (err) {
        const el = document.getElementById(loadingId);
        if (el) el.remove();
        appendMsg('bot', 'Gagal memproses laporan. Silakan coba kembali atau gunakan live chat.');
      } finally {
        drawerSubmit.disabled = false;
      }
    });
  }

  // Deteksi apakah user menanyakan status SPP secara umum tanpa menyertakan nomor spesifik
  function isGeneralSppInquiry(text) {
    const t = (text || '').trim().toLowerCase();
    const generalPatterns = [
      /^(?:cek|status|pencairan|mau cek|tolong cek|bisa cek)?\s*(?:status)?\s*(?:pencairan)?\s*spp(?:\s*(?:online|manual|desa|tnt|link|dong|min))?$/i,
      /^(?:cek|status)?\s*(?:status)?\s*pencairan(?:\s*(?:spp|dana|desa))?$/i,
      /^apakah\s*(?:status)?\s*(?:pencairan)?\s*spp\s*(?:sudah\s*cair|sudah\s*masuk)?\??$/i,
      /^(?:cek\s*status\s*pencairan\s*spp|cek\s*pencairan\s*spp|cek\s*status\s*spp|cek\s*spp|pencairan\s*spp)$/i
    ];
    return generalPatterns.some(p => p.test(t));
  }

  // Ekstrak nomor SPP / CMS / Kwitansi spesifik (wajib ada angka / pola baku)
  function extractSppIdentifier(text) {
    // A. Format baku Siskeudes: 00001/SPP/...
    const mSpp = text.match(/\b(\d{3,5}\/SPP\/[0-9A-Za-z./-]+)\b/i);
    if (mSpp) return mSpp[1];

    // B. Format Kode CMS dengan angka: CMS-2026-00123 atau CMS2026001001
    const mCms = text.match(/\b(CMS[-_0-9A-Za-z]*\d+[-_0-9A-Za-z]*)\b/i);
    if (mCms && mCms[1].length >= 6) return mCms[1];

    // C. Format Kwitansi dengan angka: KW-1/2026 atau KW-001/2026
    const mKw = text.match(/\b(KW[-_./0-9A-Za-z]*\d+[-_./0-9A-Za-z]*)\b/i);
    if (mKw && mKw[1].length >= 4) return mKw[1];

    // D. Format eksplisit dengan keyword pencarian: cek spp 00001/...
    const mExplicit = text.match(/(?:cek|status|pencairan)\s*(?:status)?\s*(?:pencairan)?\s*(?:spp|cms|kwitansi)\s*[:=]?\s*([0-9A-Za-z/.-]*\d[0-9A-Za-z/.-]*)/i);
    if (mExplicit && mExplicit[1].length >= 4) {
      const cand = mExplicit[1];
      const stopwords = ['pencairan', 'status', 'online', 'manual', 'tnt', 'desa', 'bank', 'kwitansi'];
      if (!stopwords.includes(cand.toLowerCase())) {
        return cand;
      }
    }

    return null;
  }

  // Tanya nomor SPP ke pengguna
  function askForSppNumber(originalText) {
    if (originalText) {
      appendMsg('user', originalText);
    }

    const replyHtml = `Silakan ketikkan <b>Nomor SPP</b>, <b>Kode CMS Siskeudes</b>, atau <b>Nomor Kwitansi</b> yang ingin dicek status pencairannya:<br><br>`
      + `<i>Contoh format:</i><br>`
      + `• <code>00001/SPP/51.03.01.2001/2026</code><br>`
      + `• <code>CMS2026001001</code><br>`
      + `• <code>KW-1/2026</code>`;

    appendMsg('bot', replyHtml);
    input.placeholder = "Ketik no. SPP (contoh: 00001/SPP/51.03.01.2001/2026)...";
    input.focus();
  }

  window.__checkSppDirect = function(sppNum) {
    if (sppNum) {
      input.value = sppNum;
      handleSppInquiry(sppNum, sppNum);
    }
  };

  // Handle Chat Input Form
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    input.value = '';

    // 1. Cek apakah user sedang menanyakan nomor tiket (Follow-up mandiri via Web)
    const matchTicket = text.match(/\b(TCK-\d{8}-[A-Za-z0-9]+)\b/i);
    if (matchTicket) {
      const ticketNum = matchTicket[1].toUpperCase();
      handleTicketFollowup(ticketNum, text);
      return;
    }

    // 2. Cek apakah query meminta pengecekan SPP umum tanpa nomor
    if (isGeneralSppInquiry(text)) {
      askForSppNumber(text);
      return;
    }

    // 3. Cek apakah user menyertakan nomor SPP / Kode CMS / Kwitansi spesifik
    const sppKey = extractSppIdentifier(text);
    if (sppKey) {
      handleSppInquiry(sppKey, text);
      return;
    }

    // 4. Cek apakah query cocok dengan SOP Knowledge Base resmi (Birokrasi Kemendagri / BPKP)
    if (handleLocalSopMatch(text)) {
      return;
    }

    sendChatMessage(text);
  });

  // Follow-up Mandiri Status Tiket di Web (Bebas Kuota WA)
  async function handleTicketFollowup(ticketNum, originalText) {
    appendMsg('user', originalText);
    submitBtn.disabled = true;

    const loadingId = 'loading-' + Date.now();
    const loadingDiv = document.createElement('div');
    loadingDiv.id = loadingId;
    loadingDiv.className = 'ai-msg loading';
    loadingDiv.innerText = `Memeriksa status tiket #${ticketNum}...`;
    msgContainer.appendChild(loadingDiv);
    msgContainer.scrollTop = msgContainer.scrollHeight;

    try {
      const backendUrl = BACKEND_BASE_URL.replace(/\/$/, '');
      const resp = await fetch(`${backendUrl}/api/v1/tickets/track/${ticketNum}?format=json`);
      const data = await resp.json();

      const el = document.getElementById(loadingId);
      if (el) el.remove();

      if (data && data.ticket) {
        const t = data.ticket;
        const status = (t.status || 'open').toUpperCase();
        const magicLink = `${backendUrl}/api/v1/tickets/track/${t.ticket_number}?token=${t.tracking_token || ''}`;

        let statusText = status === 'RESOLVED' || status === 'DONE' ? '🟢 SELESAI / RESOLVED' : (status === 'INVESTIGATING' ? '🟡 DALAM INVESTIGASI' : '🔵 SEDANG DIANTREKAN');

        let replyHtml = `🔍 <b>Informasi Status Tiket: #${t.ticket_number}</b><br><br>`
          + `• Status Terkini: <b>${statusText}</b><br>`
          + `• Desa / Kab: <b>Desa ${t.nama_desa || '-'}, ${t.kabupaten || '-'}</b><br>`
          + (t.no_spp ? `• No. SPP: <code>${t.no_spp}</code><br>` : '')
          + (t.resolution_notes ? `<br><b>🛠️ Catatan Solusi Teknisi:</b><br><i>"${t.resolution_notes}"</i><br>` : '')
          + `<br><a href="${magicLink}" target="_blank" class="ai-ticket-magic-btn">🔗 Buka Halaman Pelacak Mandiri ↗</a>`;

        appendMsg('bot', replyHtml);
      } else {
        appendMsg('bot', `Tiket <b>#${ticketNum}</b> tidak ditemukan dalam basis data kami. Pastikan nomor tiket yang Anda masukkan sudah benar.`);
      }
    } catch (err) {
      const el = document.getElementById(loadingId);
      if (el) el.remove();
      // Teruskan ke AI biasa jika endpoint gagal
      sendChatMessage(originalText);
    } finally {
      submitBtn.disabled = false;
      input.focus();
    }
  }

  // Pengecekan Mandiri Status Pencairan SPP (Read-Only API)
  async function handleSppInquiry(sppKey, originalText) {
    appendMsg('user', originalText);
    submitBtn.disabled = true;

    const loadingId = 'loading-' + Date.now();
    const loadingDiv = document.createElement('div');
    loadingDiv.id = loadingId;
    loadingDiv.className = 'ai-msg loading';
    loadingDiv.innerText = `Menghubungi API Siskeudes-Link untuk mengecek status pencairan SPP/CMS [${sppKey}]...`;
    msgContainer.appendChild(loadingDiv);
    msgContainer.scrollTop = msgContainer.scrollHeight;

    const ctx = detectSiskeudesContext();
    const backendUrl = BACKEND_BASE_URL.replace(/\/$/, '');
    const url = `${backendUrl}/api/v1/siskeudes/spp/status?no_spp=${encodeURIComponent(sppKey)}&kabupaten=${encodeURIComponent(ctx.kabupaten)}&nama_desa=${encodeURIComponent(ctx.desa)}&tahun=${encodeURIComponent(ctx.tahun)}`;

    try {
      const resp = await fetch(url);
      const data = await resp.json();

      const el = document.getElementById(loadingId);
      if (el) el.remove();

      if (data && data.status === 'success' && data.spp_data) {
        const s = data.spp_data;
        const isCair = s.status_pembayaran === 'BERHASIL_CAIR';
        const badgeBg = s.badge_color === 'success' ? '#10b981' : (s.badge_color === 'danger' ? '#ef4444' : '#f59e0b');
        const badgeText = s.status_label || (isCair ? '🟢 SUDAH CAIR / BERHASIL TRANSFER' : '🟡 MENUNGGU VERIFIKASI / PROSES');

        let html = `
          <div style="font-size: 13px; line-height: 1.45;">
            <b>Hasil Pengecekan Status Pencairan SPP</b><br>
            <span style="font-size: 11px; color: #64748b;">Sumber: API Siskeudes-Link &bull; CMS Bank Desa</span>
            <div style="background: #ffffff; border: 1.5px solid ${isCair ? '#a7f3d0' : '#fed7aa'}; border-radius: 8px; padding: 10px; margin-top: 8px;">
              <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px; margin-bottom: 6px;">
                <span style="font-weight: 700; color: #1e3a8a; font-family: monospace;">${s.no_spp}</span>
                <span style="background: ${badgeBg}; color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px;">${s.status_pembayaran}</span>
              </div>
              <div style="font-size: 11.5px; color: #334155; line-height: 1.5;">
                • <b>Status:</b> ${badgeText}<br>
                • <b>No. Kwitansi:</b> <code>${s.no_kwitansi}</code><br>
                • <b>Kode CMS:</b> Siskeudes: <code>${s.kode_cms_siskeudes}</code> | Bank: <code>${s.kode_cms_bank}</code><br>
                • <b>Waktu Transaksi:</b> ${s.tanggal_transaksi} WIB<br>
                • <b>No. Referensi Bank:</b> <code>${s.no_referensi_bank}</code><br>
                • <b>Penerima:</b> ${s.nama_penerima} (${s.rekening_tujuan})<br>
                <div style="background: #f8fafc; padding: 6px 8px; border-radius: 6px; margin: 6px 0; border: 1px solid #e2e8f0;">
                  • <b>Nominal Bruto:</b> ${s.nominal_bruto_format}<br>
                  • <b>Potongan Pajak:</b> ${s.potongan_pajak_format} (${s.status_pajak})<br>
                  • <b>Diterima Bersih (Netto):</b> <b style="color: #047857;">${s.nominal_netto_format}</b>
                </div>
                • <b>Alur Verifikasi Dokumen:</b><br>
                &nbsp;&nbsp;✓ Siskeudes Online: ${s.alur_verifikasi.siskeudes_online}<br>
                &nbsp;&nbsp;✓ Upload SPTJB & SPP2: ${s.alur_verifikasi.upload_sptjb}<br>
                &nbsp;&nbsp;✓ Verifikasi Sekdes: ${s.alur_verifikasi.verifikasi_sekdes}<br>
                &nbsp;&nbsp;✓ Approval Kades: ${s.alur_verifikasi.approval_kades}
              </div>
            </div>
            <div style="margin-top: 8px; display: flex; gap: 6px;">
              <button type="button" class="ai-chip-btn ai-chip-issue" onclick="window.__prefillSiskeudesIssue('${s.no_spp}', '${s.no_kwitansi}', '${s.kode_cms_siskeudes}', '${s.kode_cms_bank}')">🚨 Ada Masalah pada SPP Ini? Laporkan</button>
            </div>
          </div>
        `;

        appendMsg('bot', html);
      } else {
        const notFoundMsg = (data && data.message) ? data.message : `Data transaksi untuk <b>${sppKey}</b> tidak ditemukan dalam database Siskeudes.`;
        let notFoundHtml = `
          <div style="font-size: 13px; line-height: 1.45;">
            <b>Hasil Pengecekan Status SPP: Tidak Ditemukan</b><br>
            <div style="background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 8px; padding: 10px; margin-top: 8px;">
              <div style="font-weight: 700; color: #b45309; font-family: monospace; margin-bottom: 4px;">🔍 ${sppKey}</div>
              <div style="font-size: 11.5px; color: #78350f; line-height: 1.5;">${notFoundMsg}</div>
            </div>
            <div style="margin-top: 8px; display: flex; gap: 6px;">
              <button type="button" class="ai-chip-btn ai-chip-issue" onclick="window.__prefillSiskeudesIssue('${sppKey}', '', '', '')">🚨 Butuh Bantuan Teknis? Buat Laporan</button>
            </div>
          </div>
        `;
        appendMsg('bot', notFoundHtml);
      }
    } catch (err) {
      const el = document.getElementById(loadingId);
      if (el) el.remove();
      sendChatMessage(originalText);
    } finally {
      submitBtn.disabled = false;
      input.focus();
    }
  }

  // Knowledge Base SOP Templates Resmi (Kemendagri & BPKP Manual Book)
  const SOP_TEMPLATES = {
    spp_verifikasi: {
      regex: /(?:verifikasi\s*spp|upload\s*sptjb|upload\s*spp2|upload\s*berkas|captcha|tombol\s*setuju|hal(?:aman)?\s*10(?:\s*poin\s*d)?)/i,
      title: '📋 SOP Verifikasi SPP & Upload SPTJB/SPP2 (Manual Hal. 10 Poin D)',
      steps: [
        'Buka menu <b>Verifikasi SPP</b> di aplikasi Siskeudes-Link.',
        'Klik tombol <b>Aksi (Detail)</b> pada baris SPP yang akan diverifikasi.',
        'Unggah berkas <b>SPTJB</b> (Surat Pernyataan Tanggung Jawab Belanja) bertanda tangan basah/stempel desa format PDF.',
        'Unggah berkas <b>SPP2</b> yang telah diverifikasi kelengkapannya.',
        'Ketikkan kode keamanan <b>Captcha</b> yang tampil di layar secara teliti.',
        'Pastikan muncul konfirmasi teks bertuliskan: <i>"File berhasil diupload"</i>.',
        'Klik tombol <b>Setuju</b> pada pop-up konfirmasi. Sistem akan otomatis menerbitkan <b>Kode CMS</b> untuk diproses ke Bank.'
      ],
      escalation: 'Jika tombol Setuju tetap tidak aktif atau upload selalu gagal, gunakan tombol <b>Lapor Kendala</b> di atas.'
    },
    spp_akun_merah: {
      regex: /(?:akun\s*merah|status\s*merah|rekening\s*merah|merah\s*saat\s*verifikasi)/i,
      title: '🔴 Solusi Status Akun Merah saat Verifikasi SPP (Manual Hal. 10 Poin C)',
      steps: [
        'Indikator akun berwarna <b>MERAH</b> menandakan adanya ketidaksesuaian nomor rekening bank atau pagu kas anggaran di Siskeudes Online.',
        'Buka kembali aplikasi <b>Siskeudes Online Desktop</b> di komputer desa.',
        'Masuk ke menu <b>Penatausahaan ➔ SPP Definitif</b>.',
        'Periksa rekening kas desa pada menu tersebut, pastikan rekening bank dan rekening rincian belanja sudah sesuai.',
        'Lakukan pembetulan data hingga valid, lalu simpan perubahan.',
        'Refresh halaman Siskeudes-Link; indikator akan otomatis berubah menjadi <b>HIJAU</b> dan tombol verifikasi dapat diklik.'
      ],
      escalation: 'Bila data di Siskeudes Online sudah hijau namun di web tetap merah, laporkan kendala sinkronisasi master rekening via Form Lapor.'
    },
    spp_online_sync: {
      regex: /(?:alur\s*spp|spp\s*online|spp\s*belum\s*muncul|spp\s*tidak\s*ada|tarik\s*spp)/i,
      title: '🔄 Alur SPP Siskeudes Online ke Siskeudes-Link (Manual Hal. 4 & 10)',
      steps: [
        'Aplikasi Siskeudes-Link hanya menarik data SPP yang telah berstatus <b>FINAL</b> di Siskeudes Online.',
        'Buka aplikasi Siskeudes Online Desktop, masuk ke menu <b>SPP Pelaksana Kegiatan</b>.',
        'Pilih nomor SPP yang bersangkutan, ubah status dropdown dari Draft/Baru menjadi <b>"Final"</b>.',
        'Klik tombol <b>Simpan</b>.',
        'Buka kembali web Siskeudes-Link, buka menu <b>Data SPP</b> atau <b>Verifikasi SPP</b>, data akan otomatis tersinkronisasi.'
      ],
      escalation: 'Jika sudah berstatus Final tetapi belum muncul lebih dari 15 menit, lakukan sinkronisasi manual atau hubungi helpdesk.'
    },
    pajak_koreksi: {
      regex: /(?:koreksi\s*id\s*billing|id\s*billing|pajak\s*kadaluarsa|billing\s*kadaluarsa|pajak|hal(?:aman)?\s*15|hal(?:aman)?\s*16)/i,
      title: '📑 SOP Pajak & Koreksi ID Billing Kadaluarsa (Manual Hal. 15 & 16)',
      steps: [
        'Buka menu <b>Pajak</b> di panel navigasi Siskeudes-Link.',
        'Cari daftar potongan pajak dengan status <i>"Belum Bayar"</i> atau <i>"Billing Expired"</i>.',
        'Klik tombol <b>Koreksi ID Billing</b> pada baris transaksi pajak terkait.',
        'Ketikkan kode ID Billing baru yang diterbitkan oleh DJP Online / BPD.',
        'Klik <b>Simpan Koreksi</b>.',
        'Sistem CMS Bank Desa akan memproses pemotongan pajak otomatis sesuai ID Billing yang baru.'
      ],
      escalation: 'Jangan membatalkan SPP hanya karena ID Billing expired, cukup lakukan koreksi ID Billing pada menu Pajak.'
    },
    pembayaran_gagal: {
      regex: /(?:pembayaran\s*gagal|gagal\s*bank|menu\s*no\.?\s*7|menu\s*7|cms\s*bank\s*gagal|hal(?:aman)?\s*6)/i,
      title: '❌ Menu Pembayaran Gagal di CMS Bank Desa (Manual Hal. 6 Menu No. 7)',
      steps: [
        'Buka menu navigasi utama nomor 7: <b>Pembayaran Gagal</b> di Siskeudes-Link.',
        'Menu ini mencatat seluruh transaksi SPP yang ditolak oleh sistem core-banking CMS Bank Desa.',
        'Penyebab umum: Rekening tujuan pasif/dormant, salah nomor rekening, atau saldo rekening kas desa tidak mencukupi.',
        'Cek kolom <b>Keterangan Error Bank</b> pada baris transaksi.',
        'Jika rekening salah/dormant: Lakukan pembatalan verifikasi di menu Pembayaran Gagal, lalu perbaiki rekening penerima.',
        'Jika saldo kurang: Pastikan penambahan kas desa telah dibukukan oleh Bank mitra.'
      ],
      escalation: 'Bila keterangan error bank menyatakan <i>"Sistem Timeout / Host Unreachable"</i>, laporkan segera ke tim teknis iMD.'
    }
  };

  function handleLocalSopMatch(text) {
    for (const key in SOP_TEMPLATES) {
      const sop = SOP_TEMPLATES[key];
      if (sop.regex.test(text)) {
        appendMsg('user', text);
        let stepsHtml = sop.steps.map((st, idx) => `<div style="margin-bottom: 5px; display: flex; gap: 6px;"><span style="background: #e0e7ff; color: #3730a3; font-weight: 700; border-radius: 50%; min-width: 18px; height: 18px; font-size: 11px; display: inline-flex; align-items: center; justify-content: center;">${idx+1}</span><span>${st}</span></div>`).join('');
        
        let reply = `
          <div style="font-size: 13px; line-height: 1.45;">
            <b>${sop.title}</b><br><br>
            <div style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px; margin-bottom: 8px;">
              ${stepsHtml}
            </div>
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 6px 8px; font-size: 11.5px; color: #991b1b; margin-bottom: 8px;">
              💡 <b>Catatan Penting:</b> ${sop.escalation}
            </div>
            <div style="display: flex; gap: 6px;">
              <button type="button" class="ai-chip-btn ai-chip-issue" onclick="window.__prefillSiskeudesIssue()">🚨 Butuh Bantuan Teknis? Buat Laporan</button>
            </div>
          </div>
        `;
        appendMsg('bot', reply);
        return true;
      }
    }
    return false;
  }

  // Pre-fill Drawer saat pelapor menemukan kendala pada SPP tertentu
  window.__prefillSiskeudesIssue = function (spp, kwitansi, kodeSiskeudes, kodeBank) {
    toggleDrawer(true);
    if (spp) document.getElementById('ai-issue-spp').value = spp;
    if (kwitansi) document.getElementById('ai-issue-kwitansi').value = kwitansi;
    if (kodeSiskeudes) document.getElementById('ai-issue-kode-siskeudes').value = kodeSiskeudes;
    if (kodeBank) document.getElementById('ai-issue-kode-bank').value = kodeBank;
    const desc = document.getElementById('ai-issue-desc');
    if (desc && !desc.value) {
      desc.value = spp ? `Kendala transaksi pada SPP ${spp}: ` : '';
      desc.focus();
    }
  };

  async function sendChatMessage(text) {
    const sanitizedText = maskSensitivePII(text);
    appendMsg('user', sanitizedText);
    submitBtn.disabled = true;

    const loadingId = 'loading-' + Date.now();
    const loadingDiv = document.createElement('div');
    loadingDiv.id = loadingId;
    loadingDiv.className = 'ai-msg loading';
    loadingDiv.innerText = 'Menganalisis pesan...';
    msgContainer.appendChild(loadingDiv);
    msgContainer.scrollTop = msgContainer.scrollHeight;

    try {
      const res = await fetch(WEBHOOK_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          conversation_id: CONVERSATION_ID,
          user_id: USER_ID,
          user_name: USER_NAME,
          message: sanitizedText,
          channel: 'web'
        })
      });
      const data = await res.json();
      const el = document.getElementById(loadingId);
      if (el) el.remove();

      appendMsg('bot', data.reply || 'Tidak ada tanggapan.', data.intent, data);
    } catch {
      const el = document.getElementById(loadingId);
      if (el) el.remove();
      appendMsg('bot', 'Halo <b>' + displayName + '</b>! 👋 Asisten Digital Siskeudes TNT siap melayani Anda.<br><br>Untuk mengecek status pencairan SPP secara langsung, Anda dapat mengetikkan nomor SPP (contoh: <code>00001/SPP/51.03.01.2001/2026</code>) atau gunakan tombol <b>🚨 Form Lapor Kendala</b> jika mengalami kendala transaksi.');
    } finally {
      submitBtn.disabled = false;
      input.focus();
    }
  }

  function appendMsg(role, text, intent = null, rawData = null) {
    const div = document.createElement('div');
    div.className = `ai-msg ${role}`;
    if (role === 'bot') {
      let content = '';
      const isTicket = intent === 'report_customer_issue' || (rawData && rawData.is_ticket_report);
      
      if (isTicket) {
        content += `<div class="ai-intent-pill ticket">🚨 TIKET GANGGUAN SISKEUDES</div><br>`;
      } else if (intent && intent !== 'unknown' && intent !== 'greeting' && intent !== 'chitchat') {
        content += `<div class="ai-intent-pill">🎯 Intent: ${intent}</div><br>`;
      }
      
      content += text;

      // Render Visual Ticket Card jika terdeteksi laporan tiket
      if (isTicket && rawData && (rawData.ticket_number || rawData.ticket_data || rawData.ticket)) {
        const ticketObj = rawData.ticket || rawData.ticket_data || {};
        const ticketNum = rawData.ticket_number || ticketObj.ticket_number || 'TCK-BARU';
        const desa = ticketObj.nama_desa || rawData.nama_desa || '';
        const kabupaten = ticketObj.kabupaten || rawData.kabupaten || '';
        const spp = ticketObj.no_spp || rawData.no_spp || '';
        const kwitansi = ticketObj.no_kwitansi || rawData.no_kwitansi || '';
        const kodeSiskeudes = ticketObj.kode_cms_siskeudes || rawData.kode_cms_siskeudes || '';
        const kodeBank = ticketObj.kode_cms_bank || rawData.kode_cms_bank || '';
        const phone = ticketObj.reporter_phone || rawData.reporter_phone || '';

        const backendUrl = BACKEND_BASE_URL.replace(/\/$/, '');
        const magicLink = rawData.magic_link || ticketObj.magic_link || `${backendUrl}/api/v1/tickets/track/${ticketNum}?token=${ticketObj.tracking_token || ''}`;

        const jiraKey = (rawData.jira && rawData.jira.key) || ticketObj.jira_key || rawData.jira_key || null;
        const jiraUrl = (rawData.jira && rawData.jira.url) || ticketObj.jira_url || rawData.jira_url || (jiraKey ? `https://imtekservice.atlassian.net/browse/${jiraKey}` : null);

        content += `
          <div class="ai-ticket-card">
            <div class="ai-ticket-top">
              <span class="ai-ticket-num">🎫 #${ticketNum}</span>
              <span class="ai-ticket-tag-open">🔴 OPEN</span>
            </div>

            ${(desa || kabupaten) ? `
            <div class="ai-ticket-meta">
              <div>🏛️ <b>Wilayah:</b> Desa ${desa || '-'}, Kab. ${kabupaten || '-'}</div>
            </div>` : ''}

            ${(spp || kwitansi || kodeSiskeudes || kodeBank) ? `
            <div class="ai-ticket-grid">
              ${spp ? `<div><b>No. SPP:</b> ${spp}</div>` : ''}
              ${kwitansi ? `<div><b>No. Kwitansi:</b> ${kwitansi}</div>` : ''}
              ${kodeSiskeudes ? `<div><b>CMS SKD:</b> ${kodeSiskeudes}</div>` : ''}
              ${kodeBank ? `<div><b>CMS Bank:</b> ${kodeBank}</div>` : ''}
            </div>` : ''}

            ${phone ? `
            <div class="ai-ticket-wa-note">
              📱 <b>Notifikasi WhatsApp Aktif:</b> Tanda terima & link pelacak telah dikirim ke <b>${phone}</b>. Hasil akhir penanganan akan dikabarkan ke nomor ini saat selesai.
            </div>` : ''}

            ${jiraKey && jiraUrl ? `
            <div class="ai-ticket-jira-box">
              <div style="display: flex; align-items: center; gap: 5px;">
                <span>🌐</span>
                <span><b>Jira Service Desk:</b> SUP</span>
              </div>
              <a href="${jiraUrl}" target="_blank" rel="noopener noreferrer" class="ai-jira-badge-link">
                ${jiraKey} ↗
              </a>
            </div>` : ''}

            <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 8px;">
              <a href="${magicLink}" target="_blank" rel="noopener noreferrer" class="ai-ticket-magic-btn">
                🔗 Buka Link Pemantauan Mandiri ↗
              </a>
              <button type="button" class="ai-ticket-copy-btn" data-ticket="${ticketNum}">📋 Salin No. Tiket</button>
            </div>
          </div>
        `;
      }

      div.innerHTML = content;
    } else {
      div.innerText = text;
    }
    msgContainer.appendChild(div);
    msgContainer.scrollTop = msgContainer.scrollHeight;
  }

  // Handle Salin Nomor Tiket
  msgContainer.addEventListener('click', (e) => {
    const copyBtn = e.target.closest('.ai-ticket-copy-btn');
    if (copyBtn && copyBtn.dataset.ticket) {
      navigator.clipboard.writeText(copyBtn.dataset.ticket).then(() => {
        const originalText = copyBtn.innerText;
        copyBtn.innerText = '✓ Berhasil Disalin!';
        setTimeout(() => { copyBtn.innerText = originalText; }, 2000);
      }).catch(() => {
        alert('Nomor tiket: ' + copyBtn.dataset.ticket);
      });
    }
  });
})();
