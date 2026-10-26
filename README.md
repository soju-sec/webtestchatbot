# Universal AI Chatbot (n8n + Laravel + Vanilla JS)

Arsitektur chatbot cerdas universal berstandar enterprise berbasis orkestrasi **n8n** dan **Google Gemini 2.5 Flash**, dirancang *plug-and-play*, *Manifest-Driven*, dilengkapi **Anti-PII Privacy Shield** (pencegahan kebocoran data sensitif seperti KTP, No HP, dll), **Isolasi Database Total (Zero Disruption)**, **Sistem Tiket Gangguan & Alert Realtime Tim Internal (Slack Channel & Multi-Channel)**, **Sapaan Awal Personal & Quick Action Chips (Widget v2.0)**, serta **Pemantauan Sisa & Pemakaian Token Terpisah**.

> 📚 **Dokumentasi Lengkap**:
> - 🏛️ **[LAPORAN_ARSITEKTUR_DAN_IMPLEMENTASI.md](LAPORAN_ARSITEKTUR_DAN_IMPLEMENTASI.md)**: Dokumen arsitektur teknis mendalam, analisis zero-knowledge LLM, diagram topologi/flowchart, dan kalkulasi token.
> - 📖 **[MANUAL_INTEGRASI.md](MANUAL_INTEGRASI.md)**: Panduan langkah-demi-langkah integrasi, panduan setup Slack Webhook, Telegram/WhatsApp, isolasi database, dan registrasi API.

---

## 🛡️ Fitur Keamanan, Stabilitas & Arsitektur Utama

1. **Anti-PII Privacy Shield (Data Masking Otomatis)**:
   - AI **TIDAK BISA** membaca atau menyimpan data pribadi sensitif pengguna.
   - Nomor KTP / NIK (16 digit), Nomor HP (+62/08...), Email, Nomor Kartu Kredit, PIN, OTP, dan Password otomatis disamarkan (*redacted*) sebelum menyentuh model AI maupun database memori obrolan.
2. **Zero-Disruption Database Production (Isolasi Total)**:
   - Mendukung koneksi database mandiri (misal: `DB_CHATBOT_CONNECTION=sqlite` atau `chatbot_db`) sehingga database MySQL/Postgres utama production **100% tidak tersentuh**.
   - Jika memakai database yang sama, seluruh tabel menggunakan prefix `chatbot_` tanpa foreign key ke tabel bisnis, menjamin zero table locks / cascades.
3. **Sistem Tiket Gangguan & Alert Realtime Tim Internal (Slack Channel & Multi-Channel)**:
   - Jika pelanggan mengeluhkan kendala teknis (misal: fitur tidak dapat diakses, error 500, atau kendala transaksi) baik lewat chat maupun formulir kendala (*Issue Drawer*), AI otomatis membuat tiket resmi `#TCK-YYYYMMDD-XXXX`.
   - Tiket langsung disimpan di tabel `chatbot_tickets` dan sistem men-dispatch notifikasi instan ke **Channel Slack Tim Internal** (`#customer-tickets`) dengan tata letak visual **Slack Block Kit** (indikator warna tingkat urgensi, detail masalah, pelapor, dan thread diskusi tim). Opsi pengiriman ke Telegram Bot dan WhatsApp Gateway juga tetap tersedia sebagai saluran alternatif/cadangan.
4. **Sapaan Awal Personal & Quick Action Chips (Widget v2.0)**:
   - Saat chat dibuka, bot otomatis menyapa dengan nama user (misal: *"Halo Budi Pratama! Ada yang bisa kami bantu?"*) lengkap dengan tombol shortcut bantuan cepat (Metrik Toko, Laporan Revenue, Lapor Kendala Fitur).
5. **Fitur Cek Sisa Token & Pemakaian Terpisah (Isolated Token Monitor)**:
   - Pantau total token terpakai (hari ini, bulan ini, all-time) dan estimasi sisa token bulanan.
   - Tersedia via **Web Dashboard** (`package-frontend/token-dashboard.html`), **CLI Terminal** (`./check-tokens.sh`), dan **Artisan Command** (`php artisan chatbot:tokens`).
   - Lengkap dengan **Live Token Calculator** untuk menguji estimasi token sebelum prompt dikirim.
6. **Gemini API Key Pool & Rotasi Otomatis (Anti-Kehabisan Kuota)**:
   - Mendukung multi-key di `.env` (`GEMINI_API_KEYS=key1,key2,key3`).
   - Fitur **Round-Robin Load Balancing** membagi beban request secara proporsional.
   - Fitur **Auto-Failover** otomatis mengalihkan request ke key cadangan jika ada key yang terkena *Rate Limit (HTTP 429)* atau kuota harian habis.
7. **Dedicated Intent & Entity Parser**:
   - AI agent bertugas murni memetakan maksud pengguna (*intent recognition*) dan mengekstrak parameter non-sensitif (*slot extraction*).
8. **Multi-Turn Contextual Memory**:
   - Memanfaatkan riwayat obrolan aman untuk memahami konteks lanjutan (misalnya: *"kalau bulan depannya lagi?"* atau klarifikasi pertanyaan).

---

## 📁 Struktur Direktori

```plaintext
chatbot/
├── docker-compose.yml              # Konfigurasi container n8n orchestrator (mendukung Docker & Host Native)
├── .env.example                    # Template variabel lingkungan (kuota token, DB, Slack, Telegram & WA)
├── LAPORAN_ARSITEKTUR_DAN_IMPLEMENTASI.md # Laporan arsitektur teknis lengkap & formal
├── MANUAL_INTEGRASI.md             # Panduan implementasi, isolasi DB, Anti-PII & Tiket Slack/Telegram/WA
├── README.md                       # Ringkasan cepat & panduan operasional
├── check-tokens.sh                 # Script cepat cek sisa token dari terminal
├── install.sh                      # Script setup & validasi instalasi lingkungan
├── register-ai-capability.sh       # Script otomasi pendaftaran endpoint/manifest API baru
├── n8n/
│   └── universal-chatbot-workflow.json # Alur orkestrasi n8n (Anti-PII, Intent, Tiket & Slack/Telegram/WA Alert)
├── package-backend/                # Salin folder ini ke proyek backend Laravel Anda
│   ├── Commands/
│   │   └── ChatbotTokenCommand.php # Perintah Artisan php artisan chatbot:tokens
│   ├── Services/
│   │   ├── ChatbotNotificationService.php # Pengiriman alert tiket via Slack Webhook, Telegram Bot & WhatsApp
│   │   ├── ChatbotTokenService.php        # Layanan tracking, kuota & live count token
│   │   └── PiiSanitizer.php               # Layanan sanitasi data pribadi sensitif (KTP, HP, dll.)
│   ├── config/
│   │   ├── chatbot.php             # Konfigurasi isolasi database, notifikasi tiket & batas kuota token
│   │   └── ai_registry.php         # Tempat mendaftarkan manifest intent/kapabilitas API
│   ├── Controllers/
│   │   ├── AiCapabilityController.php       # Controller manifest schema kapabilitas
│   │   ├── ConversationMemoryController.php # Controller penyimpanan & pembacaan memori
│   │   ├── TicketReportController.php       # Controller tiket gangguan customer & dispatch alert
│   │   └── TokenUsageController.php         # Controller terpisah untuk cek sisa token
│   ├── Middleware/
│   │   └── VerifyInternalApiKey.php         # Validasi token X-Internal-API-Key
│   ├── migrations/
│   │   ├── 2025_01_01_000001_create_chatbot_tables.php       # Migrasi pesan & token
│   │   └── 2025_01_01_000002_create_chatbot_tickets_table.php # Migrasi tiket terisolasi
│   ├── routes/
│   │   └── chatbot.php             # Route terisolasi (cukup 1 baris include di routes/api.php)
│   └── bin/
│       └── check-tokens.sh         # Script bash mandiri pengecekan token
└── package-frontend/               # Widget chat & dashboard token terpisah
    ├── chat-widget.js              # Script widget publik (Anti-PII, sapaan personal, issue drawer & tiket)
    ├── chat-widget.css             # Style widget publik (responsif, drawer, kartu tiket & animasi)
    ├── index.html                  # Halaman demo integrasi chat
    └── token-dashboard.html        # Dashboard pemantauan token (khusus admin, terpisah dari publik)
```

---

## 🚀 Panduan Memulai Cepat

### 1. Setup & Jalankan Orkestrator n8n

1. Salin `.env.example` ke `.env`:
   ```bash
   cp .env.example .env
   ```
2. Isi `GEMINI_API_KEY` (dapatkan gratis di [Google AI Studio](https://aistudio.google.com/)).
3. Tentukan `BACKEND_BASE_URL` (contoh: `http://host.docker.internal:8000` atau port 8088 jika Laravel berjalan native di host).
4. Jalankan container n8n:
   ```bash
   docker compose up -d
   ```
5. Import & aktifkan alur kerja:
   ```bash
   docker exec universal_chatbot_n8n n8n import:workflow --input=/backup/n8n/universal-chatbot-workflow.json
   docker exec universal_chatbot_n8n n8n publish:workflow --id=universal-chatbot
   docker restart universal_chatbot_n8n
   ```

---

### 2. Integrasi Backend Laravel (Bebas Gangguan)

1. Salin file dari `package-backend/` ke project Laravel Anda:
   - `Commands/*` ➔ `app/Console/Commands/`
   - `Services/*` ➔ `app/Services/`
   - `config/*` ➔ `config/`
   - `Controllers/*` ➔ `app/Http/Controllers/Api/`
   - `Middleware/*` ➔ `app/Http/Middleware/`
   - `migrations/*` ➔ `database/migrations/`
   - `routes/chatbot.php` ➔ `routes/chatbot.php`
2. **Isolasi Database (Direkomendasikan)**:
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

### 3. Setup Notifikasi Tiket (Slack Channel Tim Internal - Direkomendasikan)

Pelaporan tiket kendala dirancang optimal untuk **Slack Channel** karena tata letaknya jauh lebih tertata rapi (**Slack Block Kit**), dilengkapi indikator warna tingkat urgensi, informasi field dua kolom, dan mempermudah tim teknis berdiskusi langsung di dalam **thread** pesan tanpa mengotori ruang obrolan utama.

#### A. Slack Incoming Webhook (Paling Rapi & Tertata - Rekomendasi):
1. Buka [Slack API Apps](https://api.slack.com/apps) dan pilih/buat aplikasi baru di Workspace Anda.
2. Aktifkan fitur **Incoming Webhooks** dan klik tombol **Add New Webhook to Workspace**.
3. Pilih channel tim yang ingin menerima laporan tiket (contoh: `#customer-tickets` atau `#ops-alerts`).
4. Salin URL Webhook dan tambahkan ke file `.env`:
   ```env
   SLACK_NOTIFICATIONS_ENABLED=true
   SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR_WORKSPACE_ID/YOUR_BOT_ID/YOUR_WEBHOOK_TOKEN
   SLACK_CHANNEL=#customer-tickets
   ```

#### B. Telegram Bot (Saluran Alternatif / Opsional):
Jika Anda masih memerlukan notifikasi cadangan ke Telegram:
```env
TELEGRAM_NOTIFICATIONS_ENABLED=false
TELEGRAM_BOT_TOKEN=123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ
TELEGRAM_CHAT_ID=-1001234567890
```

#### C. WhatsApp Gateway (Opsional):
Jika Anda menggunakan gateway WhatsApp (seperti Wablas, Fonnte, Twilio, atau custom webhook):
```env
WHATSAPP_NOTIFICATIONS_ENABLED=false
WHATSAPP_API_URL=https://api.fonnte.com/send
WHATSAPP_API_KEY=your_fonnte_token_here
WHATSAPP_TARGET_NUMBER=08123456789
```

---

### 4. Integrasi Jira Service Management (Proyek SUP - Atlassian)

Setiap tiket keluhan pelanggan yang dilaporkan lewat chatbot otomatis diterbitkan sebagai issue resmi di **Jira Service Management**:
- **Jira Instance:** `https://imtekservice.atlassian.net`
- **Project Key:** `SUP` ([Buka Getting Started di Jira](https://imtekservice.atlassian.net/jira/servicedesk/projects/SUP/getting-started))

#### Langkah Konfigurasi:
1. Buat **Atlassian API Token** (Gratis):
   - Kunjungi: [https://id.atlassian.com/manage-profile/security/api-tokens](https://id.atlassian.com/manage-profile/security/api-tokens)
   - Klik **Create API token**, beri nama label (misal: `Universal Chatbot`), lalu salin token tersebut.
2. Tambahkan ke file `.env`:
   ```env
   JIRA_ENABLED=true
   JIRA_HOST=imtekservice.atlassian.net
   JIRA_PROJECT_KEY=SUP
   JIRA_USER_EMAIL=email_atlassian_anda@domain.com
   JIRA_API_TOKEN=token_api_atlassian_anda
   JIRA_ISSUE_TYPE=Service Request
   ```
3. Uji koneksi dan hak akses:
   ```bash
   # Opsi 1: Menggunakan Bash script mandiri
   ./check-jira.sh

   # Opsi 2: Membuat satu tiket uji coba langsung di Jira
   ./check-jira.sh --create-issue

   # Opsi 3: Menggunakan perintah Artisan (di project Laravel)
   php artisan chatbot:jira --test
   ```
4. Di sisi pelanggan:
   - Respon chat dan kartu tiket (*Ticket Card*) otomatis menampilkan tautan langsung: `🔗 Jira: [SUP-XX](https://imtekservice.atlassian.net/browse/SUP-XX)` sehingga pelanggan dan agen support dapat melacak progres tiket secara transparan.


Pilih metode yang paling sesuai:

- **Opsi A: Via Terminal (Bash)**
  ```bash
  ./check-tokens.sh
  ```
- **Opsi B: Via Artisan Command**
  ```bash
  php artisan chatbot:tokens --history
  ```
- **Opsi C: Via Web Dashboard (Admin UI)**  
  Buka file `package-frontend/token-dashboard.html` di browser Anda untuk melihat gauge sisa kuota, grafik riwayat pemakaian, dan live token counter.

---

### 5. Integrasi Frontend Widget

Sematkan script pada layout web Anda (`app.blade.php` atau `index.html`):

```html
<script 
  src="/path-to/chat-widget.js" 
  data-webhook="http://localhost:5678/webhook/chat-entry" 
  data-user-id="USER-123"
  data-user-name="Budi Pratama"
  data-bot-name="Asisten Virtual">
</script>
```

#### Atribut Tag Script yang Didukung:
- `data-webhook`: Endpoint webhook n8n (contoh: `http://localhost:5678/webhook/chat-entry`).
- `data-user-id`: ID unik pengguna / pelanggan di aplikasi Anda.
- `data-user-name`: Nama pengguna untuk sapaan personal otomatis (*greeting*).
- `data-bot-name`: Nama identitas bot (standar: *Asisten Virtual*).
- `data-greeting`: *(Opsional)* Teks salam pembuka kustom.

---

## 🔌 Referensi REST API Backend

Seluruh endpoint terproteksi oleh header `X-Internal-API-Key: {INTERNAL_API_KEY}`:

| Metode | Endpoint | Deskripsi Fungsi |
| :--- | :--- | :--- |
| `GET` | `/api/v1/ai/manifest` | Mengambil skema JSON kapabilitas dan intent yang didukung. |
| `GET` | `/api/v1/conversations/{id}/messages` | Mengambil riwayat pesan tersanitasi dari suatu percakapan. |
| `POST` | `/api/v1/conversations/{id}/messages` | Menyimpan pesan pengguna atau balasan bot ke database memori. |
| `POST` | `/api/v1/tickets` | Menerbitkan tiket kendala baru, membuat issue Jira (SUP), dan memicu alert Slack/Telegram/WA. |
| `GET` | `/api/v1/tickets` | Menampilkan daftar tiket (filter: `status`, `user_id`, `category`). |
| `GET` | `/api/v1/tickets/{ticketNumber}` | Mengambil detail lengkap tiket gangguan tertentu (termasuk `jira_key` & `jira_url`). |
| `PATCH` | `/api/v1/tickets/{ticketNumber}/status` | Memperbarui status penanganan tiket (`open`, `investigating`, `resolved`). |
| `POST/GET` | `/api/v1/tickets/{ticketNumber}/jira-sync` | Melakukan sinkronisasi status tiket dari Jira Cloud ke database lokal. |
| `POST/GET` | `/api/v1/jira/test-connection` | Menguji koneksi kredensial ke Jira Cloud instance (`imtekservice.atlassian.net / SUP`). |
| `GET` | `/api/v1/ai/tokens/summary` | Mendapatkan ringkasan sisa kuota dan pemakaian token. |
| `GET` | `/api/v1/ai/tokens/history` | Mengambil riwayat pemakaian token per sesi. |
| `POST` | `/api/v1/ai/tokens/calculate` | Menghitung perkiraan token dari sebuah teks secara langsung. |

---

## 💡 Menambahkan Data / API Baru untuk Chatbot

Gunakan script otomatis untuk mendaftarkan endpoint/intent baru:
```bash
./register-ai-capability.sh --url="http://localhost:8088/api/v1/products"
```
Script akan membedah skema API Anda dan otomatis menambahkannya ke `config/ai_registry.php` tanpa perlu me-restart kontainer n8n.

---

## 📄 Lisensi & Kontribusi

Sistem ini dikembangkan untuk kebutuhan internal enterprise dan implementasi layanan publik terisolasi. Seluruh hak cipta dan kepemilikan kode dilindungi di bawah lisensi internal organisasi.
