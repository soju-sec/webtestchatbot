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
            'action' => 'check_spp_status',
            'method' => 'GET',
            'path' => '/api/v1/siskeudes/spp/status',
            'description' => 'Mengecek status pencairan SPP desa (Surat Permintaan Pembayaran), rincian belanja bruto, potongan pajak, nominal netto, alur verifikasi berkas, dan nomor referensi bank BPD',
            'params' => ['no_spp', 'no_kwitansi', 'kode_cms', 'desa', 'kabupaten', 'tahun'],
            'current_data' => [
                'no_spp_contoh' => '00001/SPP/51.03.01.2001/2026',
                'desa' => 'Desa Kuta',
                'kabupaten' => 'Pemerintah Kabupaten Badung',
                'uraian_belanja' => 'Belanja Operasional Kantor dan Alat Tulis Kantor Desa Kuta Tahap I',
                'nominal_bruto' => 'Rp 25.000.000',
                'potongan_pajak' => 'Rp 2.750.000',
                'nominal_netto' => 'Rp 22.250.000',
                'status_pembayaran' => 'BERHASIL_CAIR',
                'bank_tujuan' => 'BPD Bali',
                'rekening_tujuan' => '101.02.0001',
                'no_referensi_bank' => 'REF-BPD-20260117-001',
                'alur_verifikasi' => 'Siskeudes Online Final -> Upload SPTJB & SPP2 -> Verifikasi Sekdes -> Approval Kades'
            ]
        ],
        [
            'action' => 'check_realisasi_apbdes',
            'method' => 'GET',
            'path' => '/api/realisasi',
            'description' => 'Melihat rekapitulasi realisasi anggaran belanja dan pendapatan APBDes per bidang kegiatan dan per bulan pada tahun anggaran berjalan',
            'params' => ['desa', 'tahun', 'bulan', 'bidang'],
            'current_data' => [
                'tahun_anggaran' => '2026',
                'pagu_anggaran_desa' => 'Rp 1.450.000.000',
                'total_realisasi_belanja' => 'Rp 825.500.000 (56.9%)',
                'sisa_anggaran_kas' => 'Rp 624.500.000',
                'rincian_bidang' => [
                    'Penyelenggaraan Pemerintahan Desa' => 'Rp 320.000.000',
                    'Pelaksanaan Pembangunan Desa' => 'Rp 365.500.000',
                    'Pembinaan Kemasyarakatan' => 'Rp 85.000.000',
                    'Pemberdayaan Masyarakat' => 'Rp 55.000.000'
                ]
            ]
        ],
        [
            'action' => 'check_saldo_kas_desa',
            'method' => 'GET',
            'path' => '/api/mock-siskeudes/ceksaldobank',
            'description' => 'Pengecekan saldo rekening kas desa (RKD) yang terdaftar di Bank BPD mitra untuk memastikan kecukupan dana pencairan non-tunai',
            'params' => ['rekening_desa', 'bank'],
            'current_data' => [
                'nama_rekening' => 'Kas Desa Kuta',
                'nomor_rekening' => '101.02.0001',
                'bank_mitra' => 'BPD Bali',
                'saldo_tersedia' => 'Rp 624.500.000',
                'status_rekening' => 'AKTIF (Siap Transaksi)'
            ]
        ],
        [
            'action' => 'check_status_pajak_billing',
            'method' => 'GET',
            'path' => '/api/cms/pajak-belumbayar',
            'description' => 'Pengecekan status potongan pajak PPh/PPN transaksi belanja desa dan validasi masa aktif kode ID Billing DJP',
            'params' => ['id_billing', 'no_spp'],
            'current_data' => [
                'id_billing' => 'CMS2026001001',
                'status_billing' => 'TERBAYAR OTOMATIS VIA CMS BANK',
                'ntpn' => '98A17BC40129',
                'keterangan' => 'Pajak telah terdebet otomatis saat proses pencairan SPP'
            ]
        ],
        [
            'action' => 'export_laporan_tnt_excel',
            'method' => 'GET',
            'path' => '/api/v1/reports/store-metrics/excel',
            'description' => 'Mengunduh file rekapitulasi laporan transaksi pencairan non-tunai Siskeudes TNT dalam format spreadsheet Excel (.xlsx / .csv)',
            'params' => ['tahun', 'format'],
            'current_data' => [
                'filename' => 'Rekap_Pencairan_Siskeudes_TNT_2026.csv',
                'download_path' => '/api/v1/reports/store-metrics/excel',
                'description' => 'Rekapitulasi lengkap transaksi pencairan SPP, belanja desa, potongan pajak, dan transfer CMS Bank'
            ]
        ],
        [
            'action' => 'report_customer_issue',
            'method' => 'POST',
            'path' => '/api/v1/tickets/public-submit',
            'description' => 'Melaporkan kendala operasional transaksi non-tunai Siskeudes TNT, error CMS Bank Desa, akun terkunci, atau kegagalan verifikasi berkas, dan menerbitkan tiket resmi ke Jira SUP',
            'params' => ['category', 'description', 'no_spp', 'no_kwitansi', 'urgency', 'screenshot']
        ]
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
            'intro' => 'Baik Bapak/Ibu, kami siap membantu menerbitkan tiket investigasi selisih data kepada tim teknis & verifikasi. Agar pengecekan dapat segera diproses, mohon lengkapi format verifikasi berikut:',
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
