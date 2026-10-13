(function () {
  const currentScript = document.currentScript || document.querySelector('script[data-webhook]');
  const WEBHOOK_URL = (currentScript && currentScript.getAttribute('data-webhook')) || 'http://localhost:5678/webhook/chat-entry';
  const USER_ID = (currentScript && currentScript.getAttribute('data-user-id')) || 'guest-user';
  const CONVERSATION_ID = `conv-${USER_ID}-${Math.random().toString(36).substring(2, 9)}`;

  // Buat floating chat button & container
  const container = document.createElement('div');
  container.id = 'ai-chat-root';
  container.innerHTML = `
    <style>
      #ai-chat-btn { position: fixed; bottom: 20px; right: 20px; width: 56px; height: 56px; border-radius: 50%; background: #2563eb; color: #fff; border: none; cursor: pointer; font-size: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); z-index: 9999; display: flex; align-items: center; justify-content: center; transition: transform 0.2s; }
      #ai-chat-btn:hover { transform: scale(1.05); }
      #ai-chat-box { display: none; position: fixed; bottom: 85px; right: 20px; width: 360px; height: 500px; background: #fff; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.15); flex-direction: column; z-index: 9999; overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
      #ai-chat-header { background: #2563eb; color: #fff; padding: 14px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
      #ai-chat-header span { font-size: 15px; }
      #ai-chat-close-btn { background: none; border: none; color: #fff; font-size: 18px; cursor: pointer; padding: 0 4px; }
      #ai-chat-msgs { flex: 1; padding: 12px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; font-size: 13px; background: #f8fafc; }
      .ai-msg { max-width: 80%; padding: 8px 12px; border-radius: 8px; line-height: 1.4; word-break: break-word; }
      .ai-msg.user { align-self: flex-end; background: #2563eb; color: #fff; }
      .ai-msg.bot { align-self: flex-start; background: #f1f5f9; color: #1e293b; border: 1px solid #e2e8f0; }
      .ai-msg.loading { align-self: flex-start; background: #f1f5f9; color: #64748b; font-style: italic; }
      #ai-chat-form { display: flex; border-top: 1px solid #e2e8f0; padding: 8px; background: #fff; }
      #ai-chat-input { flex: 1; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; outline: none; font-size: 13px; }
      #ai-chat-input:focus { border-color: #2563eb; }
      #ai-chat-submit { background: #2563eb; color: #fff; border: none; border-radius: 6px; margin-left: 6px; padding: 0 12px; cursor: pointer; font-size: 13px; }
      #ai-chat-submit:disabled { opacity: 0.6; cursor: not-allowed; }
    </style>
    <button id="ai-chat-btn" title="Buka Chat">💬</button>
    <div id="ai-chat-box">
      <div id="ai-chat-header">
        <span>Asisten Virtual</span>
        <button id="ai-chat-close-btn" title="Tutup Chat">✕</button>
      </div>
      <div id="ai-chat-msgs">
        <div class="ai-msg bot">Halo! Ada yang bisa saya bantu terkait akun atau layanan Anda?</div>
      </div>
      <form id="ai-chat-form">
        <input type="text" id="ai-chat-input" placeholder="Ketik pesan..." required autocomplete="off" />
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

  function toggleChat() {
    const isVisible = box.style.display === 'flex';
    box.style.display = isVisible ? 'none' : 'flex';
    if (!isVisible) {
      input.focus();
    }
  }

  btn.addEventListener('click', toggleChat);
  if (closeBtn) {
    closeBtn.addEventListener('click', () => {
      box.style.display = 'none';
    });
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;

    appendMsg('user', text);
    input.value = '';
    submitBtn.disabled = true;

    const loadingId = 'loading-' + Date.now();
    const loadingDiv = document.createElement('div');
    loadingDiv.id = loadingId;
    loadingDiv.className = 'ai-msg loading';
    loadingDiv.innerText = 'Mengetik...';
    msgContainer.appendChild(loadingDiv);
    msgContainer.scrollTop = msgContainer.scrollHeight;

    try {
      const res = await fetch(WEBHOOK_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          conversation_id: CONVERSATION_ID,
          user_id: USER_ID,
          message: text,
          channel: 'web'
        })
      });
      const data = await res.json();
      const el = document.getElementById(loadingId);
      if (el) el.remove();
      appendMsg('bot', data.reply || 'Tidak ada tanggapan.');
    } catch {
      const el = document.getElementById(loadingId);
      if (el) el.remove();
      appendMsg('bot', 'Layanan tidak dapat dihubungi saat ini.');
    } finally {
      submitBtn.disabled = false;
      input.focus();
    }
  });

  function appendMsg(role, text) {
    const div = document.createElement('div');
    div.className = `ai-msg ${role}`;
    if (role === 'bot') {
      div.innerHTML = text;
    } else {
      div.innerText = text;
    }
    msgContainer.appendChild(div);
    msgContainer.scrollTop = msgContainer.scrollHeight;
  }
})();
