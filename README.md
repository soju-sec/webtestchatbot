# Universal AI Chatbot (n8n + Laravel + Vanilla JS)

Arsitektur chatbot cerdas universal berbasis orkestrasi **n8n** dan **Google Gemini**, dirancang *plug-and-play* untuk proyek backend (Laravel/PHP) dan web frontend apa pun.

---

## 📁 Struktur Direktori

```plaintext
chatbot/
├── .gitlab-ci.yml
├── docker-compose.yml
├── .env.example
├── .gitignore
├── install.sh
├── README.md
├── n8n/
│   └── universal-chatbot-workflow.json
├── package-backend/          # Salin folder ini ke proyek backend mana pun
│   ├── config/
│   │   └── ai_registry.php   # Tempat mendaftarkan endpoint API proyek
│   ├── Controllers/
│   │   ├── AiCapabilityController.php
│   │   └── ConversationMemoryController.php
│   ├── Middleware/
│   │   └── VerifyInternalApiKey.php
│   └── migrations/
│       └── create_conversation_messages_table.php
└── package-frontend/         # Widget chat mandiri (bisa di-embed via 1 tag script)
    ├── chat-widget.js
    ├── chat-widget.css
    └── index.html            # Halaman demo integrasi
```

---

## 🚀 Panduan Memulai Cepat

### 1. Setup & Jalankan Orkestrator n8n

1. Jalankan script instalasi:
   ```bash
   ./install.sh
   ```
2. Sesuaikan konfigurasi di `.env`:
   - `GEMINI_API_KEY`: Kunci API Google Gemini dari Google AI Studio.
   - `INTERNAL_API_KEY`: Kunci otentikasi internal antara n8n dan backend.
   - `BACKEND_BASE_URL`: URL backend Laravel (contoh: `http://host.docker.internal:8000`).
3. Jalankan container n8n:
   ```bash
   docker compose up -d
   ```
4. Buka n8n di browser pada `http://localhost:5678`.
5. Import workflow dari `n8n/universal-chatbot-workflow.json` dan aktifkan workflow.

---

## 🛠️ 1. Integrasi Backend (Laravel/PHP)

Cukup salin isi folder `package-backend/` ke proyek Laravel Anda:

1. Salin `config/ai_registry.php` ke `config/ai_registry.php`.
2. Salin Controllers ke `app/Http/Controllers/Api/`:
   - `AiCapabilityController.php`
   - `ConversationMemoryController.php`
3. Salin Middleware ke `app/Http/Middleware/VerifyInternalApiKey.php`.
4. Salin migrasi ke `database/migrations/` dan jalankan:
   ```bash
   php artisan migrate
   ```
5. Daftarkan rute API di `routes/api.php`:
   ```php
   use App\Http\Controllers\Api\AiCapabilityController;
   use App\Http\Controllers\Api\ConversationMemoryController;
   use App\Http\Middleware\VerifyInternalApiKey;

   Route::middleware([VerifyInternalApiKey::class])->prefix('v1')->group(function () {
       Route::get('/ai/manifest', [AiCapabilityController::class, 'manifest']);
       Route::get('/conversations/{conversationId}/messages', [ConversationMemoryController::class, 'getMessages']);
       Route::post('/conversations/{conversationId}/messages', [ConversationMemoryController::class, 'storeMessage']);
   });
   ```

---

## 💻 2. Integrasi Frontend (Vanilla JS)

Cukup sematkan script widget ke template web atau HTML mana pun:

```html
<script 
  src="/path-to/chat-widget.js" 
  data-webhook="http://localhost:5678/webhook/chat-entry" 
  data-user-id="USER-123">
</script>
```

Widget akan otomatis membuat bubble chat mengambang dan menangani seluruh siklus percakapan dengan webhook n8n.

---

## 🔄 3. CI/CD Pipeline (.gitlab-ci.yml)

Pipeline GitLab CI sudah disiapkan untuk memvalidasi:
- Sintaks dan struktur JSON workflow n8n (`n8n/universal-chatbot-workflow.json`).
- Sintaks PHP untuk seluruh file di dalam `package-backend/`.
