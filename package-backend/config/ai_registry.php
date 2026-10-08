<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Daftarkan Endpoint API yang Boleh Diakses AI di Proyek Ini
    |--------------------------------------------------------------------------
    | n8n akan membaca manifest ini untuk membuat prompt Gemini secara dinamis.
    */
    'endpoints' => [
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
            'description' => 'Melihat riwayat transaksi keuangan atau pembayaran',
            'params' => ['status', 'limit', 'date_from', 'date_to']
        ],
    ],
];
