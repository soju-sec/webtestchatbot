# Universal AI Chatbot (n8n + Laravel + Vanilla JS)

Arsitektur chatbot cerdas universal berbasis orkestrasi **n8n** dan **Google Gemini 2.5 Flash**, dirancang *plug-and-play*, *Manifest-Driven*, dilengkapi **Anti-PII Privacy Shield** (pencegahan kebocoran data sensitif seperti KTP, No HP, dll), **Isolasi Database Total (Zero Disruption)**, **Pemantauan Sisa & Pemakaian Token Terpisah**, serta bertindak murni sebagai **Intent Parser** cerdas dengan dukungan **Multi-Turn Conversation Memory**.

> 📖 **Panduan Lengkap Arsitektur & Keamanan**: Silakan baca [MANUAL_INTEGRASI.md](MANUAL_INTEGRASI.md).

---

## 🛡️ Fitur Keamanan, Stabilitas & Arsitektur Utama

1. **Anti-PII Privacy Shield (Data Masking Otomatis)**:
   - AI **TIDAK BISA** membaca atau menyimpan data pribadi sensitif pengguna.
   - Nomor KTP / NIK (16 digit), Nomor HP (+62/08...), Email, Nomor Kartu Kredit, PIN, dan Password otomatis disamarkan (*redacted*) sebelum sampai ke model AI maupun memori percakapan.
2. **Zero-Disruption Database Production (Isolasi Total)**:
   - Mendukung koneksi database mandiri (misal: `DB_CHATBOT_CONNECTION=chatbot_sqlite` atau `sqlite`) sehingga database MySQL/Postgres utama production **100% tidak tersentuh**.
   - Jika memakai database yang sama, seluruh tabel menggunakan prefix `chatbot_` tanpa foreign key ke tabel bisnis, menjamin zero table locks / cascades.
3. **Fitur Cek Sisa Token & Pemakaian Terpisah (Isolated Token Monitor)**:
   - Pantau total token terpakai (hari ini, bulan ini, all-time) dan estimasi sisa token bulanan.
   - Tersedia via **Web Dashboard** (`package-frontend/token-dashboard.html`), **CLI Terminal** (`./check-tokens.sh`), dan **Artisan Command** (`php artisan chatbot:tokens`).
   - Lengkap dengan **Live Token Calculator** untuk menguji estimasi token sebelum prompt dikirim.
4. **Gemini API Key Pool & Rotasi Otomatis (Anti-Kehabisan Kuota)**:
   - Mendukung multi-key di `.env` (`GEMINI_API_KEYS=key1,key2,key3`).
   - Fitur **Round-Robin Load Balancing** membagi beban request secara proporsional.
   - Fitur **Auto-Failover** otomatis mengalihkan request ke key cadangan jika ada key yang terkena *Rate Limit (429)* atau kehabisan kuota harian.
5. **Kompatibel Docker & Native**:
   - Mendukung lingkungan di mana aplikasi host berjalan secara Native (Nginx/Artisan) maupun Dockerized (Docker Compose), dengan fail-safe proxy (Circuit Breaker).
6. **Dedicated Intent & Entity Parser**:
   - AI agent bertugas murni memetakan maksud pengguna (*intent recognition*) dan mengekstrak parameter non-sensitif (*slot extraction*).
7. **Multi-Turn Contextual Memory**:
   - Memanfaatkan riwayat percakapan untuk memahami konteks lanjutan (misalnya: *"kalau bulan depannya lagi?"* atau klarifikasi pertanyaan).

---

## 📁 Struktur Direktori

```plaintext
chatbot/
├── docker-compose.yml        # Konfigurasi container n8n orchestrator (mendukung Docker & Host Native)
├── .env.example              # Template variabel lingkungan (termasuk kuota token & koneksi DB)
├── MANUAL_INTEGRASI.md       # Panduan lengkap integrasi, isolasi DB, Anti-PII & Token Tracking
├── README.md                 # Ringkasan cepat
├── check-tokens.sh           # Script cepat cek sisa token dari terminal
├── install.sh                # Script setup & validasi instalasi
├── n8n/
│   └── universal-chatbot-workflow.json # Alur orkestrasi n8n (Anti-PII, Intent Parser & Token Recorder)
├── package-backend/          # Salin folder ini ke proyek backend Laravel Anda
│   ├── Commands/
│   │   └── ChatbotTokenCommand.php # Perintah Artisan php artisan chatbot:tokens
│   ├── Services/
│   │   ├── ChatbotTokenService.php # Layanan tracking, kuota & live count token
│   │   └── PiiSanitizer.php        # Layanan sanitasi data sensitif (KTP, HP, dll.)
│   ├── config/
│   │   ├── chatbot.php             # Konfigurasi isolasi database & batas kuota token
│   │   └── ai_registry.php         # Tempat mendaftarkan manifest intent/kapabilitas API
│   ├── Controllers/
│   │   ├── AiCapabilityController.php
│   │   ├── ConversationMemoryController.php
│   │   └── TokenUsageController.php # Controller terpisah untuk cek sisa token
│   ├── Middleware/
│   │   └── VerifyInternalApiKey.php
│   ├── migrations/
│   │   └── 2025_01_01_000001_create_chatbot_tables.php # Migrasi tabel terisolasi
│   ├── routes/
│   │   └── chatbot.php             # Route terisolasi (cukup 1 baris include di routes/api.php)
│   └── bin/
│       └── check-tokens.sh         # Script bash mandiri pengecekan token
└── package-frontend/         # Widget chat & dashboard token terpisah
    ├── chat-widget.js        # Script widget publik (Anti-PII & intent tag)
    ├── chat-widget.css       # Style widget publik
    ├── index.html            # Halaman demo integrasi chat
    └── token-dashboard.html  # Dashboard pemantauan sisa & pemakaian token (khusus admin/terpisah)
```

---

## 🚀 Panduan Memulai Cepat

### 1. Setup & Jalankan Orkestrator n8n

1. Salin `.env.example` ke `.env`:
   ```bash
   cp .env.example .env
   ```
2. Isi `GEMINI_API_KEY` (dapatkan gratis di [Google AI Studio](https://aistudio.google.com/)).
3. Tentukan `BACKEND_BASE_URL` (contoh: `http://host.docker.internal:8000` jika Laravel berjalan native di host, atau nama service container jika Dockerized).
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

## 🛠️ 2. Integrasi Backend (Laravel/PHP) - Bebas Gangguan

1. Salin file dari `package-backend/` ke project Laravel Anda:
   - `Commands/*` -> `app/Console/Commands/`
   - `Services/*` -> `app/Services/`
   - `config/*` -> `config/`
   - `Controllers/*` -> `app/Http/Controllers/Api/`
   - `Middleware/*` -> `app/Http/Middleware/`
   - `migrations/*` -> `database/migrations/`
   - `routes/chatbot.php` -> `routes/chatbot.php`
2. **Isolasi Database (Opsional & Direkomendasikan)**:
   Jika ingin memisahkan database chat dari database transaksi bisnis, set di `.env`:
   ```env
   DB_CHATBOT_CONNECTION=sqlite
   ```
3. Jalankan migrasi:
   ```bash
   php artisan migrate
   ```
4. Di file `routes/api.php`, cukup sertakan 1 baris berikut:
   ```php
   require __DIR__ . '/chatbot.php';
   ```

---

## 🪙 3. Memeriksa Sisa Token & Pemakaian (Terpisah)

Pilih metode yang paling nyaman bagi Anda:

### Opsi A: Via Terminal (Bash)
```bash
./check-tokens.sh
```

### Opsi B: Via Artisan Command
```bash
php artisan chatbot:tokens --history
```

### Opsi C: Via Web Dashboard (Admin UI)
Buka file `package-frontend/token-dashboard.html` di browser Anda untuk melihat gauge sisa kuota, grafik pemakaian, dan live token counter.

---

## 💻 4. Integrasi Frontend Widget

Cukup tambahkan satu script tag di layout aplikasi web Anda (`app.blade.php` atau `index.html`):

```html
<script 
  src="/chat-widget.js" 
  data-webhook="/api/webhook/chat-entry" 
  data-user-id="user-123">
</script>
```

---

## 💡 5. Menambahkan Data / API Baru untuk Chatbot

Gunakan script otomatis untuk mendaftarkan endpoint/intent baru:
```bash
./register-ai-capability.sh --url="http://localhost:8088/api/v1/products"
```
Lihat panduan lengkap di **[MANUAL_INTEGRASI.md](MANUAL_INTEGRASI.md)**.
