<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JiraService
{
    /**
     * Mendapatkan base URL instance Jira Cloud.
     * Contoh: https://imtekservice.atlassian.net
     */
    public static function getBaseUrl(): string
    {
        $host = config('chatbot.jira.host', env('JIRA_HOST', 'imtekservice.atlassian.net'));
        
        // Bersihkan host jika dimasukkan URL lengkap
        $host = preg_replace('#^https?://#i', '', trim($host));
        $host = rtrim($host, '/');

        return "https://{$host}";
    }

    /**
     * Mendapatkan Project Key Jira.
     * Contoh: SUP
     */
    public static function getProjectKey(): string
    {
        return strtoupper(trim(config('chatbot.jira.project_key', env('JIRA_PROJECT_KEY', 'SUP'))));
    }

    /**
     * Mendapatkan email pengguna Atlassian.
     */
    public static function getUserEmail(): string
    {
        return trim(config('chatbot.jira.user_email', env('JIRA_USER_EMAIL', '')));
    }

    /**
     * Mendapatkan Atlassian API Token.
     */
    public static function getApiToken(): string
    {
        return trim(config('chatbot.jira.api_token', env('JIRA_API_TOKEN', '')));
    }

    /**
     * Mengecek apakah integrasi Jira aktif dan kredensial dasar telah diisi.
     */
    public static function isConfigured(): bool
    {
        $enabled = config('chatbot.jira.enabled', env('JIRA_ENABLED', true));
        $email   = self::getUserEmail();
        $token   = self::getApiToken();

        return $enabled && !empty($email) && !empty($token);
    }

    /**
     * Membuat Basic Auth Header untuk Atlassian Jira Cloud REST API.
     */
    public static function getAuthHeaders(): array
    {
        $email = self::getUserEmail();
        $token = self::getApiToken();
        $auth  = base64_encode("{$email}:{$token}");

        return [
            'Authorization' => "Basic {$auth}",
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Menguji koneksi ke Jira instance dan memvalidasi akses ke Project SUP.
     *
     * @return array Status koneksi dan detail proyek
     */
    public static function testConnection(): array
    {
        $baseUrl    = self::getBaseUrl();
        $projectKey = self::getProjectKey();
        $email      = self::getUserEmail();
        $token      = self::getApiToken();

        if (empty($email) || empty($token)) {
            return [
                'success' => false,
                'status'  => 'missing_credentials',
                'message' => 'JIRA_USER_EMAIL atau JIRA_API_TOKEN belum diisi di file .env',
                'help'    => 'Dapatkan API Token di: https://id.atlassian.com/manage-profile/security/api-tokens',
            ];
        }

        try {
            // 1. Verifikasi kredensial via /rest/api/2/myself
            $userRes = Http::withHeaders(self::getAuthHeaders())
                ->timeout(10)
                ->get("{$baseUrl}/rest/api/2/myself");

            if ($userRes->status() === 401 || $userRes->status() === 403) {
                return [
                    'success' => false,
                    'status'  => 'unauthorized',
                    'message' => 'Autentikasi Jira gagal (HTTP ' . $userRes->status() . '). Periksa JIRA_USER_EMAIL dan JIRA_API_TOKEN.',
                    'response' => $userRes->json(),
                ];
            }

            if (!$userRes->successful()) {
                return [
                    'success'  => false,
                    'status'   => 'api_error',
                    'message'  => 'Jira API Error: HTTP ' . $userRes->status(),
                    'response' => $userRes->json(),
                ];
            }

            $userData = $userRes->json();

            // 2. Verifikasi akses ke Project (misal: SUP)
            $projRes = Http::withHeaders(self::getAuthHeaders())
                ->timeout(10)
                ->get("{$baseUrl}/rest/api/2/project/{$projectKey}");

            if (!$projRes->successful()) {
                return [
                    'success'    => false,
                    'status'     => 'project_not_found',
                    'message'    => "Proyek '{$projectKey}' tidak ditemukan atau akun Jira tidak memiliki izin akses (HTTP {$projRes->status()}).",
                    'user'       => $userData['displayName'] ?? $email,
                    'projectKey' => $projectKey,
                ];
            }

            $projData = $projRes->json();
            $issueTypes = array_map(function ($it) {
                return [
                    'id'          => $it['id'] ?? '',
                    'name'        => $it['name'] ?? '',
                    'description' => $it['description'] ?? '',
                    'subtask'     => $it['subtask'] ?? false,
                ];
            }, $projData['issueTypes'] ?? []);

            return [
                'success'     => true,
                'status'      => 'connected',
                'message'     => "Koneksi Jira ke instance '{$baseUrl}' berhasil! Proyek '{$projectKey}' siap menerima tiket.",
                'instance'    => $baseUrl,
                'project_key' => $projectKey,
                'project_name'=> $projData['name'] ?? $projectKey,
                'project_type'=> $projData['projectTypeKey'] ?? 'service_desk',
                'user'        => [
                    'displayName'  => $userData['displayName'] ?? '',
                    'emailAddress' => $userData['emailAddress'] ?? $email,
                    'accountType'  => $userData['accountType'] ?? '',
                ],
                'available_issue_types' => $issueTypes,
            ];
        } catch (\Throwable $e) {
            Log::error('[JiraService] Test connection exception: ' . $e->getMessage());
            return [
                'success' => false,
                'status'  => 'network_error',
                'message' => 'Gagal menghubungi Jira instance: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Membuat tiket / issue baru di Jira Service Management (Proyek SUP).
     *
     * @param array $ticket Data tiket dari Chatbot/User
     * @return array Hasil pembuatan issue Jira
     */
    public static function createIssue(array $ticket): array
    {
        if (!self::isConfigured()) {
            return [
                'success' => false,
                'message' => 'Jira integration belum dikonfigurasi lengkap di .env (JIRA_USER_EMAIL / JIRA_API_TOKEN kosong)',
            ];
        }

        $baseUrl      = self::getBaseUrl();
        $projectKey   = self::getProjectKey();
        $ticketNumber = $ticket['ticket_number'] ?? ('TCK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4)));
        $featureName  = $ticket['feature_name'] ?? 'Fitur Sistem';
        $category     = $ticket['category'] ?? 'fitur_tidak_dapat_diakses';
        $urgency      = strtolower($ticket['urgency'] ?? 'tinggi');
        $description  = $ticket['description'] ?? 'Tidak ada detail laporan';
        $userId       = $ticket['user_id'] ?? 'guest';
        $userName     = !empty($ticket['user_name']) ? " ({$ticket['user_name']})" : "";
        $conversation = $ticket['conversation_id'] ?? '-';
        $channel      = $ticket['channel'] ?? 'web';
        $timeStr      = date('Y-m-d H:i:s') . ' WIB';

        // 1. Tentukan Priority Jira berdasarkan Urgensi
        $jiraPriority = match ($urgency) {
            'darurat', 'critical' => 'Highest',
            'tinggi', 'high'       => 'High',
            'sedang', 'medium'     => 'Medium',
            'rendah', 'low'        => 'Low',
            default                => 'High',
        };

        // 2. Tentukan Summary Singkat yang Informatif
        $summary = "[{$ticketNumber}] Kendala {$featureName}: " . mb_substr($description, 0, 70);
        if (mb_strlen($description) > 70) {
            $summary .= '...';
        }

        // 3. Susun Deskripsi Lengkap dengan Header & Format Jira Rapi
        $jiraDescription = "h3. 🚨 Laporan Kendala Sistem dari Chatbot Pelanggan\n\n"
            . "* *Nomor Tiket Chatbot:* {{#{$ticketNumber}}}\n"
            . "* *Pelapor:* `{$userId}`{$userName}\n"
            . "* *Fitur Terkendala:* *{$featureName}*\n"
            . "* *Kategori Masalah:* {$category}\n"
            . "* *Tingkat Urgensi:* *{$jiraPriority}* (Input: {$urgency})\n"
            . "* *ID Percakapan:* `{$conversation}`\n"
            . "* *Kanal Pelaporan:* `{$channel}`\n"
            . "* *Waktu Kejadian:* {$timeStr}\n\n"
            . "----\n"
            . "h4. 📝 Rincian Masalah Pelanggan:\n"
            . "{quote}\n{$description}\n{quote}\n\n"
            . "----\n"
            . "_Tiket ini dibuat secara otomatis oleh Universal AI Chatbot System._";

        // 4. Issue Type (Dapat dikonfigurasi melalui JIRA_ISSUE_TYPE)
        $configuredIssueType = config('chatbot.jira.issue_type', env('JIRA_ISSUE_TYPE', 'Service Request'));

        // Buat payload awal
        $payload = [
            'fields' => [
                'project' => [
                    'key' => $projectKey,
                ],
                'summary'     => $summary,
                'description' => $jiraDescription,
                'issuetype'   => [
                    'name' => $configuredIssueType,
                ],
                'priority' => [
                    'name' => $jiraPriority,
                ],
                'labels' => [
                    'chatbot',
                    'customer-issue',
                    'sup-ticket',
                ],
            ],
        ];

        try {
            $url = "{$baseUrl}/rest/api/2/issue";
            $response = Http::withHeaders(self::getAuthHeaders())
                ->timeout(12)
                ->post($url, $payload);

            // Jika sukses langsung
            if ($response->successful()) {
                $resData  = $response->json();
                $issueKey = $resData['key'] ?? '';
                $issueId  = $resData['id'] ?? '';
                $browseUrl= "{$baseUrl}/browse/{$issueKey}";

                Log::info("[JiraService] Berhasil membuat Jira Issue {$issueKey} untuk tiket {$ticketNumber}");

                return [
                    'success'  => true,
                    'key'      => $issueKey,
                    'id'       => $issueId,
                    'url'      => $browseUrl,
                    'summary'  => $summary,
                    'message'  => "Tiket Jira {$issueKey} berhasil dibuat",
                ];
            }

            $resJson = $response->json();
            $errorMsg = json_encode($resJson);

            // 5. Intelligent Fallback: Jika Issue Type tidak cocok, cari Issue Type yang tersedia di project SUP
            if ($response->status() === 400 && (str_contains($errorMsg, 'issuetype') || str_contains($errorMsg, 'issue type'))) {
                Log::warning("[JiraService] Issue type '{$configuredIssueType}' tidak valid untuk {$projectKey}. Mengambil daftar issue type valid...");

                $fallbackRes = Http::withHeaders(self::getAuthHeaders())
                    ->timeout(8)
                    ->get("{$baseUrl}/rest/api/2/project/{$projectKey}");

                if ($fallbackRes->successful()) {
                    $projMeta = $fallbackRes->json();
                    $validTypes = $projMeta['issueTypes'] ?? [];
                    // Cari tipe standar (bukan sub-task)
                    $pickedType = null;
                    foreach ($validTypes as $vt) {
                        if (empty($vt['subtask'])) {
                            // Prioritaskan nama yang umum untuk service desk / customer request
                            $name = $vt['name'] ?? '';
                            if (stripos($name, 'service') !== false || stripos($name, 'request') !== false || stripos($name, 'task') !== false || stripos($name, 'incident') !== false) {
                                $pickedType = $name;
                                break;
                            }
                            if (!$pickedType) {
                                $pickedType = $name;
                            }
                        }
                    }

                    if ($pickedType && $pickedType !== $configuredIssueType) {
                        Log::info("[JiraService] Mencoba membuat issue ulang dengan issue type cadangan: '{$pickedType}'");
                        $payload['fields']['issuetype']['name'] = $pickedType;

                        $retryRes = Http::withHeaders(self::getAuthHeaders())
                            ->timeout(12)
                            ->post($url, $payload);

                        if ($retryRes->successful()) {
                            $resData  = $retryRes->json();
                            $issueKey = $resData['key'] ?? '';
                            $issueId  = $resData['id'] ?? '';
                            $browseUrl= "{$baseUrl}/browse/{$issueKey}";

                            Log::info("[JiraService] Berhasil membuat Jira Issue {$issueKey} (menggunakan fallback issue type: {$pickedType})");

                            return [
                                'success'     => true,
                                'key'         => $issueKey,
                                'id'          => $issueId,
                                'url'         => $browseUrl,
                                'issue_type'  => $pickedType,
                                'summary'     => $summary,
                                'message'     => "Tiket Jira {$issueKey} berhasil dibuat (type: {$pickedType})",
                            ];
                        }
                        $errorMsg = json_encode($retryRes->json());
                    }
                }
            }

            Log::error("[JiraService] Gagal membuat Jira issue (HTTP {$response->status()}): {$errorMsg}");

            return [
                'success' => false,
                'status'  => $response->status(),
                'message' => "Jira API error: " . ($resJson['errorMessages'][0] ?? ($resJson['errors'] ? json_encode($resJson['errors']) : 'Gagal membuat tiket di Jira')),
                'raw'     => $resJson,
            ];
        } catch (\Throwable $e) {
            Log::error("[JiraService] Exception saat membuat Jira issue: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Mengambil detail dan status terkini dari suatu tiket Jira.
     *
     * @param string $issueKey Contoh: SUP-12
     * @return array Data issue dari Jira
     */
    public static function getIssue(string $issueKey): array
    {
        if (!self::isConfigured()) {
            return ['success' => false, 'message' => 'Jira credentials tidak disetel'];
        }

        $baseUrl = self::getBaseUrl();

        try {
            $response = Http::withHeaders(self::getAuthHeaders())
                ->timeout(10)
                ->get("{$baseUrl}/rest/api/2/issue/{$issueKey}");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'status'  => $response->status(),
                    'message' => "Tiket {$issueKey} tidak ditemukan di Jira",
                ];
            }

            $data = $response->json();
            $fields = $data['fields'] ?? [];

            return [
                'success'     => true,
                'key'         => $data['key'] ?? $issueKey,
                'summary'     => $fields['summary'] ?? '',
                'status'      => $fields['status']['name'] ?? 'Unknown',
                'priority'    => $fields['priority']['name'] ?? '',
                'issuetype'   => $fields['issuetype']['name'] ?? '',
                'assignee'    => $fields['assignee']['displayName'] ?? 'Unassigned',
                'created_at'  => $fields['created'] ?? null,
                'updated_at'  => $fields['updated'] ?? null,
                'browse_url'  => "{$baseUrl}/browse/{$issueKey}",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Menambahkan komentar ke tiket Jira.
     *
     * @param string $issueKey Contoh: SUP-12
     * @param string $comment Isi komentar
     * @return array
     */
    public static function addComment(string $issueKey, string $comment): array
    {
        if (!self::isConfigured()) {
            return ['success' => false, 'message' => 'Jira credentials tidak disetel'];
        }

        $baseUrl = self::getBaseUrl();

        try {
            $response = Http::withHeaders(self::getAuthHeaders())
                ->timeout(10)
                ->post("{$baseUrl}/rest/api/2/issue/{$issueKey}/comment", [
                    'body' => $comment,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Komentar berhasil ditambahkan ke Jira'];
            }

            return [
                'success' => false,
                'message' => 'Gagal menambah komentar di Jira: ' . $response->body(),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Helper untuk mengekstrak teks biasa dari Atlassian Document Format (ADF).
     */
    public static function extractPlainTextFromAdf($node): string
    {
        if (is_string($node)) return $node;
        if (!is_array($node)) return '';
        $text = $node['text'] ?? '';
        if (!empty($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                $text .= ' ' . self::extractPlainTextFromAdf($child);
            }
        }
        return trim($text);
    }

    /**
     * Mengambil daftar issues dari Jira Cloud menggunakan REST API v3 search JQL.
     */
    public static function fetchIssues(?string $jql = null, int $maxResults = 50): array
    {
        if (!self::isConfigured()) {
            return [
                'success' => false,
                'message' => 'Kredensial Jira belum dikonfigurasi.',
                'issues'  => [],
            ];
        }

        $baseUrl    = self::getBaseUrl();
        $projectKey = self::getProjectKey();
        $query      = $jql ?: "project = {$projectKey} ORDER BY created DESC";

        try {
            $response = Http::withHeaders(self::getAuthHeaders())
                ->timeout(15)
                ->post("{$baseUrl}/rest/api/3/search/jql", [
                    'jql'        => $query,
                    'maxResults' => $maxResults,
                    'fields'     => [
                        'summary',
                        'status',
                        'priority',
                        'created',
                        'updated',
                        'issuetype',
                        'reporter',
                        'assignee',
                        'description',
                        'labels',
                        'comment',
                        'resolution',
                        'resolutiondate',
                    ],
                ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'status'  => $response->status(),
                    'message' => 'Gagal mengambil issues dari Jira: HTTP ' . $response->status(),
                    'issues'  => [],
                ];
            }

            $data = $response->json();
            $rawIssues = $data['issues'] ?? [];

            $normalized = [];
            foreach ($rawIssues as $issue) {
                $key = $issue['key'] ?? ('ID-' . ($issue['id'] ?? ''));
                $fields = $issue['fields'] ?? [];
                $descRaw = $fields['description'] ?? null;
                $plainDesc = self::extractPlainTextFromAdf($descRaw);

                // Ekstrak resolusi dan komentar terakhir jika ada
                $resName = $fields['resolution']['name'] ?? null;
                $lastCommentText = null;
                $comments = $fields['comment']['comments'] ?? [];
                if (!empty($comments) && is_array($comments)) {
                    $last = end($comments);
                    $lastCommentText = self::extractPlainTextFromAdf($last['body'] ?? null);
                }
                $resolutionNotes = $lastCommentText ?: ($resName ? "Resolusi Jira: {$resName}" : null);

                $normalized[] = [
                    'id'               => $issue['id'] ?? '',
                    'key'              => $key,
                    'summary'          => $fields['summary'] ?? '',
                    'description'      => $plainDesc ?: ($fields['summary'] ?? ''),
                    'status'           => $fields['status']['name'] ?? 'Open',
                    'status_category'  => $fields['status']['statusCategory']['key'] ?? 'new',
                    'priority'         => $fields['priority']['name'] ?? 'Medium',
                    'issue_type'       => $fields['issuetype']['name'] ?? 'Task',
                    'reporter'         => $fields['reporter']['displayName'] ?? ($fields['reporter']['emailAddress'] ?? 'Pelanggan'),
                    'reporter_email'   => $fields['reporter']['emailAddress'] ?? '',
                    'assignee'         => $fields['assignee']['displayName'] ?? 'Unassigned',
                    'created'          => $fields['created'] ?? null,
                    'updated'          => $fields['updated'] ?? null,
                    'resolution_notes' => $resolutionNotes,
                    'resolution_date'  => $fields['resolutiondate'] ?? null,
                    'browse_url'       => "{$baseUrl}/browse/{$key}",
                ];
            }

            return [
                'success' => true,
                'total'   => count($normalized),
                'issues'  => $normalized,
            ];
        } catch (\Throwable $e) {
            Log::error('[JiraService] fetchIssues exception: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'issues'  => [],
            ];
        }
    }

    /**
     * Sinkronisasi issues dari Jira Cloud ke tabel lokal `chatbot_tickets`.
     * Jika tiket berstatus 'Done' / 'Resolved' dan pelapor belum menerima notifikasi,
     * otomatis memicu Pesan WhatsApp #2 (Solved) secara real-time ke nomor pelapor.
     */
    public static function syncIssuesWithLocalDb(): array
    {
        $fetchRes = self::fetchIssues(null, 50);
        if (!$fetchRes['success']) {
            return $fetchRes;
        }

        $issues = $fetchRes['issues'];
        $syncedCount = 0;
        $solvedNotifiedCount = 0;
        $now = now();

        foreach ($issues as $issue) {
            $key = $issue['key'];

            // Cek apakah tiket lokal dengan jira_key ini sudah ada
            $existing = \Illuminate\Support\Facades\DB::table('chatbot_tickets')
                ->where('jira_key', $key)
                ->first();

            if (!$existing) {
                // Cek fallback pencarian di kolom metadata
                $existing = \Illuminate\Support\Facades\DB::table('chatbot_tickets')
                    ->where('metadata', 'like', "%{$key}%")
                    ->first();
            }

            // Petakan urgency dari prioritas Jira
            $pName = strtolower($issue['priority'] ?? 'medium');
            $urgency = match ($pName) {
                'highest', 'critical' => 'darurat',
                'high'               => 'tinggi',
                'low', 'lowest'      => 'rendah',
                default              => 'sedang',
            };

            // Petakan status
            $jiraStatusLower = strtolower($issue['status'] ?? 'open');
            $isResolved = in_array($jiraStatusLower, ['done', 'resolved', 'closed', 'selesai', 'complete', 'completed'])
                || !empty($issue['resolution_date']);

            $localStatus = 'open';
            if ($isResolved) {
                $localStatus = 'resolved';
            } elseif (str_contains($jiraStatusLower, 'progress') || str_contains($jiraStatusLower, 'investigat')) {
                $localStatus = 'investigating';
            }

            // Petakan nama fitur dari summary jika ada format: [TCK-...] Kendala Fitur: ...
            $summary = $issue['summary'] ?? '';
            $featureName = 'Fitur Sistem';
            if (preg_match('/Kendala\s+([^:]+):/i', $summary, $m)) {
                $featureName = trim($m[1]);
            }

            $payload = [
                'jira_key'     => $key,
                'jira_url'     => $issue['browse_url'],
                'feature_name' => $featureName,
                'description'  => $issue['description'] ?: $summary,
                'urgency'      => $urgency,
                'status'       => $localStatus,
                'user_name'    => $issue['reporter'],
                'channel'      => $existing ? $existing->channel : 'jira-cloud',
                'updated_at'   => $now,
            ];

            if (!empty($issue['resolution_notes'])) {
                $payload['resolution_notes'] = $issue['resolution_notes'];
            }

            if ($existing) {
                // 🚀 REALTIME WA PESAN #2 SOLVED TRIGGER
                $shouldSendWaSolved = $isResolved
                    && empty($existing->resolved_notified_at)
                    && !empty($existing->reporter_phone);

                if ($shouldSendWaSolved) {
                    $ticketArray = (array) $existing;
                    $ticketArray['status'] = 'resolved';
                    if (!empty($issue['resolution_notes'])) {
                        $ticketArray['resolution_notes'] = $issue['resolution_notes'];
                    }

                    try {
                        $waResult = \App\Services\ChatbotNotificationService::sendTicketResolvedToReporter($ticketArray);
                        if (!empty($waResult['success'])) {
                            $payload['resolved_notified_at'] = $now;
                            $solvedNotifiedCount++;
                            Log::info("[JiraService:Sync] Realtime WA Solved sukses dikirim ke {$existing->reporter_phone} (#{$existing->ticket_number}, Jira: {$key})");
                        }
                    } catch (\Throwable $e) {
                        Log::warning("[JiraService:Sync] Exception saat kirim WA Solved: " . $e->getMessage());
                    }
                }

                \Illuminate\Support\Facades\DB::table('chatbot_tickets')
                    ->where('id', $existing->id)
                    ->update($payload);
            } else {
                $ticketNum = 'TCK-' . str_replace('-', '', $key);
                $payload['ticket_number'] = $ticketNum;
                $payload['user_id'] = $issue['reporter_email'] ?: 'jira-user';
                $payload['category'] = 'fitur_tidak_dapat_diakses';
                $payload['created_at'] = !empty($issue['created']) ? date('Y-m-d H:i:s', strtotime($issue['created'])) : $now;

                \Illuminate\Support\Facades\DB::table('chatbot_tickets')->insert($payload);
            }

            $syncedCount++;
        }

        return [
            'success'               => true,
            'message'               => "Berhasil menyinkronkan {$syncedCount} tiket dari Jira Cloud Project SUP.",
            'synced_count'          => $syncedCount,
            'solved_notified_count' => $solvedNotifiedCount,
        ];
    }
}
