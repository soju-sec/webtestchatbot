<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Daftarkan Endpoint API / Capabilities yang Boleh Diakses AI di Proyek Ini
    |--------------------------------------------------------------------------
    | AI (Gemini) diorkestrasi oleh n8n secara otomatis membaca manifest ini
    | via endpoint /api/v1/ai/manifest.
    |
    | Setiap kali Anda menambahkan atau mengubah entri di sini, AI secara
    | OTOMATIS langsung memahami kapabilitas baru tersebut tanpa perlu
    | mengubah alur/workflow n8n sama sekali!
    |
    | Parameter struktur:
    | - 'action'       : Nama unik kapabilitas (misal: get_monthly_revenue)
    | - 'method'       : HTTP Method (GET / POST)
    | - 'path'         : Endpoint path (misal: /api/v1/store/revenue/monthly)
    | - 'description'  : Deskripsi jelas apa data yang disediakan dan kapan digunakan
    | - 'params'       : (Opsional) Parameter query atau path yang didukung
    | - 'current_data'  : (Opsional) Data snapshot / statis jika ingin disuplai langsung
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
];
