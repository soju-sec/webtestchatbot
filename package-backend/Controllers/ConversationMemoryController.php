<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PiiSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationMemoryController extends Controller
{
    /**
     * Mengambil riwayat pesan percakapan untuk memori multi-turn AI.
     * Semua pesan dipastikan telah disaring dari data sensitif (PII) seperti KTP, No HP, dll.
     */
    public function getMessages(Request $request, string $conversationId)
    {
        $limit = min((int) $request->query('limit', 12), 30);
        $messages = DB::table('conversation_messages')
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
    }

    /**
     * Menyimpan pesan percakapan ke database memori.
     * Pesan disaring secara otomatis sehingga data sensitif (KTP, No HP) tidak disimpan secara mentah.
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

        $id = DB::table('conversation_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'user_id'         => $data['user_id'],
            'role'            => $data['role'],
            'message'         => $cleanMessage,
            'channel'         => $data['channel'] ?? 'web',
            'metadata'        => !empty($metadata) ? json_encode($metadata) : null,
            'created_at'      => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'id' => $id,
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
