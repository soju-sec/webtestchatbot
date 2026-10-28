<?php

use App\Http\Controllers\Api\AiCapabilityController;
use App\Http\Controllers\Api\ConversationMemoryController;
use App\Http\Controllers\Api\TicketReportController;
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

// 2. Webhook Ingress WhatsApp Khusus Eksekutif (Fonnte, Wablas, Meta Cloud API, Twilio)
Route::match(['get', 'post'], '/webhook/whatsapp-entry', [\App\Http\Controllers\Api\WhatsAppWebhookController::class, 'handleIncoming']);
Route::get('/webhook/whatsapp-entry/verify', [\App\Http\Controllers\Api\WhatsAppWebhookController::class, 'verifyWebhook']);
Route::post('/webhook/whatsapp-entry/simulate', [\App\Http\Controllers\Api\WhatsAppWebhookController::class, 'simulate']);

// Route publik untuk melayani file gambar chart PNG asli (diakses Fonnte untuk dikirim sebagai foto)
Route::get('/reports/store-metrics/chart.png', [\App\Http\Controllers\Api\ReportExportController::class, 'getChartImage']);
Route::get('/v1/reports/store-metrics/chart.png', [\App\Http\Controllers\Api\ReportExportController::class, 'getChartImage']);

// Route publik untuk unduh spreadsheet Excel eksekutif (bisa diklik langsung dari WhatsApp tanpa header API key)
Route::get('/reports/store-metrics/excel', [\App\Http\Controllers\Api\ReportExportController::class, 'exportExcel']);
Route::get('/v1/reports/store-metrics/excel', [\App\Http\Controllers\Api\ReportExportController::class, 'exportExcel']);

// Route publik Magic Tracking Link (Bisa diakses mandiri oleh pelapor dari WhatsApp)
Route::get('/tickets/track/{ticketNumber}', [TicketReportController::class, 'track']);
Route::get('/v1/tickets/track/{ticketNumber}', [TicketReportController::class, 'track']);

// Route publik Webhook Jira Cloud (Integrasi 2-Arah untuk auto-notif saat status Resolved)
Route::match(['get', 'post'], '/jira/webhook', [TicketReportController::class, 'handleJiraWebhook']);
Route::match(['get', 'post'], '/v1/jira/webhook', [TicketReportController::class, 'handleJiraWebhook']);

// Route publik pengiriman laporan formulir dari web widget
Route::post('/tickets/public-submit', [TicketReportController::class, 'store']);
Route::post('/v1/tickets/public-submit', [TicketReportController::class, 'store']);

// Route publik pengecekan status pencairan SPP Siskeudes-Link / CMS Bank
Route::get('/siskeudes/spp/status', [TicketReportController::class, 'checkSppStatus']);
Route::get('/v1/siskeudes/spp/status', [TicketReportController::class, 'checkSppStatus']);

// 3. Internal Chatbot API (Protected by Internal API Key)
Route::middleware([VerifyInternalApiKey::class])->prefix('v1')->group(function () {
    // Intent Manifest & Capabilities
    Route::get('/ai/manifest', [AiCapabilityController::class, 'manifest']);

    // Multi-turn Conversation Memory (Bebas PII & Terisolasi)
    Route::get('/conversations/{conversationId}/messages', [ConversationMemoryController::class, 'getMessages']);
    Route::post('/conversations/{conversationId}/messages', [ConversationMemoryController::class, 'storeMessage']);

    // Tiket Kendala & Feedback Pelanggan (Slack, Telegram, WhatsApp & Jira Service Desk)
    Route::post('/tickets', [TicketReportController::class, 'store']);
    Route::get('/tickets', [TicketReportController::class, 'index']);
    Route::get('/tickets/{ticketNumber}', [TicketReportController::class, 'show']);
    Route::patch('/tickets/{ticketNumber}/status', [TicketReportController::class, 'updateStatus']);
    Route::match(['get', 'post'], '/tickets/{ticketNumber}/jira-sync', [TicketReportController::class, 'syncJiraStatus']);

    // Laporan Eksekutif: Grafik Chart Visual (Internal)
    Route::get('/reports/store-metrics/chart', [\App\Http\Controllers\Api\ReportExportController::class, 'getChartUrl']);

    // Integrasi Jira Service Management (Proyek SUP)
    Route::match(['get', 'post'], '/jira/test-connection', [TicketReportController::class, 'testJiraConnection']);

    // Knowledge Base CMS: Template Kasus & Solusi (PM / Consultant Portal)
    Route::get('/kb-templates', [\App\Http\Controllers\Api\KbTemplateController::class, 'index']);
    Route::post('/kb-templates', [\App\Http\Controllers\Api\KbTemplateController::class, 'store']);
    Route::post('/kb-templates/check-duplicate', [\App\Http\Controllers\Api\KbTemplateController::class, 'checkDuplicate']);
    Route::post('/kb-templates/sync', [\App\Http\Controllers\Api\KbTemplateController::class, 'syncChatbot']);
    Route::get('/kb-templates/{id}', [\App\Http\Controllers\Api\KbTemplateController::class, 'show']);
    Route::match(['put', 'patch', 'post'], '/kb-templates/{id}', [\App\Http\Controllers\Api\KbTemplateController::class, 'update']);
    Route::delete('/kb-templates/{id}', [\App\Http\Controllers\Api\KbTemplateController::class, 'destroy']);

    // Kategori Knowledge Base
    Route::get('/kb-categories', [\App\Http\Controllers\Api\KbCategoryController::class, 'index']);
    Route::post('/kb-categories', [\App\Http\Controllers\Api\KbCategoryController::class, 'store']);

    // Fitur Pengecekan & Pemantauan Token Gemini (TERPISAH)
    Route::prefix('ai/tokens')->group(function () {
        Route::get('/summary', [TokenUsageController::class, 'summary']);   // Cek sisa kuota & total terpakai
        Route::get('/keys', [TokenUsageController::class, 'keys']);         // Cek status rotasi & key pool
        Route::get('/history', [TokenUsageController::class, 'history']);   // Riwayat log pemakaian token
        Route::post('/count', [TokenUsageController::class, 'count']);      // Hitung perkiraan token dari teks
        Route::post('/record', [TokenUsageController::class, 'record']);    // Rekam pemakaian token dari n8n/eksternal
    });
});
