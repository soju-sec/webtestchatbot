<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotNotificationService;
use App\Services\JiraService;
use App\Services\PiiSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TicketReportController extends Controller
{
    /**
     * Mendapatkan nama koneksi database terisolasi.
     */
    protected function getConnection(): ?string
    {
        return config('chatbot.database_connection', env('DB_CHATBOT_CONNECTION', null));
    }

    /**
     * Mendapatkan nama tabel tiket laporan terisolasi.
     */
    protected function getTableName(): string
    {
        return config('chatbot.tables.tickets', env('CHATBOT_TABLE_TICKETS', 'chatbot_tickets'));
    }

    /**
     * Membuat tiket laporan kendala/feedback customer baru.
     * Mengirimkan notifikasi instan ke tim internal via Telegram & WhatsApp.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id'         => 'required|string',
            'user_name'       => 'nullable|string',
            'conversation_id' => 'nullable|string',
            'category'        => 'nullable|string',
            'feature_name'    => 'nullable|string',
            'description'     => 'required|string',
            'urgency'         => 'nullable|string|in:rendah,sedang,tinggi,darurat,low,medium,high,critical',
            'channel'         => 'nullable|string',
            'ticket_number'   => 'nullable|string',
            'metadata'        => 'nullable|array',
        ]);

        $connection = $this->getConnection();
        $table      = $this->getTableName();

        // Generate nomor tiket acak unik jika belum ada (Format: TCK-YYYYMMDD-XXXX)
        $ticketNumber = !empty($data['ticket_number'])
            ? $data['ticket_number']
            : 'TCK-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

        // Sanitasi deskripsi dari data pribadi sensitif (KTP, No HP, PIN/Password)
        $cleanDescription = class_exists(PiiSanitizer::class)
            ? PiiSanitizer::sanitize($data['description'])
            : $data['description'];

        $category = !empty($data['category']) ? $data['category'] : 'fitur_tidak_dapat_diakses';
        $featureName = !empty($data['feature_name']) ? $data['feature_name'] : 'Fitur Sistem';
        $urgency = !empty($data['urgency']) ? strtolower($data['urgency']) : 'tinggi';

        $metadata = $data['metadata'] ?? [];

        $ticketData = [
            'ticket_number'   => $ticketNumber,
            'conversation_id' => $data['conversation_id'] ?? null,
            'user_id'         => $data['user_id'],
            'user_name'       => $data['user_name'] ?? null,
            'category'        => $category,
            'feature_name'    => $featureName,
            'description'     => $cleanDescription,
            'urgency'         => $urgency,
            'status'          => 'open',
            'channel'         => $data['channel'] ?? 'web',
            'created_at'      => now(),
            'updated_at'      => now(),
        ];

        // 1. Integrasi Otomatis ke Jira Service Management / Jira Service Desk (Proyek SUP)
        $jiraResult = ['success' => false, 'message' => 'Jira belum dikonfigurasi'];
        try {
            if (class_exists(JiraService::class) && JiraService::isConfigured()) {
                $jiraResult = JiraService::createIssue($ticketData);
                if (!empty($jiraResult['success'])) {
                    $ticketData['jira_key'] = $jiraResult['key'] ?? null;
                    $ticketData['jira_url'] = $jiraResult['url'] ?? null;
                    $metadata['jira'] = [
                        'key'        => $jiraResult['key'] ?? null,
                        'id'         => $jiraResult['id'] ?? null,
                        'url'        => $jiraResult['url'] ?? null,
                        'project'    => JiraService::getProjectKey(),
                        'created_at' => now()->toIso8601String(),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[TicketReportController] Exception saat integrasi Jira: ' . $e->getMessage());
            $jiraResult = ['success' => false, 'message' => $e->getMessage()];
        }

        $ticketData['metadata'] = !empty($metadata) ? json_encode($metadata) : null;

        // 2. Kirim notifikasi instan ke tim internal (Slack, Telegram & WhatsApp)
        // Notifikasi sudah menyertakan link tiket Jira jika berhasil dibuat!
        $notificationStatus = ['slack' => false, 'telegram' => false, 'whatsapp' => false];
        try {
            $notificationStatus = ChatbotNotificationService::notifyAll($ticketData);
        } catch (\Throwable $e) {
            Log::warning('[TicketReportController] Gagal dispatch notifikasi: ' . $e->getMessage());
        }

        $ticketData['notified_channels'] = json_encode([
            'slack'    => $notificationStatus['slack'] ?? false,
            'telegram' => $notificationStatus['telegram'] ?? false,
            'whatsapp' => $notificationStatus['whatsapp'] ?? false,
        ]);

        // 3. Simpan tiket ke database terisolasi
        try {
            // Periksa apakah kolom jira_key / jira_url ada di tabel sebelum insert
            $insertData = $ticketData;
            $hasJiraKey = Schema::connection($connection)->hasColumn($table, 'jira_key');
            if (!$hasJiraKey) {
                // Hilangkan field agar tidak terjadi error SQL jika migrasi belum dijalankan
                unset($insertData['jira_key'], $insertData['jira_url']);
            }

            DB::connection($connection)->table($table)->insert($insertData);
        } catch (\Throwable $e) {
            Log::error('[TicketReportController] Gagal menyimpan tiket ke database: ' . $e->getMessage());
            // Tetap kembalikan respon sukses jika Jira / notifikasi sudah terkirim agar user tidak panik
            return response()->json([
                'status'        => 'partial_success',
                'ticket_number' => $ticketNumber,
                'message'       => 'Laporan diterima dan diteruskan ke Jira / tim internal, namun pencatatan database mengalami kendala teknis.',
                'jira'          => $jiraResult,
                'error'         => $e->getMessage(),
            ], 200);
        }

        return response()->json([
            'status'        => 'success',
            'message'       => 'Tiket laporan kendala berhasil dibuat dan diteruskan ke Jira Service Desk & Tim Internal.',
            'ticket'        => [
                'ticket_number'   => $ticketNumber,
                'jira_key'        => $ticketData['jira_key'] ?? null,
                'jira_url'        => $ticketData['jira_url'] ?? null,
                'conversation_id' => $ticketData['conversation_id'],
                'user_id'         => $ticketData['user_id'],
                'category'        => $ticketData['category'],
                'feature_name'    => $ticketData['feature_name'],
                'description'     => $ticketData['description'],
                'urgency'         => $ticketData['urgency'],
                'status'          => 'open',
                'created_at'      => $ticketData['created_at']->toIso8601String(),
            ],
            'jira'          => $jiraResult,
            'notifications' => $notificationStatus,
        ], 201);
    }

    /**
     * Menampilkan daftar tiket laporan kendala (untuk admin / pemantauan internal).
     */
    public function index(Request $request)
    {
        $connection = $this->getConnection();
        $table      = $this->getTableName();
        $limit      = min((int) $request->query('limit', 20), 100);

        try {
            $query = DB::connection($connection)->table($table);

            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }
            if ($request->has('user_id')) {
                $query->where('user_id', $request->query('user_id'));
            }
            if ($request->has('category')) {
                $query->where('category', $request->query('category'));
            }

            $tickets = $query->orderBy('id', 'desc')->paginate($limit);

            return response()->json([
                'status'  => 'success',
                'tickets' => $tickets
            ]);
        } catch (\Throwable $e) {
            Log::warning('[TicketReportController] Gagal query tiket: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal mengambil data tiket: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menampilkan detail satu tiket berdasarkan nomor tiket.
     */
    public function show(string $ticketNumber)
    {
        $connection = $this->getConnection();
        $table      = $this->getTableName();

        try {
            $ticket = DB::connection($connection)->table($table)
                ->where('ticket_number', $ticketNumber)
                ->first();

            if (!$ticket) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Tiket {$ticketNumber} tidak ditemukan."
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'ticket' => $ticket
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Memperbarui status tiket (open, investigating, resolved, closed).
     */
    public function updateStatus(Request $request, string $ticketNumber)
    {
        $data = $request->validate([
            'status' => 'required|in:open,investigating,in_progress,resolved,closed',
            'note'   => 'nullable|string',
        ]);

        $connection = $this->getConnection();
        $table      = $this->getTableName();

        try {
            $ticket = DB::connection($connection)->table($table)
                ->where('ticket_number', $ticketNumber)
                ->first();

            if (!$ticket) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Tiket {$ticketNumber} tidak ditemukan."
                ], 404);
            }

            DB::connection($connection)->table($table)
                ->where('ticket_number', $ticketNumber)
                ->update([
                    'status'     => $data['status'],
                    'updated_at' => now(),
                ]);

            return response()->json([
                'status'  => 'success',
                'message' => "Status tiket {$ticketNumber} berhasil diperbarui menjadi {$data['status']}.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menguji koneksi dan status kredensial Jira Service Desk.
     */
    public function testJiraConnection(Request $request)
    {
        $status = JiraService::testConnection();
        return response()->json($status, $status['success'] ? 200 : 400);
    }

    /**
     * Melakukan sinkronisasi status tiket dari Jira ke database lokal.
     */
    public function syncJiraStatus(Request $request, string $ticketNumber)
    {
        $connection = $this->getConnection();
        $table      = $this->getTableName();

        try {
            $ticket = DB::connection($connection)->table($table)
                ->where('ticket_number', $ticketNumber)
                ->first();

            if (!$ticket) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Tiket {$ticketNumber} tidak ditemukan."
                ], 404);
            }

            // Ambil Jira Key dari record atau metadata
            $jiraKey = $ticket->jira_key ?? null;
            if (!$jiraKey && !empty($ticket->metadata)) {
                $meta = is_string($ticket->metadata) ? json_decode($ticket->metadata, true) : $ticket->metadata;
                $jiraKey = $meta['jira']['key'] ?? null;
            }

            if (empty($jiraKey)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Tiket {$ticketNumber} tidak memiliki keterkaitan dengan Jira issue.",
                ], 400);
            }

            $jiraIssue = JiraService::getIssue($jiraKey);
            if (!$jiraIssue['success']) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Gagal mengambil data dari Jira: " . ($jiraIssue['message'] ?? 'Unknown error'),
                ], 502);
            }

            // Map status Jira ke status sistem
            $jiraStatusName = strtolower($jiraIssue['status'] ?? '');
            $newStatus = 'open';
            if (str_contains($jiraStatusName, 'progress') || str_contains($jiraStatusName, 'investigat')) {
                $newStatus = 'investigating';
            } elseif (str_contains($jiraStatusName, 'done') || str_contains($jiraStatusName, 'resolved')) {
                $newStatus = 'resolved';
            } elseif (str_contains($jiraStatusName, 'close') || str_contains($jiraStatusName, 'cancel')) {
                $newStatus = 'closed';
            }

            DB::connection($connection)->table($table)
                ->where('ticket_number', $ticketNumber)
                ->update([
                    'status'     => $newStatus,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'status'         => 'success',
                'message'        => "Status tiket {$ticketNumber} disinkronkan dari Jira ({$jiraKey}). Status baru: {$newStatus} (Jira: {$jiraIssue['status']}).",
                'jira_issue'     => $jiraIssue,
                'updated_status' => $newStatus,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
