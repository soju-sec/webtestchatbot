<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotNotificationService;
use App\Services\PiiSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            'metadata'        => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ];

        // 1. Kirim notifikasi instan ke tim internal (Telegram & WhatsApp)
        $notificationStatus = ['telegram' => false, 'whatsapp' => false];
        try {
            $notificationStatus = ChatbotNotificationService::notifyAll($ticketData);
        } catch (\Throwable $e) {
            Log::warning('[TicketReportController] Gagal dispatch notifikasi: ' . $e->getMessage());
        }

        $ticketData['notified_channels'] = json_encode([
            'telegram' => $notificationStatus['telegram'] ?? false,
            'whatsapp' => $notificationStatus['whatsapp'] ?? false,
        ]);

        // 2. Simpan tiket ke database terisolasi
        try {
            DB::connection($connection)->table($table)->insert($ticketData);
        } catch (\Throwable $e) {
            Log::error('[TicketReportController] Gagal menyimpan tiket ke database: ' . $e->getMessage());
            // Tetap kembalikan respon sukses jika notifikasi sudah terkirim agar user tidak panik
            return response()->json([
                'status'        => 'partial_success',
                'ticket_number' => $ticketNumber,
                'message'       => 'Laporan diterima dan tim internal telah diberitahu, namun pencatatan database mengalami kendala teknis.',
                'error'         => $e->getMessage(),
            ], 200);
        }

        return response()->json([
            'status'        => 'success',
            'message'       => 'Tiket laporan kendala berhasil dibuat dan diteruskan ke tim internal via Telegram/WhatsApp.',
            'ticket'        => [
                'ticket_number'   => $ticketNumber,
                'conversation_id' => $ticketData['conversation_id'],
                'user_id'         => $ticketData['user_id'],
                'category'        => $ticketData['category'],
                'feature_name'    => $ticketData['feature_name'],
                'description'     => $ticketData['description'],
                'urgency'         => $ticketData['urgency'],
                'status'          => 'open',
                'created_at'      => $ticketData['created_at']->toIso8601String(),
            ],
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
}
