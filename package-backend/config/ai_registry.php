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
            'action' => 'export_store_report_excel',
            'method' => 'GET',
            'path' => '/api/v1/reports/store-metrics/excel',
            'description' => 'Mengunduh atau meminta file laporan data performa toko dan rincian revenue bulanan dalam format spreadsheet Excel (.xlsx / .csv)',
            'params' => ['year', 'format'],
            'current_data' => [
                'filename' => 'Laporan_Eksekutif_Performa_2026.csv',
                'download_path' => '/api/v1/reports/store-metrics/excel',
                'description' => 'File Excel spreadsheet lengkap berisi data Orders, Gross Earnings, Refunds, dan Rasio Konversi per bulan Januari s/d Desember'
            ]
        ],
        [
            'action' => 'get_revenue_chart',
            'method' => 'GET',
            'path' => '/api/v1/reports/store-metrics/chart',
            'description' => 'Menampilkan atau mengirimkan grafik/chart visual tren performa penjualan dan pendapatan bulanan (bar chart & line orders)',
            'params' => ['chart_type', 'year'],
            'current_data' => [
                'chart_url' => 'https://quickchart.io/chart?w=600&h=380&devicePixelRatio=2&c=%7Btype%3A%27bar%27%2Cdata%3A%7Blabels%3A%5B%27Jan%27%2C%27Feb%27%2C%27Mar%27%2C%27Apr%27%2C%27Mei%27%2C%27Jun%27%2C%27Jul%27%2C%27Agu%27%2C%27Sep%27%2C%27Okt%27%2C%27Nov%27%2C%27Des%27%5D%2Cdatasets%3A%5B%7Blabel%3A%27Revenue%20(%24k)%27%2Cdata%3A%5B89.25%2C98.58%2C68.74%2C108.87%2C77.54%2C84.03%2C51.24%2C28.57%2C92.57%2C42.36%2C88.51%2C36.57%5D%2CbackgroundColor%3A%27%23128c7e%27%7D%2C%7Btype%3A%27line%27%2Clabel%3A%27Orders%27%2Cdata%3A%5B34%2C65%2C46%2C68%2C49%2C61%2C42%2C44%2C78%2C52%2C63%2C67%5D%2CborderColor%3A%27%23f59e0b%27%7D%5D%7D%7D',
                'summary' => 'Grafik Tren Penjualan 2026: Puncak di April ($108.87k), terendah di Agustus ($28.57k)'
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
        [
            'action' => 'check_spp_cair',
            'method' => 'GET',
            'path' => '/api/v1/siskeudes/spp/status',
            'description' => 'Mengecek status pencairan SPP di Siskeudes-Link / CMS Bank Desa berdasarkan nomor SPP atau nomor kwitansi (waktu transaksi, no referensi bank, status pembayaran)',
            'params' => ['no_spp', 'no_kwitansi'],
            'current_data' => [
                'status_pembayaran' => 'Sudah dibayarkan',
                'keterangan_pembayaran' => 'Transaksi pokok berhasil dibayarkan',
                'no_referensi_bank' => '001637',
                'waktu_transaksi' => '03-09-2024 15:12:06',
                'spp_contoh' => '0015/SPP/12.2001/2024'
            ]
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SOP & Templating Pertanyaan Resmi Siskeudes-Link TNT & Non-Teknis
    |--------------------------------------------------------------------------
    | Panduan operasional standar Siskeudes TNT:
    | 1. Verifikasi SPP & Upload Berkas SPTJB/SPP2
    | 2. Status Akun Merah saat Verifikasi SPP
    | 3. Sinkronisasi SPP Final Siskeudes Online ke Siskeudes TNT
    | 4. Koreksi ID Billing Pajak & Potongan Belum Bayar
    | 5. Menu Pembayaran Gagal CMS Bank Desa
    */
    'support_templates' => [
        'spp_verifikasi_upload' => [
            'topic' => 'Verifikasi Data SPP & Upload Berkas SPTJB/SPP2',
            'keywords' => ['upload spp', 'sptjb', 'spp2', 'captcha', 'tombol setuju', 'kode cms tidak muncul', 'verifikasi spp', 'gagal upload'],
            'steps' => [
                'Buka menu <b>Verifikasi SPP</b>, lalu klik tombol <b>Aksi</b> pada baris nomor SPP yang akan diverifikasi.',
                'Unggah berkas <b>SPTJB</b> (Surat Pernyataan Tanggung Jawab Belanja) dan berkas <b>SPP2</b> yang telah ditandatangani basah/resmi oleh perangkat desa.',
                'Ketikkan kode keamanan <b>Captcha</b> sesuai kombinasi angka/huruf yang muncul pada pop-up layar.',
                'Pastikan muncul konfirmasi teks berwarna hijau bertuliskan <i>"File berhasil diupload"</i>.',
                'Klik tombol <b>Setuju</b> pada pop-up konfirmasi verifikasi. Setelah disetujui, sistem akan otomatis menerbitkan <b>Kode CMS</b> untuk diproses ke Bank.'
            ],
            'escalation' => 'Jika tombol Setuju tetap abu-abu atau Captcha gagal terus, laporkan via Form Kendala Siskeudes agar tim teknis memeriksa modul upload server.'
        ],
        'spp_akun_merah' => [
            'topic' => 'Status Akun Merah pada Menu Verifikasi SPP',
            'keywords' => ['akun merah', 'status merah', 'rekening merah', 'tidak bisa verifikasi', 'rekening tidak cocok'],
            'steps' => [
                'Pada layar Verifikasi SPP, jika indikator akun berwarna <b>MERAH</b>, data tersebut <b>belum dapat diverifikasi</b> karena ada ketidaksesuaian nomor rekening bank atau pagu kas.',
                'Buka kembali aplikasi <b>Siskeudes Online Desktop</b> di komputer desa.',
                'Periksa rekening kas desa pada menu Penatausahaan ➔ SPP Definitif, pastikan rekening bank dan rekening rincian belanja sudah sesuai.',
                'Lakukan perbaikan data di Siskeudes Online hingga valid, lalu simpan.',
                'Buka kembali Siskeudes TNT; indikator akun akan otomatis berubah menjadi <b>HIJAU</b> dan tombol verifikasi siap digunakan.'
            ],
            'escalation' => 'Jika rekening di Siskeudes Online sudah dipastikan benar tetapi status di web tetap merah, gunakan tombol Lapor Kendala untuk sinkronisasi master rekening.'
        ],
        'spp_online_sync' => [
            'topic' => 'SPP dari Siskeudes Online Belum Muncul di Siskeudes TNT',
            'keywords' => ['spp tidak muncul', 'spp belum masuk', 'spp online', 'tarik spp', 'spp hilang', 'spp belum ada'],
            'steps' => [
                'Siskeudes TNT hanya menarik data <b>SPP Definitif</b> yang berstatus <b>FINAL</b> dari aplikasi Siskeudes Online.',
                'Buka aplikasi Siskeudes Online, masuk ke menu <b>SPP Pelaksana Kegiatan</b>.',
                'Cari nomor SPP yang bersangkutan, lalu periksa dropdown <b>Status</b> di bagian bawah.',
                'Ubah status SPP menjadi <b>"Final"</b> (bukan Baru / Draft), lalu klik <b>Simpan</b>.',
                'Buka kembali browser Siskeudes TNT dan refresh menu <b>Data SPP</b> atau <b>Verifikasi SPP</b>.'
            ],
            'escalation' => 'Jika status SPP di Siskeudes Online sudah Final tetapi lebih dari 10 menit belum muncul di Siskeudes TNT, laporkan nomor SPP via Form Kendala Siskeudes.'
        ],
        'pajak_koreksi_id_billing' => [
            'topic' => 'Koreksi ID Billing Pajak & Potongan Belum Bayar',
            'keywords' => ['id billing', 'billing pajak', 'pajak belum bayar', 'koreksi billing', 'ntpn', 'billing kedaluwarsa'],
            'steps' => [
                'Untuk memeriksa daftar potongan pajak yang belum dibayarkan melalui CMS Bank, masuk ke menu <b>Koreksi ID Billing Pajak</b>.',
                'Jika kode billing pajak sudah kedaluwarsa (*expired*), generate ID Billing baru di DJP Online atau Siskeudes.',
                'Masukkan nomor ID Billing baru pada kolom koreksi di baris potongan pajak terkait.',
                'Lanjutkan proses pembayaran potongan pajak melalui aplikasi CMS Bank Desa.',
                'Setelah sukses terdebet, bukti nomor <b>NTPN</b> dan <b>NTB</b> akan otomatis tercatat dan berpindah ke menu <b>Pajak (Daftar Potongan Pajak yang Sudah Dibayarkan)</b>.'
            ],
            'escalation' => 'Jika pajak sudah didebet di bank tetapi NTPN tidak muncul di menu Pajak Siskeudes TNT, buatkan tiket dengan melampirkan No. Bukti/Kwitansi.'
        ],
        'pembayaran_gagal_bank' => [
            'topic' => 'Penanganan Menu Pembayaran Gagal di CMS Bank',
            'keywords' => ['pembayaran gagal', 'gagal debet', 'rekening pasif', 'saldo tidak cukup', 'cms bank tolak', 'transaksi ditolak'],
            'steps' => [
                'Klik menu <b>Pembayaran Gagal</b> pada navigasi utama Siskeudes TNT untuk melihat daftar transaksi yang ditolak oleh sistem perbankan.',
                'Periksa kolom keterangan penolakan; penyebab umum antara lain: <b>Rekening Penerima Tidak Aktif/Pasif</b>, <b>Nama & Nomor Rekening Tidak Cocok</b>, atau <b>Saldo Kas Desa Tidak Cukupi</b>.',
                'Jika rekening penerima pasif, hubungi rekanan/penerima untuk memastikan nomor rekening valid dan aktif.',
                'Jika saldo kurang, koordinasikan dengan Bank BPD mitra untuk rekonsiliasi mutasi kas desa.'
            ],
            'escalation' => 'Jika saldo mencukupi dan rekening penerima aktif namun status tetap Pembayaran Gagal, laporkan via Form Kendala Siskeudes dengan menyertakan Kode CMS Siskeudes & Kode CMS Bank.'
        ],
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
