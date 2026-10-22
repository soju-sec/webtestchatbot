(function () {
  const currentScript = document.currentScript || document.querySelector('script[data-webhook]');
  const WEBHOOK_URL = (currentScript && currentScript.getAttribute('data-webhook')) || 'http://localhost:5678/webhook/chat-entry';
  const USER_ID = (currentScript && currentScript.getAttribute('data-user-id')) || 'guest-user';
  const USER_NAME = (currentScript && currentScript.getAttribute('data-user-name')) || '';
  const BOT_NAME = (currentScript && currentScript.getAttribute('data-bot-name')) || 'Asisten Virtual';
  const CUSTOM_GREETING = (currentScript && currentScript.getAttribute('data-greeting')) || '';
  const CONVERSATION_ID = `conv-${USER_ID}-${Math.random().toString(36).substring(2, 9)}`;

  // Tentukan nama sapaan ramah
  const displayName = USER_NAME ? USER_NAME : (USER_ID && USER_ID !== 'guest-user' && USER_ID !== 'guest' ? USER_ID : 'Kak');

  // Client-side PII Masker (Lapisan Pertahanan Awal: KTP/NIK, No HP, Email, Password disamarkan)
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
      #ai-chat-box { display: none; position: fixed; bottom: 88px; right: 20px; width: 380px; max-width: calc(100vw - 32px); height: 550px; max-height: calc(100vh - 110px); background: #fff; border-radius: 14px; box-shadow: 0 10px 30px rgba(0,0,0,0.18); flex-direction: column; z-index: 99999; overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; border: 1px solid #e2e8f0; animation: ai-slide-up 0.25s ease-out; }
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
      .ai-msg { max-width: 84%; padding: 9px 13px; border-radius: 10px; line-height: 1.45; word-break: break-word; font-size: 13px; }
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
      .ai-ticket-card { background: #fff5f5; border: 1.5px solid #fca5a5; border-radius: 8px; padding: 10px; margin-top: 8px; font-size: 12.5px; }
      .ai-ticket-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; border-bottom: 1px solid #fed7aa; padding-bottom: 4px; }
      .ai-ticket-num { font-weight: 800; color: #991b1b; font-size: 13px; font-family: monospace; }
      .ai-ticket-tag-open { background: #dc2626; color: #fff; font-size: 9.5px; font-weight: 700; padding: 2px 6px; border-radius: 4px; }
      .ai-ticket-meta { margin-bottom: 6px; line-height: 1.4; color: #334155; }
      .ai-ticket-alert-box { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 6px 8px; font-size: 11.5px; color: #065f46; display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
      .ai-pulse-dot { width: 8px; height: 8px; border-radius: 50%; background: #10b981; animation: ai-pulse 1.5s infinite; flex-shrink: 0; }
      @keyframes ai-pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(1.3); } }
      .ai-ticket-copy-btn { background: #fee2e2; border: 1px solid #f87171; color: #991b1b; font-size: 11px; font-weight: 600; padding: 4px 8px; border-radius: 4px; cursor: pointer; transition: background 0.15s; }
      .ai-ticket-copy-btn:hover { background: #fecaca; }

      /* Issue Reporting Drawer */
      #ai-issue-drawer { display: none; background: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 12px 14px; box-shadow: 0 4px 10px rgba(0,0,0,0.06); animation: ai-fade-in 0.2s ease; }
      @keyframes ai-fade-in { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
      .ai-drawer-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 13.5px; color: #0f172a; }
      #ai-drawer-close { background: none; border: none; font-size: 16px; cursor: pointer; color: #64748b; }
      .ai-drawer-label { font-size: 11.5px; font-weight: 600; color: #475569; margin-top: 6px; margin-bottom: 3px; display: block; }
      .ai-drawer-input, .ai-drawer-textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 8px; font-size: 12.5px; font-family: inherit; outline: none; box-sizing: border-box; }
      .ai-drawer-input:focus, .ai-drawer-textarea:focus { border-color: #2563eb; }
      .ai-drawer-notice { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 6px 8px; font-size: 11px; color: #1e40af; margin-top: 8px; }
      .ai-drawer-actions { display: flex; gap: 6px; margin-top: 10px; }
      .ai-drawer-submit-btn { flex: 1; background: #dc2626; color: #fff; border: none; border-radius: 6px; padding: 7px; font-size: 12px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
      .ai-drawer-submit-btn:hover { background: #b91c1c; }
      .ai-drawer-cancel-btn { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 6px; padding: 7px 12px; font-size: 12px; cursor: pointer; }

      /* Chat Form */
      #ai-chat-form { display: flex; border-top: 1px solid #e2e8f0; padding: 10px; background: #fff; gap: 6px; align-items: center; }
      #ai-chat-input { flex: 1; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 12px; outline: none; font-size: 13px; transition: border-color 0.2s; }
      #ai-chat-input:focus { border-color: #2563eb; }
      #ai-chat-submit { background: #2563eb; color: #fff; border: none; border-radius: 8px; padding: 9px 14px; cursor: pointer; font-size: 13px; font-weight: 600; transition: background 0.2s; }
      #ai-chat-submit:hover { background: #1d4ed8; }
      #ai-chat-submit:disabled { opacity: 0.6; cursor: not-allowed; }
    </style>
    <button id="ai-chat-btn" title="Buka Percakapan Asisten">💬</button>
    <div id="ai-chat-box">
      <div id="ai-chat-header">
        <div class="ai-chat-title-box">
          <span class="ai-chat-main-title">${BOT_NAME}</span>
          <span class="ai-chat-privacy-tag">🛡️ Anti-PII & Tiket Terintegrasi</span>
        </div>
        <div class="ai-header-btns">
          <button type="button" id="ai-chat-report-btn" class="ai-header-action-btn" title="Buat Laporan Gangguan Fitur">🚨 Lapor Masalah</button>
          <button type="button" id="ai-chat-close-btn" title="Tutup Chat">✕</button>
        </div>
      </div>

      <!-- Quick Issue Report Drawer -->
      <div id="ai-issue-drawer">
        <div class="ai-drawer-header">
          <span>🚨 <b>Laporkan Kendala / Gangguan Fitur</b></span>
          <button type="button" id="ai-drawer-close">✕</button>
        </div>
        <div>
          <label class="ai-drawer-label">Fitur yang Terkendala:</label>
          <select id="ai-issue-feature" class="ai-drawer-input">
            <option value="Menu Transaksi">Menu Riwayat Transaksi</option>
            <option value="Halaman Checkout & Pembayaran">Halaman Checkout / Pembayaran</option>
            <option value="Laporan & Revenue">Laporan & Revenue Penjualan</option>
            <option value="Akun & Login">Akun, Login & Autentikasi</option>
            <option value="Fitur Lainnya">Fitur Sistem Lainnya</option>
          </select>
          <label class="ai-drawer-label">Deskripsi Kendala yang Dialami:</label>
          <textarea id="ai-issue-desc" class="ai-drawer-textarea" rows="2" placeholder="Contoh: Fitur tidak dapat diakses, muncul pesan error 500..."></textarea>
          <label class="ai-drawer-label">Tingkat Urgensi:</label>
          <select id="ai-issue-urgency" class="ai-drawer-input">
            <option value="tinggi">Tinggi (Menghambat Transaksi/Pekerjaan)</option>
            <option value="sedang">Sedang (Bisa diakses sebagian)</option>
            <option value="rendah">Rendah (Minor / Feedback)</option>
          </select>
          <div class="ai-drawer-notice">
            📢 Tiket laporan akan otomatis diteruskan ke <b>Tim Internal (Telegram / WhatsApp)</b>.
          </div>
          <div class="ai-drawer-actions">
            <button type="button" id="ai-issue-submit" class="ai-drawer-submit-btn">🚀 Kirim Tiket Gangguan</button>
            <button type="button" id="ai-issue-cancel" class="ai-drawer-cancel-btn">Batal</button>
          </div>
        </div>
      </div>

      <div id="ai-chat-msgs">
        <div class="ai-msg bot" id="ai-initial-greeting">
          ${CUSTOM_GREETING ? CUSTOM_GREETING : `Halo <b>${displayName}</b>! 👋 Ada yang bisa kami bantu hari ini?<br><br>Silakan pilih bantuan cepat di bawah, tanyakan informasi data toko, atau laporkan kendala jika ada fitur yang tidak dapat diakses:`}
          <div class="ai-quick-chips">
            <button type="button" class="ai-chip-btn" data-query="Cek ringkasan metrik toko saya">📊 Metrik Toko</button>
            <button type="button" class="ai-chip-btn" data-query="Tampilkan performa revenue bulanan">📈 Laporan Revenue</button>
            <button type="button" class="ai-chip-btn ai-chip-issue" id="ai-btn-open-issue-form">🚨 Lapor Kendala Fitur</button>
          </div>
        </div>
      </div>
      <form id="ai-chat-form">
        <input type="text" id="ai-chat-input" placeholder="Ketik pesan atau laporkan kendala..." required autocomplete="off" />
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
      const desc = document.getElementById('ai-issue-desc');
      if (desc) desc.focus();
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
    if (chip && chip.dataset.query) {
      input.value = chip.dataset.query;
      form.requestSubmit();
    } else if (chip && chip.id === 'ai-btn-open-issue-form') {
      toggleDrawer(true);
    }
  });

  // Submit Issue Report via Drawer
  if (drawerSubmit) {
    drawerSubmit.addEventListener('click', () => {
      const feature = document.getElementById('ai-issue-feature').value;
      const desc = document.getElementById('ai-issue-desc').value.trim();
      const urgency = document.getElementById('ai-issue-urgency').value;

      if (!desc) {
        alert('Mohon isi deskripsi kendala yang dialami.');
        return;
      }

      toggleDrawer(false);
      const reportMessage = `Min, saya mau lapor kendala: Fitur "${feature}" bermasalah/tidak dapat diakses. Detail: ${desc} (Urgensi: ${urgency})`;
      document.getElementById('ai-issue-desc').value = '';
      sendChatMessage(reportMessage);
    });
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    sendChatMessage(text);
  });

  async function sendChatMessage(text) {
    // Disaring sebelum dikirim (defense-in-depth)
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
      appendMsg('bot', 'Layanan tidak dapat dihubungi saat ini. Silakan coba beberapa saat lagi.');
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
        content += `<div class="ai-intent-pill ticket">🚨 TIKET GANGGUAN SISTEM</div><br>`;
      } else if (intent && intent !== 'unknown' && intent !== 'greeting' && intent !== 'chitchat') {
        content += `<div class="ai-intent-pill">🎯 Intent: ${intent}</div><br>`;
      }
      
      content += text;

      // Render Visual Ticket Card jika terdeteksi laporan tiket
      if (isTicket && rawData && (rawData.ticket_number || rawData.ticket_data)) {
        const ticketNum = rawData.ticket_number || (rawData.ticket_data && rawData.ticket_data.ticket_number) || 'TCK-BARU';
        const feature = (rawData.ticket_data && rawData.ticket_data.feature_name) || (rawData.parameters && rawData.parameters.feature_name) || 'Fitur Sistem';
        const urgency = ((rawData.ticket_data && rawData.ticket_data.urgency) || (rawData.parameters && rawData.parameters.urgency) || 'TINGGI').toUpperCase();

        content += `
          <div class="ai-ticket-card">
            <div class="ai-ticket-top">
              <span class="ai-ticket-num">🎫 #${ticketNum}</span>
              <span class="ai-ticket-tag-open">🔴 OPEN</span>
            </div>
            <div class="ai-ticket-meta">
              <div><b>🛠 Fitur:</b> ${feature}</div>
              <div><b>⚡ Urgensi:</b> ${urgency}</div>
            </div>
            <div class="ai-ticket-alert-box">
              <span class="ai-pulse-dot"></span>
              <span>Notifikasi tiket telah diteruskan ke <b>Tim Internal via Telegram & WhatsApp</b>.</span>
            </div>
            <button type="button" class="ai-ticket-copy-btn" data-ticket="${ticketNum}">📋 Salin Nomor Tiket</button>
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
