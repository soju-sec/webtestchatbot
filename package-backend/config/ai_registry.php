<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Daftarkan Intent / Capabilities yang Boleh Diproses AI di Proyek Ini
    |--------------------------------------------------------------------------
    | AI Agent berfungsi murni sebagai INTENT PARSER dengan dukungan MEMORI.
    | AI TIDAK memiliki akses langsung ke database atau data sensitif pribadi.
    |
    | Manifest ini dibaca secara otomatis via endpoint /api/v1/ai/manifest.
    | AI menggunakan definisi aksi dan parameter ini untuk memetakan maksud (intent)
    | pengguna serta mengekstrak parameter yang dibutuhkan.
    |
    | PERINGATAN PRIVASI:
    | Jangan pernah menaruh data pribadi sensitif (KTP, No HP, password) di sini.
    |
    | Parameter struktur:
    | - 'action'       : Nama unik intent/kapabilitas (misal: get_monthly_revenue)
    | - 'method'       : HTTP Method (GET / POST)
    | - 'path'         : Endpoint path (misal: /api/v1/store/revenue/monthly)
    | - 'description'  : Penjelasan maksud intent dan kapan digunakan
    | - 'params'       : Parameter/slot yang didukung untuk diekstrak (misal: month, year)
    | - 'current_data' : (Opsional) Data snapshot agregat publik untuk fulfillment
    */
    'endpoints' => [
        [
            'action' => 'get_store_metrics',
            'method' => 'GET',
            'path' => '/api/v1/store/metrics',
            'description' => 'Melihat ringkasan metrik performa toko (total earnings, total orders, customers, balance)',
            'params' => [],
            'current_data' => [
                'total_earnings' => '$559k (+16.24%)',
                'orders' => '36,894 (-3.57%)',
                'customers' => '183M (+29.08%)',
                'my_balance' => '$165k',
                'conversion_ratio' => '18%',
                'recent_summary' => '7,585 orders ($22k earnings, 367 refunds)'
            ]
        ],
        [
            'action' => 'get_monthly_revenue',
            'method' => 'GET',
            'path' => '/api/v1/store/revenue/monthly',
            'description' => 'Melihat rincian performa revenue, pendapatan/earnings, jumlah pesanan (orders), dan refund bulanan (Januari s/d Desember)',
            'params' => ['month', 'year'],
            'current_data' => [
                'Jan' => ['orders' => 34, 'earnings' => '$89.25k', 'refunds' => 8],
                'Feb' => ['orders' => 65, 'earnings' => '$98.58k', 'refunds' => 12],
                'Mar' => ['orders' => 46, 'earnings' => '$68.74k', 'refunds' => 7],
                'Apr' => ['orders' => 68, 'earnings' => '$108.87k', 'refunds' => 17],
                'May' => ['orders' => 49, 'earnings' => '$77.54k', 'refunds' => 21],
                'Jun' => ['orders' => 61, 'earnings' => '$84.03k', 'refunds' => 11],
                'Jul' => ['orders' => 42, 'earnings' => '$51.24k', 'refunds' => 5],
                'Aug' => ['orders' => 44, 'earnings' => '$28.57k', 'refunds' => 9],
                'Sep' => ['orders' => 78, 'earnings' => '$92.57k', 'refunds' => 7],
                'Oct' => ['orders' => 52, 'earnings' => '$42.36k', 'refunds' => 29],
                'Nov' => ['orders' => 63, 'earnings' => '$88.51k', 'refunds' => 12],
                'Dec' => ['orders' => 67, 'earnings' => '$36.57k', 'refunds' => 35],
            ]
        ],
        [
            'action' => 'get_user_profile',
            'method' => 'GET',
            'path' => '/api/v1/user/{user_id}/profile',
            'description' => 'Melihat data profil dan status akun pengguna saat ini',
            'params' => []
        ],
        [
            'action' => 'get_transactions',
            'method' => 'GET',
            'path' => '/api/v1/user/{user_id}/transactions',
            'description' => 'Melihat riwayat transaksi penjualan dan order pengguna',
            'params' => ['status', 'limit', 'date_from', 'date_to']
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SOP & Templating Pertanyaan Non-Teknis (Customer Support Knowledge)
    |--------------------------------------------------------------------------
    | Panduan operasional standar dan panduan jawaban ramah pengguna non-teknis:
    | 1. Kendala Login / Tidak bisa masuk akun
    | 2. Selisih Data / Inkonsistensi (Format Verifikasi Data Lanjut)
    | 3. File Laporan Tidak Bisa Diexport
    | 4. Kendala Status Pembayaran Pending
    | 5. Halaman Blank / Loading Stuck / Error Sistem
    */
    'support_templates' => [
        'login_troubleshooting' => [
            'topic' => 'Kendala Login / Tidak Bisa Masuk Akun',
            'keywords' => ['login', 'masuk', 'password', 'kata sandi', 'terkunci', 'lupa password', 'akun terkunci'],
            'steps' => [
                'Periksa kembali penulisan Email / Username Anda (pastikan huruf besar/kecil benar dan Caps Lock tidak aktif).',
                'Gunakan fitur <b>Lupa Password?</b> di bawah form login untuk menerima tautan reset kata sandi melalui email terdaftar.',
                'Bersihkan <i>Cache & Cookies</i> browser Anda, atau coba buka melalui jendela <b>Incognito / Private Window</b>.',
                'Jika salah memasukkan kata sandi lebih dari 5 kali berturut-turut, sistem akan mengunci akun sementara demi perlindungan keamanan.'
            ],
            'escalation' => 'Jika akun masih terkunci atau tidak menerima email reset, ketik <b>"Buatkan tiket kendala login"</b> agar admin IT kami memeriksa status akun Anda.'
        ],
        'data_discrepancy' => [
            'topic' => 'Data Tidak Sesuai / Selisih Angka / Inkonsistensi Data',
            'keywords' => ['tidak sesuai', 'salah data', 'selisih', 'tidak cocok', 'data beda', 'salah angka', 'angka tidak pas'],
            'intro' => 'Baik Kak, kami siap bantu buatkan tiket investigasi selisih data ke tim teknis & verifikasi. Agar pengecekan dapat segera diproses, mohon lengkapi format verifikasi berikut ya Kak:',
            'format_template' => "📋 <b>Format Verifikasi Data:</b><br>" .
                "• <b>Menu / Fitur:</b> [misal: Laporan Penjualan / Riwayat Transaksi]<br>" .
                "• <b>ID Transaksi / No Referensi:</b> [misal: TRX-202610-001]<br>" .
                "• <b>Data yang Seharusnya:</b> [misal: Total Rp 500.000]<br>" .
                "• <b>Data yang Tampil Saat Ini:</b> [misal: Total Rp 350.000]<br>" .
                "• <b>Waktu Kejadian:</b> [Tanggal & Perkiraan Jam]",
            'auto_ticket_rule' => 'Setelah pengguna mengirimkan rincian di atas (atau jika sudah menyebutkan nomor transaksi & angka selisih), sistem secara OTOMATIS menerbitkan tiket resmi ke Jira Service Desk (Project SUP) & database internal.'
        ],
        'export_report_failure' => [
            'topic' => 'File Laporan Tidak Bisa Diexport / Gagal Unduh',
            'keywords' => ['export', 'ekspor', 'download laporan', 'unduh laporan', 'gagal export', 'export error', 'download gagal'],
            'steps' => [
                '<b>Perkecil Rentang Tanggal Filter:</b> Jika memilih rentang waktu terlalu panjang (misal 1 tahun atau ribuan baris data), proses generate memakan memori tinggi. Coba unduh per bulan atau per minggu.',
                '<b>Gunakan Format CSV:</b> Jika file Excel (.xlsx) atau PDF berat atau gagal, coba unduh dalam format <b>CSV</b> yang jauh lebih ringan dan cepat.',
                '<b>Periksa Pop-up Blocker:</b> Pastikan browser Anda tidak memblokir unduhan otomatis (cek ikon pop-up di pojok kanan address bar browser).',
                '<b>Hindari Klik Berulang:</b> Saat proses generate sedang berjalan, tunggu beberapa saat hingga file selesai dibuat oleh server.'
            ],
            'escalation' => 'Jika tombol tetap tidak merespon atau muncul pesan error 500/timeout, ketik <b>"Buatkan tiket error export"</b> agar tim developer memeriksa modul export data.'
        ],
        'payment_pending' => [
            'topic' => 'Status Pembayaran Belum Berubah / Menunggu Verifikasi',
            'keywords' => ['pembayaran', 'bayar', 'belum masuk', 'pending', 'menunggu pembayaran', 'sudah transfer'],
            'steps' => [
                'Pembayaran via Virtual Account & QRIS umumnya terverifikasi otomatis dalam 1 - 5 menit setelah transfer berhasil.',
                'Periksa mutasi rekening / e-wallet Anda untuk memastikan saldo telah terpotong dan nomor referensi tercatat.',
                'Jika transfer dilakukan saat jam cut-off bank atau sudah lebih dari 15 menit status belum berubah, kami siap bantu cek manual.'
            ],
            'escalation' => 'Ketik nomor invoice / ID transaksi Anda agar kami buatkan tiket konfirmasi manual ke tim finance.'
        ],
        'page_error_loading' => [
            'topic' => 'Halaman Blank / Loading Lama / Error Sistem',
            'keywords' => ['loading lama', 'blank', 'putih', 'stuck', 'error 500', 'tidak bisa dibuka', 'muter terus'],
            'steps' => [
                'Lakukan <b>Hard Refresh</b> dengan menekan tombol <code>Ctrl + F5</code> (Windows) atau <code>Cmd + Shift + R</code> (Mac).',
                'Pastikan koneksi internet stabil dan tidak mengalami gangguan DNS.',
                'Coba gunakan browser Google Chrome atau Mozilla Firefox versi terbaru.'
            ],
            'escalation' => 'Jika halaman tetap error, sebutkan kode pesan error yang muncul agar kami terbitkan tiket investigasi sistem.'
        ]
    ]
];
