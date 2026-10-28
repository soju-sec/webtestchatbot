<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Isolasi Database Chatbot (Database Isolation)
    |--------------------------------------------------------------------------
    | Untuk menjamin 100% TIDAK MENGGANGGU database aplikasi production yang ada:
    | - Isi 'DB_CHATBOT_CONNECTION' dengan nama koneksi khusus (misal: 'sqlite',
    |   'chatbot_sqlite', atau nama koneksi database MySQL terpisah).
    | - Jika diset null / kosong, chatbot akan menggunakan koneksi default aplikasi,
    |   namun tetap menggunakan nama tabel berawalan 'chatbot_' agar tidak bentrok.
    |
    | Rekomendasi Production:
    | Gunakan koneksi 'chatbot_sqlite' (file sqlite mandiri) atau database terpisah
    | agar beban query riwayat chat tidak membebani database utama transaksi bisnis.
    */
    'database_connection' => env('DB_CHATBOT_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Penamaan Tabel Mandiri (Isolated Table Names)
    |--------------------------------------------------------------------------
    | Menghindari benturan (collision) dengan nama tabel yang sudah ada di production.
    */
    'tables' => [
        'messages' => env('CHATBOT_TABLE_MESSAGES', 'chatbot_conversation_messages'),
        'tokens'   => env('CHATBOT_TABLE_TOKENS', 'chatbot_token_usages'),
        'tickets'  => env('CHATBOT_TABLE_TICKETS', 'chatbot_tickets'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifikasi Tiket Gangguan Pelanggan (Slack, Telegram & WhatsApp)
    |--------------------------------------------------------------------------
    | Otomatis mengirimkan alert langsung ke tim internal saat ada pelanggan
    | yang melaporkan fitur tidak dapat diakses, bug sistem, atau keluhan.
    |
    | Rekomendasi Utama: Gunakan Slack untuk pelaporan yang tertata rapi
    | dengan format Block Kit dan thread diskusi antar-anggota tim internal.
    */
    'notifications' => [
        'slack' => [
            'enabled'     => (bool) env('SLACK_NOTIFICATIONS_ENABLED', true),
            'webhook_url' => env('SLACK_WEBHOOK_URL', ''),
            'channel'     => env('SLACK_CHANNEL', '#customer-tickets'),
        ],
        'telegram' => [
            'enabled'   => (bool) env('TELEGRAM_NOTIFICATIONS_ENABLED', false),
            'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
            'chat_id'   => env('TELEGRAM_CHAT_ID', ''),
        ],
        'whatsapp' => [
            'enabled'       => (bool) env('WHATSAPP_NOTIFICATIONS_ENABLED', false),
            'api_url'       => env('WHATSAPP_API_URL', ''),
            'api_key'       => env('WHATSAPP_API_KEY', ''),
            'target_number' => env('WHATSAPP_TARGET_NUMBER', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Chatbot Khusus Eksekutif (Executive AI Assistant)
    |--------------------------------------------------------------------------
    | Saluran percakapan interaktif khusus jajaran Direksi, C-Level & Manajer.
    | Menggunakan alur orkestrasi & engine AI yang SAMA PERSIS dengan Web Chatbot,
    | namun disesuaikan secara khusus untuk kebutuhan eksekutif:
    |
    | 1. Persona Eksekutif: Jawaban lugas, padat, data-driven (Executive Summary).
    | 2. Formatting: Otomatis berformat WhatsApp Markdown (*bold*, _italic_, bullet).
    | 3. Whitelist Nomor: Filter keamanan ketat agar data strategis hanya dapat
    |    diakses oleh nomor WhatsApp jajaran pimpinan yang telah terdaftar.
    | 4. Prioritas Insiden: Laporan kendala dari eksekutif otomatis berstatus VIP / HIGH.
    */
    'executive_whatsapp' => [
        'enabled'           => (bool) env('WHATSAPP_EXECUTIVE_ENABLED', true),
        'provider'          => env('WHATSAPP_PROVIDER', 'fonnte'), // fonnte, wablas, meta_cloud, generic
        'api_url'           => env('WHATSAPP_API_URL', 'https://api.fonnte.com/send'),
        'api_key'           => env('WHATSAPP_API_KEY', ''),
        'verify_token'      => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN', ''),
        
        // Membatasi hanya nomor yang terdaftar yang boleh berinteraksi dengan AI
        'require_whitelist' => (bool) env('WHATSAPP_EXECUTIVE_REQUIRE_WHITELIST', true),
        
        // Daftar nomor WhatsApp eksekutif (format dipisahkan koma atau array)
        // Contoh: '08123456789,08198765432,628111222333'
        'whitelist_numbers' => env('WHATSAPP_EXECUTIVE_WHITELIST', ''),

        // Pemetaan nomor ke nama & jabatan eksekutif (opsional)
        // Format: '08123456789:Budi (CEO),08198765432:Siti (CFO)'
        'executive_directory' => env('WHATSAPP_EXECUTIVE_DIRECTORY', ''),
        
        // Pesan penolakan jika nomor tidak terdaftar dalam whitelist
        'unauthorized_message' => "🔒 *Akses Terbatas: Executive AI Assistant*\n\nNomor WhatsApp Anda belum terdaftar dalam direktori eksekutif resmi perusahaan. Silakan hubungi Administrator IT untuk mendaftarkan akses Anda.",
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrasi Jira Service Management / Jira Service Desk (Atlassian)
    |--------------------------------------------------------------------------
    | Setiap tiket kendala pelanggan yang dibuat oleh chatbot akan otomatis
    | diteruskan ke Jira Service Desk (Project: SUP) di instance Atlassian Anda:
    | https://imtekservice.atlassian.net/jira/servicedesk/projects/SUP
    |
    | Kredensial yang dibutuhkan:
    | - JIRA_USER_EMAIL : Email akun Atlassian Anda
    | - JIRA_API_TOKEN  : API Token dibuat dari https://id.atlassian.com/manage-profile/security/api-tokens
    */
    'jira' => [
        'enabled'     => (bool) env('JIRA_ENABLED', true),
        'host'        => env('JIRA_HOST', 'imtekservice.atlassian.net'),
        'project_key' => env('JIRA_PROJECT_KEY', 'SUP'),
        'user_email'  => env('JIRA_USER_EMAIL', ''),
        'api_token'   => env('JIRA_API_TOKEN', ''),
        'issue_type'  => env('JIRA_ISSUE_TYPE', 'Service Request'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fitur Tracking & Sisa Token Gemini (Terpisah / Isolated)
    |--------------------------------------------------------------------------
    | Kuota bulanan default untuk estimasi sisa token.
    | Gemini 2.5 Flash / 1.5 Flash Free Tier menyediakan hingga ~1.000.000 TPM
    | dan 1.500 requests per hari per key.
    | Anda dapat menentukan batas kuota bulanan untuk memantau sisa token.
    */
    'token_quota' => [
        // Batas kuota token bulanan per key (misal: 1.000.000 token). Set 0 untuk unlimited.
        'monthly_limit' => (int) env('GEMINI_MONTHLY_TOKEN_QUOTA', 1000000),
        
        // Persentase batas peringatan (warning threshold) sebelum kuota habis
        'warning_threshold_percent' => (int) env('GEMINI_TOKEN_WARNING_PERCENT', 85),
        
        // Nama model default
        'default_model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gemini API Key Pool & Rotasi Otomatis (Multi-Key Anti-Kehabisan Token)
    |--------------------------------------------------------------------------
    | Masukkan 1 atau lebih API Key (dipisahkan koma atau spasi).
    | Setiap key yang ditambahkan akan melipatgandakan batas kuota:
    | - 1 Key = 15 RPM, 1.500 requests/hari, 1.000.000 token
    | - 3 Key = 45 RPM, 4.500 requests/hari, 3.000.000 token
    | Sistem akan merotasi key secara otomatis (load balancing) dan melakukan
    | auto-failover ke key cadangan jika salah satu key terkena limit (429).
    */
    'gemini_api_keys' => env('GEMINI_API_KEYS', env('GEMINI_API_KEY', '')),

    /*
    |--------------------------------------------------------------------------
    | Kunci Keamanan Internal (Internal API Key)
    |--------------------------------------------------------------------------
    */
    'internal_api_key' => env('INTERNAL_API_KEY', 'your_secure_internal_api_key_here'),

    /*
    |--------------------------------------------------------------------------
    | Integrasi Orkestrator n8n
    |--------------------------------------------------------------------------
    */
    'n8n' => [
        'webhook_url'     => env('N8N_WEBHOOK_URL', 'http://127.0.0.1:5678/webhook/chat-entry'),
        'timeout_seconds' => (int) env('CHATBOT_TIMEOUT_SECONDS', 30),
    ],
];
