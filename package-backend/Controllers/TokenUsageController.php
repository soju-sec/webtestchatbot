<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotTokenService;
use Illuminate\Http\Request;

class TokenUsageController extends Controller
{
    /**
     * Endpoint terpisah untuk melihat ringkasan sisa token, pemakaian saat ini, dan status rotasi key.
     * GET /api/v1/ai/tokens/summary
     */
    public function summary()
    {
        $summary = ChatbotTokenService::getSummary();
        return response()->json($summary);
    }

    /**
     * Endpoint untuk memeriksa status kumpulan API Key (Key Pool) dan kapasitas gabungan.
     * GET /api/v1/ai/tokens/keys
     */
    public function keys()
    {
        $info = ChatbotTokenService::getKeyPoolInfo();
        return response()->json([
            'status' => 'success',
            'data'   => $info
        ]);
    }

    /**
     * Endpoint terpisah untuk melihat riwayat log penggunaan token per percakapan.
     * GET /api/v1/ai/tokens/history?limit=20&offset=0
     */
    public function history(Request $request)
    {
        $limit  = (int) $request->query('limit', 20);
        $offset = (int) $request->query('offset', 0);

        $history = ChatbotTokenService::getHistory($limit, $offset);
        return response()->json([
            'status' => 'success',
            'data'   => $history
        ]);
    }

    /**
     * Endpoint kalkulator token: menghitung token dari teks input sebelum dikirim.
     * POST /api/v1/ai/tokens/count
     * Body: { "text": "contoh kalimat..." }
     */
    public function count(Request $request)
    {
        $request->validate([
            'text' => 'required|string',
        ]);

        $text = $request->input('text');
        $result = ChatbotTokenService::countTokens($text);

        return response()->json($result);
    }

    /**
     * Endpoint pencatatan pemakaian token dari orkestrator (n8n) atau caller lain.
     * POST /api/v1/ai/tokens/record
     */
    public function record(Request $request)
    {
        $validated = $request->validate([
            'prompt_tokens'     => 'nullable|integer',
            'completion_tokens' => 'nullable|integer',
            'candidates_tokens' => 'nullable|integer',
            'total_tokens'      => 'nullable|integer',
            'conversation_id'   => 'nullable|string',
            'user_id'           => 'nullable|string',
            'model'             => 'nullable|string',
            'channel'           => 'nullable|string',
            'metadata'          => 'nullable|array',
        ]);

        $success = ChatbotTokenService::recordUsage($validated);

        return response()->json([
            'status'  => $success ? 'success' : 'fallback_logged',
            'message' => $success ? 'Token usage recorded' : 'Token usage logged to fallback'
        ]);
    }
}
