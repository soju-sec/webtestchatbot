<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotTokenService;
use App\Services\PiiSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConversationMemoryController extends Controller
{
    /**
     * Mendapatkan nama koneksi database terisolasi.
     * Mengutamakan DB_CHATBOT_CONNECTION agar tidak mengganggu database produksi.
     */
    protected function getConnection(): ?string
    {
        return config('chatbot.database_connection', env('DB_CHATBOT_CONNECTION', null));
    }

    /**
     * Mendapatkan nama tabel pesan percakapan terisolasi.
     * Mendukung fallback backward-compatibility jika tabel lama 'conversation_messages' sudah ada.
     */
    protected function getTableName(): string
    {
        $connection = self::getConnection();
        $preferredTable = config('chatbot.tables.messages', env('CHATBOT_TABLE_MESSAGES', 'chatbot_conversation_messages'));

        try {
            $schema = DB::connection($connection)->getSchemaBuilder();
            if ($schema->hasTable($preferredTable)) {
                return $preferredTable;
            }
            // Backward-compatibility jika tabel lama sudah ada
            if ($schema->hasTable('conversation_messages')) {
                return 'conversation_messages';
            }
        } catch (\Throwable $e) {
            Log::warning('[ConversationMemoryController] Gagal cek schema tabel: ' . $e->getMessage());
        }

        return $preferredTable;
    }

    /**
     * Mengambil riwayat pesan percakapan untuk memori multi-turn AI.
     * Semua pesan dipastikan telah disaring dari data sensitif (PII) seperti KTP, No HP, dll.
     */
    public function getMessages(Request $request, string $conversationId)
    {
        $limit = min((int) $request->query('limit', 12), 30);
        $connection = $this->getConnection();
        $table = $this->getTableName();

        try {
            $messages = DB::connection($connection)->table($table)
                ->where('conversation_id', $conversationId)
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get(['role', 'message', 'metadata', 'created_at'])
                ->reverse()
                ->values();

            // Pastikan tidak ada data sensitif (PII) yang dikirimkan ke konteks AI
            $messages = $messages->map(function ($msg) {
                $msg->message = class_exists(PiiSanitizer::class)
                    ? PiiSanitizer::sanitize($msg->message)
                    : self::fallbackSanitize($msg->message);
                return $msg;
            });

            return response()->json(['messages' => $messages]);
        } catch (\Throwable $e) {
            Log::warning('[ConversationMemoryController] Gagal mengambil riwayat pesan: ' . $e->getMessage());
            // Fail-safe: Kembalikan array kosong agar chat tetap dapat berjalan tanpa memori jika DB offline
            return response()->json(['messages' => []]);
        }
    }

    /**
     * Menyimpan pesan percakapan ke database memori.
     * Pesan disaring secara otomatis sehingga data sensitif (KTP, No HP) tidak disimpan secara mentah.
     * Sekaligus mencatat pemakaian token AI jika tersedia di metadata secara terpisah.
     */
    public function storeMessage(Request $request, string $conversationId)
    {
        $data = $request->validate([
            'user_id'  => 'required|string',
            'role'     => 'required|in:user,assistant',
            'message'  => 'required|string',
            'channel'  => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $rawMessage = $data['message'];
        $hasPii = class_exists(PiiSanitizer::class)
            ? PiiSanitizer::containsPii($rawMessage)
            : self::fallbackContainsPii($rawMessage);

        $cleanMessage = class_exists(PiiSanitizer::class)
            ? PiiSanitizer::sanitize($rawMessage)
            : self::fallbackSanitize($rawMessage);

        $metadata = $data['metadata'] ?? [];
        if ($hasPii) {
            $metadata['pii_sanitized'] = true;
        }

        $connection = $this->getConnection();
        $table = $this->getTableName();

        // 1. Simpan pesan percakapan
        $id = null;
        try {
            $id = DB::connection($connection)->table($table)->insertGetId([
                'conversation_id' => $conversationId,
                'user_id'         => $data['user_id'],
                'role'            => $data['role'],
                'message'         => $cleanMessage,
                'channel'         => $data['channel'] ?? 'web',
                'metadata'        => !empty($metadata) ? json_encode($metadata) : null,
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[ConversationMemoryController] Gagal menyimpan pesan ke database: ' . $e->getMessage());
        }

        // 2. Tracking token secara terpisah & aman (jika role assistant dan ada data token)
        if ($data['role'] === 'assistant' && !empty($metadata['tokens'])) {
            try {
                $tokenData = $metadata['tokens'];
                if (is_array($tokenData)) {
                    $tokenData['conversation_id'] = $conversationId;
                    $tokenData['user_id'] = $data['user_id'];
                    $tokenData['channel'] = $data['channel'] ?? 'web';
                    if (class_exists(ChatbotTokenService::class)) {
                        ChatbotTokenService::recordUsage($tokenData);
                    }
                }
            } catch (\Throwable $e) {
                // Tracking token tidak boleh menggagalkan respon chat
                Log::warning('[ConversationMemoryController] Gagal merekam token: ' . $e->getMessage());
            }
        }

        return response()->json([
            'status'        => 'success',
            'id'            => $id,
            'pii_sanitized' => $hasPii,
        ], 201);
    }

    /**
     * Sanitasi fallback jika PiiSanitizer belum di-autoload
     */
    protected static function fallbackSanitize(string $text): string
    {
        $text = preg_replace('/\b\d{4}[ -]?\d{4}[ -]?\d{4}[ -]?\d{4}\b|\b\d{16}\b/', '[NOMOR_KTP_DISAMARKAN]', $text);
        $text = preg_replace('/(?:\+?62|0)8[0-9]{1,3}[ -]?[0-9]{3,4}[ -]?[0-9]{3,5}\b/', '[NOMOR_HP_DISAMARKAN]', $text);
        $text = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', '[EMAIL_DISAMARKAN]', $text);
        return $text;
    }

    protected static function fallbackContainsPii(string $text): bool
    {
        return self::fallbackSanitize($text) !== $text;
    }
}
