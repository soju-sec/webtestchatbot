# 📑 LAPORAN RESMI: ARSITEKTUR & ALUR INTEGRASI ANTAR-SISTEM
## Asisten Digital Siskeudes TNT & Ekosistem Helpdesk Konsultan
**Pengembang**: PT. IMTEK MEDIA DATA (iMD)  
**Sistem Terkait**: Siskeudes-Link & CMS Bank Desa, CMS Konsultan, n8n Workflow Orchestrator, Jira Service Desk, WhatsApp Gateway, Slack, & Telegram  
**Versi Dokumen**: 2.5 (Revisi Mutakhir)  
**Tanggal**: 28 Oktober 2026  

---

## 1. RINGKASAN EKSEKUTIF (EXECUTIVE SUMMARY)

Ekosistem **Asisten Digital Siskeudes TNT** dirancang untuk memberikan layanan pendampingan cerdas, transparan, dan terstandarisasi bagi aparatur pemerintah desa (Kaur Keuangan, Operator Desa, Sekdes, Kepala Desa) serta konsultan pendamping daerah dalam penatausahaan keuangan desa non-tunai (CMS Bank Desa).

Sistem ini menghubungkan **5 pilar utama**:
1. **Frontend Transaksi Desa (Siskeudes TNT / Siskeudes-Link Web)**: Antarmuka aplikasi desa tempat widget chatbot floating disematkan, lengkap dengan form laporan 8 field wajib dan tombol pengecekan SPP read-only.
2. **Pusat Manajemen Pengetahuan (CMS Konsultan)**: Portal admin bagi konsultan pendamping untuk mengelola template SOP, panduan solusi, status publikasi kasus, dan pemantauan eskalasi teknis.
3. **Orkestrator & Parser AI (n8n & Google Gemini 2.5 Flash)**: Penghubung webhook cerdas yang mengelola rotasi multi-API key, sanitasi data pribadi (Anti-PII), inferensi memori percakapan multi-turn, dan eksekusi intent programatik.
4. **Sistem Tiket & Eskalasi Engineering (Atlassian Jira Service Management)**: Pelacak tiket gangguan teknis berstandar industri dengan integrasi sinkronisasi 2 arah (Two-Way Synchronization).
5. **Kanal Notifikasi Terpadu (Zero-Spam WhatsApp, Slack, & Telegram)**: Notifikasi WhatsApp efisien (Skenario B) yang hanya mengirimkan 2 pesan utama (Tanda Terima + Magic Link dan Notifikasi Hasil Solusi saat status Jira Solved) guna menjaga keandalan kuota API WhatsApp.

---

## 2. PETA EKOSISTEM SISTEM (SYSTEM LANDSCAPE)

| No | Komponen Sistem | Lokasi / Port | Teknologi Utama | Peran & Tanggung Jawab |
|---|---|---|---|---|
| **1** | **Siskeudes-Link Web** | Host / Port `8000` | Laravel 10, PostgreSQL, Blade, Vanilla JS | Aplikasi penatausahaan pencairan SPP desa, host widget floating frontend (`chat-widget.js`), dan API status SPP read-only. |
| **2** | **CMS Konsultan** | Container / Port `8090` | Laravel 10, Inertia.js / Vue, SQLite / PostgreSQL | Portal manajemen konsultan iMD untuk membuat/mengedit template SOP kasus (`/templates`), metrik Jira, dan penyedia endpoint `/api/v1/ai/manifest`. |
| **3** | **n8n Orchestrator** | Container / Port `5678` | n8n Engine, Node.js, Webhook, Gemini REST | Orkestrator alur kerja AI, Anti-PII shield, penggabung memori percakapan, dan pengeksekusi fungsi bisnis. |
| **4** | **Jira Service Desk** | Cloud (`atlassian.net`) | Atlassian REST API v3, Webhook | Pengelolaan tiket insiden teknis tim pengembang (project `SUP`), pelacakan SLA, dan assignment teknisi. |
| **5** | **WhatsApp Gateway** | Eksternal / Cloud API | WhatsApp Business Cloud API / Gateway | Kanal penyampaian tanda terima pelaporan (#1) dan pemberitahuan solusi penanganan (#2) kepada nomor pelapor desa. |
| **6** | **Slack & Telegram** | Eksternal Webhook / Bot | Slack Incoming Webhook, Telegram Bot API | Notifikasi broadcast instan ke kanal tim internal engineering dan grup pengawas konsultan. |
| **7** | **Core Database** | Port `5432` (`bapenda-postgres`) | PostgreSQL 15 | Database persisten untuk tabel transaksi desa (`tbspp`, `tbmdesa`, `tbmpemda`) dan isolasi tabel chatbot (`chatbot_tickets`, `chatbot_memories`). |

---

## 3. DIAGRAM ARSITEKTUR INTEGRASI END-TO-END

```mermaid
flowchart TD
    subgraph DESA["🏛️ Wilayah Pemerintah Desa (Aparatur Desa)"]
        User["Bpk/Ibu Kaur Keuangan / Operator"]
        Widget["Chat Widget Floating (chat-widget.js)"]
        User -->|1. Akses Halaman Pencairan SPP| Widget
        User -->|2. Lapor Kendala / Tanya SPP| Widget
    end

    subgraph SISKEUDES["💻 Siskeudes TNT Web Server (Port 8000)"]
        SppController["TicketReportController.php"]
        SiskeudesDB[(PostgreSQL: tbspp, tbmdesa, tbmpemda)]
        Widget -->|Cek Status SPP (Read-Only)| SppController
        SppController -->|Query Transaksi Riil| SiskeudesDB
        Widget -->|Submit Laporan 8 Field| SppController
    end

    subgraph CMS["🏢 CMS Konsultan iMD (Port 8090)"]
        CmsUI["Web Portal Konsultan (/templates)"]
        KbController["KbTemplateController.php"]
        AiManifestController["AiCapabilityController.php"]
        KbDB[(chatbot_kb_templates)]
        CmsUI -->|Kelola Panduan & Solusi SOP| KbController
        KbController -->|CRUD Template Kasus| KbDB
        KbDB -->|Format Published SOP| AiManifestController
    end

    subgraph N8N_AI["🧠 n8n Orchestrator & AI Engine (Port 5678)"]
        N8nEntry["Webhook Entry Point (/api/webhook/chat-entry)"]
        PiiNode["Anti-PII Sanitizer & Normalizer"]
        ManifestNode["Fetch AI Manifest"]
        PromptNode["Build Gemini Prompt & Key Rotation"]
        Gemini["Google Gemini 2.5 Flash API"]
        N8nEntry --> PiiNode
        PiiNode --> ManifestNode
        AiManifestController -.->|GET /api/v1/ai/manifest| ManifestNode
        ManifestNode --> PromptNode
        PromptNode --> Gemini
        Gemini -->|Intent & Structured Response| N8nEntry
    end

    subgraph ESCALATION["🚀 Layanan Eskalasi & Notifikasi"]
        Jira["Atlassian Jira Service Desk (Project SUP)"]
        Slack["Slack Engineering Alerts"]
        Telegram["Telegram Pengawas Konsultan"]
        WhatsApp["WhatsApp Gateway (Zero-Spam)"]
    end

    subgraph TRACKING["🌐 Portal Pelacakan Mandiri"]
        MagicWeb["Halaman Pelacak Tiket Mandiri (/tickets/track/{no})"]
    end

    SppController -->|Dispatch Tiket & Webhook| N8nEntry
    SppController -->|Create Issue API| Jira
    SppController -->|Pesan #1 Tanda Terima + Magic Link| WhatsApp
    SppController -->|Alert Internal| Slack
    SppController -->|Alert Internal| Telegram
    WhatsApp -.->|Pelapor Buka Link Web| MagicWeb
    MagicWeb -->|Cek Progres Tanpa Kuota WA| SiskeudesDB

    Jira -->|Webhook Status Solved/Done| SppController
    SppController -->|Pesan #2 Solusi Penanganan Teknisi| WhatsApp
```

---

## 4. ALUR INTEGRASI RINCI (DETAILED INTEGRATION FLOWS)

### 4.1. Alur 1: Sinkronisasi Template Knowledge Base (CMS Konsultan -> AI)
Alur ini menjamin bahwa seluruh SOP penanganan masalah yang diatur oleh konsultan iMD otomatis menjadi pedoman jawaban AI bagi seluruh pengguna Siskeudes:

1. **Input Konsultan**: Konsultan membuka dashboard `http://localhost:8090/templates`, membuat atau mengubah kasus kendala (misal: *Alur SPP Online*, *Solusi Akun Merah*, *Koreksi ID Billing*), mengisi *trigger keywords*, *solution steps*, dan mengubah status menjadi `published`.
2. **Kompilasi Manifest**: Controller `AiCapabilityController.php` pada CMS Konsultan menyediakan endpoint publik terproteksi `GET /api/v1/ai/manifest`. Endpoint ini mengekstrak semua template berstatus `published`.
3. **Penarikan oleh n8n**: Ketika pengguna mengajukan pertanyaan di widget desa, node `Fetch AI Manifest` di n8n melakukan HTTP GET ke CMS Konsultan menggunakan header `X-Internal-API-Key`.
4. **Penyuntikan Instruksi Gemini**: Node `Build Gemini Prompt` merangkai data template kasus menjadi bagian dari *System Instruction* Gemini AI.
5. **Hasil Jawaban Terstandar**: Jika pesan pengguna cocok dengan kata kunci kasus, Gemini AI wajib menyusun balasan berbasis langkah resmi SOP yang telah ditetapkan konsultan.

---

### 4.2. Alur 2: Pelaporan Kendala Siskeudes & WhatsApp Zero-Spam (Skenario B)
Sistem menerapkan protokol pelaporan dengan **8 field wajib** yang terhubung langsung ke antrean Jira dan notifikasi WhatsApp:

```
[Pengguna di Desa]
       │
       ▼ (Mengisi Drawer Lapor Kendala: Form Data + Bukti Screenshot)
• No WA Pelapor         • Kabupaten (Auto-fill)   • Nama Desa (Auto-fill)
• No SPP                • No Kwitansi             • Kode CMS Siskeudes
• Kode CMS Bank Desa    • Keterangan / Kendala    • 📎 Lampiran Screenshot (File / Ctrl+V)
       │
       ▼ (POST /api/v1/tickets/public-submit atau Webhook n8n)
[Backend Siskeudes TNT & Orkestrator n8n]
       ├─── 1. Sanitasi PII & Validasi Format No WA (+62 / 08) & File Screenshot
       ├─── 2. Generate Nomor Tiket Baku (TCK-YYYYMMDD-XXXX) & Token Magic Link
       ├─── 3. Simpan ke Database Lokal (tabel chatbot_tickets) & Simpan Berkas Gambar
       ├─── 4. Buat Tiket di Jira Service Management (Project SUP, SLA High)
       │       └─── Otomatis Upload & Attach Screenshot ke Issue Jira (SUP-xxx)
       ├─── 5. Broadcast Alert ke Slack Tim Engineering & Telegram Konsultan (+ Bukti Lampiran)
       └─── 6. Routing Notifikasi WhatsApp Berdasarkan Kategori Laporan:
               ├── [A. Kendala Transaksi Siskeudes TNT]:
               │   └── Kirim PESAN WHATSAPP #1 (Tanda Terima + Magic Link) ke Pelapor Desa
               └── [B. Kendala Login / Permohonan Reset Akun]:
                   ├── Kirim LINK OTORISASI RESET ke WhatsApp ADMIN DPMD KABUPATEN
                   └── Kirim Tanda Terima ke Pelapor Desa (Edukasi alur verifikasi DPMD)
```

#### Struktur Pesan WhatsApp #1 (Kendala Transaksi TNT):
> *Yth. Bapak/Ibu Operator Desa Kuta, Pemkab Badung,*  
> *Laporan kendala transaksi Siskeudes TNT Anda telah kami terima:*  
> *• No. Tiket: `#TCK-20261028-4821`*  
> *• No. SPP: `00001/SPP/51.03.01.2001/2026`*  
> *• Bukti Screenshot: Terlampir di Jira SUP*  
> *• Status: SEDANG DIANTREKAN TIM TEKNIS*  
>  
> *Pantau perkembangan penanganan langsung melalui tautan pelacak mandiri:*  
> *🔗 https://siskeudes.badungkab.go.id/api/v1/tickets/track/TCK-20261028-4821?token=a8f9c2...*  
> *(Hemat kuota, pantau status tiket kapan saja tanpa menunggu balasan chat)*

#### Struktur Pesan WhatsApp (Khusus Kendala Login ke Admin DPMD):
> *🏛️ [PERMOHONAN RESET AKUN SISKEUDES - OTORISASI DPMD]*  
> *Yth. Admin DPMD Kab. Badung,*  
> *Terdapat permohonan pemulihan akses akun dari Desa Kuta (#TCK-20261028-4821) untuk akun: `kaur.keuangan@kuta.desa.id`.*  
> *Tautan otorisasi & reset resmi DPMD:*  
> *🔗 https://siskeudes.badungkab.go.id/api/v1/dpmd/auth-reset/TCK-20261028-4821?token=sec_a8...*  
> *(Sesuai SOP keamanan, link reset HANYA dikirimkan ke Admin DPMD dan tidak diberikan ke pemohon desa).*


---

### 4.3. Alur 3: Pelacakan Mandiri Berbasis Web (Web-First Follow-up)
Untuk mencegah nomor WhatsApp terkena *rate limit* atau pemblokiran meta akibat chat berulang kali:
- Pelapor tidak perlu mengirim pesan WhatsApp baru untuk menanyakan status.
- Pelapor cukup mengeklik tautan **Magic Tracking Link** yang membuka halaman web resmi pelacakan tiket mandiri.
- Atau, pelapor dapat mengetikkan nomor tiketnya (misal: `TCK-20261028-4821`) langsung pada widget chatbot di aplikasi Siskeudes.
- Sistem langsung menampilkan status terkini (*Open*, *Investigating*, *Resolved*) beserta catatan solusi teknisi.

---

### 4.4. Alur 4: Sinkronisasi 2 Arah Penyelesaian Tiket (Jira Webhook -> WhatsApp #2)
Ketika teknisi di Jira menyelesaikan kendala:

1. **Aksi Teknisi**: Teknisi mengubah status issue di Jira menjadi `Resolved` atau `Done` dan memasukkan catatan solusi (*Resolution Notes*).
2. **Pengiriman Webhook**: Jira mengirimkan payload JSON ke endpoint `POST /api/v1/jira/webhook`.
3. **Pencatatan Idempoten**: Backend mencocokkan nomor tiket, memperbarui status tiket di database lokal menjadi `resolved`, mengisi `resolution_notes`, dan memeriksa kolom `resolved_notified_at`.
4. **Kirim PESAN WHATSAPP #2 (Hasil Penanganan)**:
   - Jika `resolved_notified_at` masih kosong, sistem mengirimkan pesan WhatsApp ke nomor pelapor desa:
   > *Yth. Bapak/Ibu Operator Desa Kuta,*  
   > *Kendala transaksi pada Tiket `#TCK-20261028-4821` telah SELESAI DITANGANI oleh tim teknisi:*  
   >  
   > *🛠️ Catatan Solusi:*  
   > *"ID Billing pajak telah disinkronkan ulang dengan CMS Bank. Silakan lakukan proses pencairan ulang di menu Pencairan SPP (manual)."*  
   >  
   > *Rincian lengkap: https://siskeudes.badungkab.go.id/api/v1/tickets/track/...*
   - Sistem mencatat timestamp pada `resolved_notified_at` agar notifikasi tidak terkirim ganda.

---

### 4.5. Alur 5: Pengecekan Status Pencairan SPP (Read-Only API)
Alur ini beroperasi mandiri dan aman dipanggil ribuan kali tanpa membebani WhatsApp API:

1. **Permintaan Pengguna**: Pengguna mengklik chip **💸 Cek Pencairan SPP** atau mengetik kata kunci umum.
2. **Permintaan Input Nomor SPP**: Chatbot meminta nomor transaksi secara bersih:
   > *"Silakan ketikkan Nomor SPP, Kode CMS Siskeudes, atau Nomor Kwitansi yang ingin dicek status pencairannya (contoh: `00001/SPP/51.03.01.2001/2026`)."*
3. **Pencarian Database PostgreSQL**:
   - Controller memeriksa tabel riil `tbspp`, `tbmdesa`, dan `tbmpemda`.
   - Mengambil nominal belanja (bruto), nilai potongan (pajak), dan nilai terima (netto).
   - Menghitung status berdasarkan kolom `status_verifikasi`, `status_pembayaran`, dan `status_pencairan`.
4. **Penyajian Kartu Status**:
   - Menampilkan badge visual: `🟢 SUDAH CAIR / BERHASIL TRANSFER` atau `🟡 Disetujui Sekdes (Menunggu Pencairan di CMS Bank)`.
   - Menampilkan rincian alur dokumen: Siskeudes Online Final, Upload SPTJB/SPP2, Verifikasi Sekdes, dan Approval Kades.
   - Menyediakan tombol pintas `🚨 Ada Masalah pada SPP Ini? Laporkan` yang otomatis mengisi nomor SPP tersebut ke dalam form pelaporan kendala.

---

### 4.6. Alur 6: Arsitektur Hybrid / Multi-Tiered Request Handling
Untuk menjamin kecepatan interaksi maksimal dan efisiensi konsumsi token AI, arsitektur membagi pemrosesan pesan pengguna ke dalam **3 Tingkat (Tier)** yang saling melengkapi:

1. **Tier 1 - Edge SOP Cache (Frontend Local Template - 0 ms / Bebas Token)**:
   - Diterapkan pada pertanyaan SOP baku dan klik tombol *Quick Chips* (contoh: panduan verifikasi berkas, solusi akun merah, dan koreksi ID billing).
   - Ditangani seketika oleh `chat-widget.js` (ukuran file hanya ~71 KB dan ter-cache di browser).
   - **Keuntungan**: Latensi 0 milidetik, tidak membebani server, dan menghemat biaya kuota token Google Gemini (Zero-Token).
2. **Tier 2 - Direct Read-Only Database (Backend Laravel API - < 150 ms)**:
   - Diterapkan saat pengguna memasukkan parameter pencarian spesifik seperti Nomor SPP (`00001/SPP/...`), Nomor Kwitansi, atau Kode CMS.
   - Mengambil data transaksi riil langsung dari database PostgreSQL Siskeudes TNT secara aman tanpa melibatkan LLM.
3. **Tier 3 - Intelligent Orchestration Gateway (n8n & Google Gemini LLM - 1,5–3 dtk)**:
   - Diterapkan pada seluruh percakapan bebas (*free-text natural language*), konsultasi teknis yang kompleks, dan analisis sentimen/keluhan.
   - n8n bertindak sebagai orkestrator yang menjalankan sanitasi Anti-PII, memanggil model Gemini 2.5 Flash, mengenali maksud pengguna (*intent recognition*), dan mengeksekusi penerbitan tiket Jira secara otomatis.

---

## 5. SPESIFIKASI KONTRAK DATA (DATA CONTRACTS & PAYLOADS)

### 5.1. Payload Form Pelaporan Kendala (`POST /api/v1/tickets/report`)
```json
{
  "reporter_phone": "081234567890",
  "kabupaten": "Pemerintah Kabupaten Badung",
  "nama_desa": "Desa Kuta",
  "tahun_anggaran": "2026",
  "no_spp": "00001/SPP/51.03.01.2001/2026",
  "no_kwitansi": "KW-001/2026",
  "kode_cms_siskeudes": "CMS2026001001",
  "kode_cms_bank": "BNK-100001",
  "description": "Status SPP tertulis berhasil cair namun dana belum masuk ke mutasi kas desa",
  "urgency": "tinggi",
  "metadata": {
    "url": "http://localhost:8000/pencairansppmanual",
    "user_agent": "Mozilla/5.0..."
  }
}
```

### 5.2. Response Pengecekan SPP (`GET /api/v1/siskeudes/spp/status?no_spp=...`)
```json
{
  "status": "success",
  "query": {
    "no_spp": "00001/SPP/51.03.01.2001/2026",
    "kabupaten": "Pemerintah Kabupaten Badung",
    "nama_desa": "Desa Kuta",
    "tahun_anggaran": "2026"
  },
  "spp_data": {
    "no_spp": "00001/SPP/51.03.01.2001/2026",
    "no_kwitansi": "KW-1/2026",
    "kode_cms_siskeudes": "CMS2026001001",
    "kode_cms_bank": "BNK-100001",
    "tanggal_transaksi": "16-01-2026 10:00:00",
    "status_pembayaran": "BERHASIL_CAIR",
    "status_label": "Sudah Cair / Berhasil Ditransfer",
    "badge_color": "success",
    "nominal_bruto": 25000000,
    "nominal_bruto_format": "Rp 25.000.000",
    "potongan_pajak": 2750000,
    "potongan_pajak_format": "Rp 2.750.000",
    "nominal_netto": 22250000,
    "nominal_netto_format": "Rp 22.250.000",
    "status_pajak": "ID Billing Terbayar Otomatis melalui CMS Bank Desa",
    "alur_verifikasi": {
      "siskeudes_online": "Final (Disetujui)",
      "upload_sptjb": "Lengkap",
      "upload_spp2": "Lengkap",
      "verifikasi_sekdes": "Selesai (Disetujui)",
      "approval_kades": "Selesai (Cair)"
    }
  }
}
```

---

## 6. ASPEK KEAMANAN, PRIVASI, DAN ISOLASI DATA

1. **Perlindungan Data Pribadi (Anti-PII Guard)**:
   - Modul `PiiSanitizer` aktif di dua lapis (Client Widget & n8n Orchestrator).
   - Data sensitif seperti NIK (16 digit), kata sandi, PIN, dan nomor rekening perbankan pribadi secara otomatis disamarkan menjadi token netral (misal: `[NOMOR_KTP_DISAMARKAN]`) sebelum teks diproses oleh model LLM.
2. **Isolasi Database Non-Intrusif**:
   - Seluruh tabel chatbot (`chatbot_tickets`, `chatbot_memories`, `chatbot_token_usage`) menggunakan prefix terisolasi dan skema mandiri.
   - Operasi pengecekan SPP berstatus **read-only**, sehingga tidak akan mengubah atau memanipulasi data saldo kas desa dan bukti transfer asli di tabel `tbspp`.
3. **Autentikasi Internal Antar-Sistem**:
   - Pertukaran data antara n8n dan backend dilindungi oleh middleware `VerifyInternalApiKey` menggunakan header `X-Internal-API-Key`.
   - Webhook Jira divalidasi menggunakan token rahasia webhook (`JIRA_WEBHOOK_SECRET`).

---

## 7. PANDUAN VERIFIKASI & MONITORING OPERASIONAL

| Langkah Pengujian | Target URL / Perintah | Hasil yang Diharapkan |
|---|---|---|
| **1. Pengujian CMS Konsultan** | `http://localhost:8090/templates` | Menampilkan tabel 10 template SOP kasus (SOP Verifikasi, Akun Merah, Koreksi Billing, dsb.) dengan status *published*. |
| **2. Verifikasi Manifest AI** | `curl -H "X-Internal-API-Key: ..." http://localhost:8090/api/v1/ai/manifest` | Menghasilkan JSON berisi daftar action sistem dan object `support_templates` lengkap. |
| **3. Verifikasi Widget Siskeudes** | `http://localhost:8000/pencairansppmanual` | Widget floating tampil di pojok kanan bawah dengan logo resmi robot Siskeudes TNT, menyapa Bpk/Ibu Kaur Keuangan Desa Kuta. |
| **4. Pengecekan SPP Riil** | Ketik `00001/SPP/51.03.01.2001/2026` di widget | Menampilkan kartu rincian SPP Desa Kuta berstatus `🟢 SUDAH CAIR` Rp 22.250.000 netto. |
| **5. Simulasi WhatsApp & Jira** | Klik `🚨 Form Lapor Kendala Siskeudes` | Tiket terbit (`TCK-...`), pesan WhatsApp #1 terkirim dengan Magic Link, dan issue otomatis terdaftar di backlog Jira SUP. |

---

**Disusun dan Diverifikasi Oleh:**  
*Tim Perekayasa Perangkat Lunak & AI Antigravity*  
**PT. IMTEK MEDIA DATA (iMD)**  
*Mendukung Akuntabilitas & Transparansi Keuangan Desa Indonesia*
