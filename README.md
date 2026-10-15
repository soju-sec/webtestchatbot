# Universal AI Chatbot (n8n + Laravel + Vanilla JS)

Arsitektur chatbot cerdas universal berbasis orkestrasi **n8n** dan **Google Gemini 2.5 Flash**, dirancang *plug-and-play*, *Manifest-Driven*, dilengkapi **Anti-PII Privacy Shield** (pencegahan kebocoran data sensitif seperti KTP, No HP, dll) dan berfokus murni sebagai **Intent Parser** cerdas dengan dukungan **Multi-Turn Conversation Memory**.

> 📖 **Panduan Lengkap Arsitektur & Keamanan**: Silakan baca [MANUAL_INTEGRASI.md](MANUAL_INTEGRASI.md).

---

## 🛡️ Fitur Keamanan & Arsitektur Utama

1. **Anti-PII Privacy Shield (Data Masking)**:
   - AI **TIDAK BISA** membaca atau menyimpan data pribadi sensitif pengguna.
   - Nomor KTP / NIK (16 digit), Nomor HP (+62/08...), Email, Nomor Kartu Kredit, PIN, dan Password otomatis disamarkan (*redacted*) sebelum sampai ke model AI maupun memori percakapan.
2. **Dedicated Intent & Entity Parser**:
   - AI agent bertugas murni memetakan maksud pengguna (*intent recognition*) dan mengekstrak parameter non-sensitif (*slot extraction*).
   - AI tidak mengakses langsung basis data mentah.
3. **Multi-Turn Contextual Memory**:
   - Memanfaatkan riwayat percakapan untuk memahami konteks lanjutan (misalnya: *"kalau bulan depannya lagi?"* atau klarifikasi pertanyaan).
4. **Programmatic Safe Fulfillment**:
   - Data bisnis diproses dan disajikan secara aman dan terprogram di level sistem backend/orkestrator.

---

## 📁 Struktur Direktori

```plaintext
chatbot/
├── docker-compose.yml        # Konfigurasi container n8n orchestrator
├── .env.example              # Template variabel lingkungan
├── MANUAL_INTEGRASI.md       # Panduan lengkap integrasi, Anti-PII & Intent Parser
├── README.md                 # Ringkasan cepat
├── n8n/
│   └── universal-chatbot-workflow.json # Alur orkestrasi n8n (Anti-PII & Intent Parser)
├── package-backend/          # Salin folder ini ke proyek backend Laravel Anda
│   ├── Services/
│   │   └── PiiSanitizer.php  # Layanan sanitasi & masking data sensitif (KTP, HP, dll.)
│   ├── config/
│   │   └── ai_registry.php   # Tempat mendaftarkan manifest intent/kapabilitas API
│   ├── Controllers/
│   │   ├── AiCapabilityController.php
│   │   └── ConversationMemoryController.php
│   ├── Middleware/
│   │   └── VerifyInternalApiKey.php
│   └── migrations/
│       └── create_conversation_messages_table.php
└── package-frontend/         # Widget chat mandiri (bisa di-embed via 1 tag script)
    ├── chat-widget.js        # Script widget (dengan client-side protection & intent badge)
    ├── chat-widget.css       # Style widget
    └── index.html            # Halaman demo integrasi
```

---

## 🚀 Panduan Memulai Cepat

### 1. Setup & Jalankan Orkestrator n8n

1. Salin `.env.example` ke `.env`:
   ```bash
   cp .env.example .env
   ```
2. Isi `GEMINI_API_KEY` (dapatkan gratis di [Google AI Studio](https://aistudio.google.com/)).
3. Tentukan `BACKEND_BASE_URL` (contoh: `http://127.0.0.1:8088` untuk Linux host, atau `http://host.docker.internal:8000`).
4. Jalankan container n8n:
   ```bash
   docker compose up -d
   ```
5. Import & aktifkan workflow:
   ```bash
   docker exec universal_chatbot_n8n n8n import:workflow --input=/backup/n8n/universal-chatbot-workflow.json
   docker exec universal_chatbot_n8n n8n publish:workflow --id=universal-chatbot
   docker restart universal_chatbot_n8n
   ```

---

## 🛠️ 2. Integrasi Backend (Laravel/PHP)

1. Salin file dari `package-backend/` ke project Laravel Anda:
   - `Services/*` -> `app/Services/`
   - `config/ai_registry.php` -> `config/ai_registry.php`
   - `Controllers/*` -> `app/Http/Controllers/Api/`
   - `Middleware/*` -> `app/Http/Middleware/`
   - `migrations/*` -> `database/migrations/`
2. Jalankan migrasi:
   ```bash
   php artisan migrate
   ```
3. Daftarkan route di `routes/api.php`:
   ```php
   use App\Http\Controllers\Api\AiCapabilityController;
   use App\Http\Controllers\Api\ConversationMemoryController;
   use App\Http\Middleware\VerifyInternalApiKey;

   // Proxy route untuk menghindari CORS dari widget ke n8n
   Route::post('/webhook/chat-entry', function (Request $request) {
       $n8nUrl = env('N8N_WEBHOOK_URL', 'http://127.0.0.1:5678/webhook/chat-entry');
       $response = \Illuminate\Support\Facades\Http::timeout(60)->post($n8nUrl, $request->all());
       return response()->json($response->json(), $response->status());
   });

   // Endpoint internal untuk n8n
   Route::middleware([VerifyInternalApiKey::class])->prefix('v1')->group(function () {
       Route::get('/ai/manifest', [AiCapabilityController::class, 'manifest']);
       Route::get('/conversations/{conversationId}/messages', [ConversationMemoryController::class, 'getMessages']);
       Route::post('/conversations/{conversationId}/messages', [ConversationMemoryController::class, 'storeMessage']);
   });
   ```

> ⚠️ **Catatan Penting saat Menjalankan Native**:
> Jika menjalankan via `php artisan serve`, gunakan multi-worker untuk mencegah deadlock:
> ```bash
> PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0 --port=8088 --no-reload
> ```

---

## 💻 3. Integrasi Frontend Widget

Cukup tambahkan satu script tag di layout aplikasi web Anda (`app.blade.php` atau `index.html`):

```html
<script 
  src="/chat-widget.js" 
  data-webhook="/api/webhook/chat-entry" 
  data-user-id="user-123">
</script>
```

---

## 💡 4. Menambahkan Data / API Baru untuk Chatbot

Gunakan script otomatis untuk mendaftarkan endpoint/intent baru:
```bash
./register-ai-capability.sh --url="http://localhost:8088/api/v1/products"
```
Lihat panduan lengkap di **[MANUAL_INTEGRASI.md](MANUAL_INTEGRASI.md)**.
