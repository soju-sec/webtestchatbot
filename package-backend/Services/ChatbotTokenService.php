<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotTokenService
{
    /**
     * Mendapatkan nama koneksi database yang terisolasi.
     * Jika tidak diset, menggunakan koneksi default aplikasi.
     */
    public static function getConnection(): ?string
    {
        return config('chatbot.database_connection', env('DB_CHATBOT_CONNECTION', null));
    }

    /**
     * Mendapatkan nama tabel token yang terisolasi.
     */
    public static function getTableName(): string
    {
        return config('chatbot.tables.tokens', env('CHATBOT_TABLE_TOKENS', 'chatbot_token_usages'));
    }

    /**
     * Mengambil seluruh API Key Gemini dari konfigurasi / environment (Key Pool).
     * Mendukung pemisah koma, titik koma, atau spasi (misal: "key1,key2,key3").
     */
    public static function getKeyPool(): array
    {
        $raw = config('chatbot.gemini_api_keys')
            ?: env('GEMINI_API_KEYS')
            ?: env('GEMINI_API_KEY')
            ?: '';

        if (is_array($raw)) {
            $keys = $raw;
        } else {
            $keys = preg_split('/[,;\s\n\r]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        }

        $cleaned = [];
        foreach ($keys as $k) {
            $trimmed = trim($k);
            if (!empty($trimmed) && strlen($trimmed) > 8 && $trimmed !== 'your_gemini_api_key_here') {
                $cleaned[] = $trimmed;
            }
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * Memilih satu API Key secara bergilir (Rotasi / Load Balancing).
     */
    public static function getRotatedKey(): ?string
    {
        $pool = self::getKeyPool();
        if (empty($pool)) {
            return null;
        }

        // Gunakan random / round-robin selection
        $index = array_rand($pool);
        return $pool[$index];
    }

    /**
     * Menyamarkan API Key untuk keamanan tampilan UI & log (misal: AIzaSy...4bbk).
     */
    public static function maskKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 8) {
            return '********';
        }
        return substr($key, 0, 6) . '...' . substr($key, -4);
    }

    /**
     * Mengambil informasi status kumpulan API Key (Key Pool Status).
     */
    public static function getKeyPoolInfo(): array
    {
        $pool = self::getKeyPool();
        $totalKeys = max(1, count($pool));

        $maskedList = [];
        foreach ($pool as $idx => $k) {
            $maskedList[] = [
                'index'  => $idx,
                'masked' => self::maskKey($k),
                'status' => 'active',
            ];
        }

        return [
            'total_keys'        => count($pool),
            'has_multiple_keys' => count($pool) > 1,
            'rotation_strategy' => count($pool) > 1 
                ? 'Round-Robin Load Balancing + Auto-Failover (429)'
                : 'Single Key Mode',
            'keys'              => $maskedList,
            'combined_capacity' => [
                'rpm'                   => $totalKeys * 15,
                'tpm'                   => number_format($totalKeys * 1000000) . ' TPM',
                'rpd'                   => $totalKeys * 1500,
                'monthly_token_estimate'=> number_format($totalKeys * 1000000) . ' tokens',
            ]
        ];
    }

    /**
     * Mencatat riwayat penggunaan token Gemini secara aman dan terisolasi.
     * Tidak akan pernah menggagalkan flow utama percakapan (Fail-Safe).
     */
    public static function recordUsage(array $data): bool
    {
        $connection = self::getConnection();
        $table = self::getTableName();

        $promptTokens     = (int) ($data['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($data['completion_tokens'] ?? $data['candidates_tokens'] ?? 0);
        $totalTokens      = (int) ($data['total_tokens'] ?? ($promptTokens + $completionTokens));
        $model            = $data['model'] ?? config('chatbot.token_quota.default_model', 'gemini-2.5-flash');

        $record = [
            'conversation_id'   => $data['conversation_id'] ?? null,
            'user_id'           => $data['user_id'] ?? 'unknown',
            'model'             => $model,
            'prompt_tokens'     => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens'      => $totalTokens,
            'channel'           => $data['channel'] ?? 'web',
            'metadata'          => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_at'        => now(),
        ];

        try {
            DB::connection($connection)->table($table)->insert($record);
            return true;
        } catch (\Throwable $e) {
            Log::warning('[ChatbotTokenService] Gagal menyimpan log token ke database: ' . $e->getMessage());
            self::fallbackFileLog($record);
            return false;
        }
    }

    /**
     * Mengambil ringkasan penggunaan token, sisa kuota, dan status rotasi key.
     */
    public static function getSummary(): array
    {
        $connection = self::getConnection();
        $table = self::getTableName();
        $keyPoolInfo = self::getKeyPoolInfo();
        $keyMultiplier = max(1, $keyPoolInfo['total_keys']);

        // Kuota dasar bulanan dikalikan jumlah key aktif dalam pool
        $baseLimit = (int) config('chatbot.token_quota.monthly_limit', env('GEMINI_MONTHLY_TOKEN_QUOTA', 1000000));
        $monthlyLimit = $baseLimit * $keyMultiplier;
        $warningPercent = (int) config('chatbot.token_quota.warning_threshold_percent', 85);
        $model = config('chatbot.token_quota.default_model', 'gemini-2.5-flash');

        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $monthStart = $now->copy()->startOfMonth();

        $stats = [
            'today'      => ['total' => 0, 'prompt' => 0, 'completion' => 0, 'requests' => 0],
            'this_month' => ['total' => 0, 'prompt' => 0, 'completion' => 0, 'requests' => 0],
            'all_time'   => ['total' => 0, 'prompt' => 0, 'completion' => 0, 'requests' => 0],
        ];

        try {
            $hasTable = DB::connection($connection)->getSchemaBuilder()->hasTable($table);
            if ($hasTable) {
                // Hari ini
                $todayRow = DB::connection($connection)->table($table)
                    ->where('created_at', '>=', $todayStart)
                    ->selectRaw('COUNT(*) as reqs, COALESCE(SUM(total_tokens), 0) as tot, COALESCE(SUM(prompt_tokens), 0) as prm, COALESCE(SUM(completion_tokens), 0) as cmp')
                    ->first();

                if ($todayRow) {
                    $stats['today']['requests']   = (int) $todayRow->reqs;
                    $stats['today']['total']      = (int) $todayRow->tot;
                    $stats['today']['prompt']     = (int) $todayRow->prm;
                    $stats['today']['completion'] = (int) $todayRow->cmp;
                }

                // Bulan ini
                $monthRow = DB::connection($connection)->table($table)
                    ->where('created_at', '>=', $monthStart)
                    ->selectRaw('COUNT(*) as reqs, COALESCE(SUM(total_tokens), 0) as tot, COALESCE(SUM(prompt_tokens), 0) as prm, COALESCE(SUM(completion_tokens), 0) as cmp')
                    ->first();

                if ($monthRow) {
                    $stats['this_month']['requests']   = (int) $monthRow->reqs;
                    $stats['this_month']['total']      = (int) $monthRow->tot;
                    $stats['this_month']['prompt']     = (int) $monthRow->prm;
                    $stats['this_month']['completion'] = (int) $monthRow->cmp;
                }

                // Sepanjang Waktu
                $allRow = DB::connection($connection)->table($table)
                    ->selectRaw('COUNT(*) as reqs, COALESCE(SUM(total_tokens), 0) as tot, COALESCE(SUM(prompt_tokens), 0) as prm, COALESCE(SUM(completion_tokens), 0) as cmp')
                    ->first();

                if ($allRow) {
                    $stats['all_time']['requests']   = (int) $allRow->reqs;
                    $stats['all_time']['total']      = (int) $allRow->tot;
                    $stats['all_time']['prompt']     = (int) $allRow->prm;
                    $stats['all_time']['completion'] = (int) $allRow->cmp;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[ChatbotTokenService] Gagal membaca riwayat token: ' . $e->getMessage());
        }

        $usedThisMonth = $stats['this_month']['total'];
        $remainingTokens = $monthlyLimit > 0 ? max(0, $monthlyLimit - $usedThisMonth) : null;
        $usedPercent = $monthlyLimit > 0 ? round(($usedThisMonth / $monthlyLimit) * 100, 2) : 0;

        $status = 'safe';
        $statusLabel = 'Aman (Normal)';
        if ($monthlyLimit > 0) {
            if ($usedThisMonth >= $monthlyLimit) {
                $status = 'exceeded';
                $statusLabel = 'Kuota Habis';
            } elseif ($usedPercent >= $warningPercent) {
                $status = 'warning';
                $statusLabel = 'Mendekati Batas';
            }
        } else {
            $status = 'unlimited';
            $statusLabel = 'Tanpa Batas (Pay-as-you-go)';
        }

        $avgTokensPerReq = $stats['this_month']['requests'] > 0
            ? round($usedThisMonth / $stats['this_month']['requests'], 1)
            : 0;

        return [
            'status' => 'success',
            'model'  => $model,
            'quota'  => [
                'monthly_limit'     => $monthlyLimit,
                'base_limit_per_key'=> $baseLimit,
                'used_this_month'   => $usedThisMonth,
                'remaining_tokens'  => $remainingTokens,
                'used_percentage'   => $usedPercent,
                'status'            => $status,
                'status_label'      => $statusLabel,
                'warning_threshold' => $warningPercent,
            ],
            'usage' => [
                'today'      => $stats['today'],
                'this_month' => $stats['this_month'],
                'all_time'   => $stats['all_time'],
                'avg_tokens_per_request' => $avgTokensPerReq,
            ],
            'key_pool' => $keyPoolInfo,
            'model_limits' => [
                'context_window'   => '1,048,576 tokens',
                'max_output'       => '8,192 tokens',
                'combined_rpm'     => ($keyMultiplier * 15) . ' RPM (Combined)',
                'combined_tpm'     => number_format($keyMultiplier * 1000000) . ' TPM (Combined)',
                'combined_rpd'     => number_format($keyMultiplier * 1500) . ' RPD (Requests Per Day)',
            ],
            'database' => [
                'connection' => $connection ?? 'default',
                'table'      => $table,
            ],
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Mengambil riwayat pemakaian token per percakapan (dengan pagination).
     */
    public static function getHistory(int $limit = 20, int $offset = 0): array
    {
        $connection = self::getConnection();
        $table = self::getTableName();
        $limit = min(max(1, $limit), 100);

        try {
            $hasTable = DB::connection($connection)->getSchemaBuilder()->hasTable($table);
            if (!$hasTable) {
                return ['total' => 0, 'data' => []];
            }

            $total = DB::connection($connection)->table($table)->count();
            $rows = DB::connection($connection)->table($table)
                ->orderBy('id', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get();

            return [
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
                'data'   => $rows,
            ];
        } catch (\Throwable $e) {
            Log::warning('[ChatbotTokenService] Gagal mengambil list riwayat token: ' . $e->getMessage());
            return ['total' => 0, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Menghitung token teks secara langsung via Gemini API countTokens atau estimasi lokal.
     * Menggunakan rotasi key pool dengan failover jika terkena limit 429.
     */
    public static function countTokens(string $text, ?string $apiKey = null): array
    {
        $keys = !empty($apiKey) ? [$apiKey] : self::getKeyPool();
        $model = config('chatbot.token_quota.default_model', 'gemini-2.5-flash');

        if (!empty($keys)) {
            foreach ($keys as $candidateKey) {
                try {
                    $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:countTokens?key={$candidateKey}";
                    $response = Http::timeout(8)->post($endpoint, [
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => $text]
                                ]
                            ]
                        ]
                    ]);

                    if ($response->successful()) {
                        $json = $response->json();
                        return [
                            'status'       => 'success',
                            'method'       => 'gemini_api_live',
                            'model'        => $model,
                            'used_key'     => self::maskKey($candidateKey),
                            'total_tokens' => $json['totalTokens'] ?? self::estimateTokensLocally($text),
                            'char_count'   => mb_strlen($text),
                        ];
                    }

                    // Jika terkena 429 / Quota, otomatis loop mencoba key berikutnya
                    if ($response->status() === 429) {
                        Log::info('[ChatbotTokenService] Key ' . self::maskKey($candidateKey) . ' kena 429, mencoba key berikutnya...');
                        continue;
                    }
                } catch (\Throwable $e) {
                    Log::info('[ChatbotTokenService] Live countTokens API fallback: ' . $e->getMessage());
                }
            }
        }

        // Estimasi Heuristik Cepat (Offline Fallback)
        $estTokens = self::estimateTokensLocally($text);
        return [
            'status'       => 'success',
            'method'       => 'heuristic_estimate',
            'model'        => $model,
            'total_tokens' => $estTokens,
            'char_count'   => mb_strlen($text),
            'note'         => 'Perkiraan heuristik (Gemini API offline atau kuota key habis)'
        ];
    }

    /**
     * Estimasi token lokal: Rata-rata 1 token ≈ 3.5 - 4 karakter.
     */
    public static function estimateTokensLocally(string $text): int
    {
        $charLength = mb_strlen($text);
        if ($charLength === 0) return 0;
        
        $wordCount = str_word_count($text);
        $estByChars = (int) ceil($charLength / 3.5);
        $estByWords = (int) ceil($wordCount * 1.3);

        return max(1, max($estByChars, $estByWords));
    }

    /**
     * Fallback file logger jika DB sedang bermasalah.
     */
    protected static function fallbackFileLog(array $record): void
    {
        try {
            $logPath = storage_path('logs/chatbot_tokens_emergency.log');
            file_put_contents($logPath, json_encode($record) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {}
    }
}
