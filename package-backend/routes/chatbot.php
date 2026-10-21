<?php

use App\Http\Controllers\Api\AiCapabilityController;
use App\Http\Controllers\Api\ConversationMemoryController;
use App\Http\Controllers\Api\TokenUsageController;
use App\Http\Middleware\VerifyInternalApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Chatbot Isolated Routes (Non-Intrusive)
|--------------------------------------------------------------------------
| File route ini terisolasi mandiri agar TIDAK MENGGANGGU routes aplikasi produksi.
| Cukup sertakan satu baris pada routes/api.php:
|     require __DIR__ . '/chatbot.php';
| atau daftarkan via ServiceProvider.
*/

// 1. Safe Proxy Route untuk Web Widget (CORS Protection & Circuit Breaker)
Route::post('/webhook/chat-entry', function (Request $request) {
    $n8nUrl = config('chatbot.n8n.webhook_url', env('N8N_WEBHOOK_URL', 'http://127.0.0.1:5678/webhook/chat-entry'));
    $timeout = (int) config('chatbot.n8n.timeout_seconds', env('CHATBOT_TIMEOUT_SECONDS', 30));

    try {
        $response = Http::timeout($timeout)->post($n8nUrl, $request->all());
        return response()->json($response->json(), $response->status());
    } catch (\Throwable $e) {
        Log::warning('[Chatbot Proxy] Gagal menghubungi n8n orchestrator: ' . $e->getMessage());
        // Respon cadangan fail-safe agar aplikasi host tidak throw 500 error ke user
        return response()->json([
            'status'  => 'fallback',
            'reply'   => 'Mohon maaf, layanan asisten cerdas sedang dalam pemeliharaan berkala. Silakan coba beberapa saat lagi.',
            'intent'  => 'fallback_maintenance',
            'error'   => 'Orchestrator unreachable'
        ], 503);
    }
});

// 2. Internal Chatbot API (Protected by Internal API Key)
Route::middleware([VerifyInternalApiKey::class])->prefix('v1')->group(function () {
    // Intent Manifest & Capabilities
    Route::get('/ai/manifest', [AiCapabilityController::class, 'manifest']);

    // Multi-turn Conversation Memory (Bebas PII & Terisolasi)
    Route::get('/conversations/{conversationId}/messages', [ConversationMemoryController::class, 'getMessages']);
    Route::post('/conversations/{conversationId}/messages', [ConversationMemoryController::class, 'storeMessage']);

    // Fitur Pengecekan & Pemantauan Token Gemini (TERPISAH)
    Route::prefix('ai/tokens')->group(function () {
        Route::get('/summary', [TokenUsageController::class, 'summary']);   // Cek sisa kuota & total terpakai
        Route::get('/keys', [TokenUsageController::class, 'keys']);         // Cek status rotasi & key pool
        Route::get('/history', [TokenUsageController::class, 'history']);   // Riwayat log pemakaian token
        Route::post('/count', [TokenUsageController::class, 'count']);      // Hitung perkiraan token dari teks
        Route::post('/record', [TokenUsageController::class, 'record']);    // Rekam pemakaian token dari n8n/eksternal
    });
});
