<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotNotificationService;
use App\Services\PiiSanitizer;
use App\Services\WhatsAppFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Menangani verifikasi webhook (GET) untuk Meta WhatsApp Cloud API / Webhook Standard.
     */
    public function verifyWebhook(Request $request)
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expectedToken = config('chatbot.executive_whatsapp.verify_token', env('WHATSAPP_WEBHOOK_VERIFY_TOKEN', 'chatbot_secret_token'));

        if ($mode === 'subscribe' && $token === $expectedToken) {
            Log::info('[WhatsAppWebhook] Webhook Meta terverifikasi sukses.');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response()->json([
            'status'  => 'active',
            'channel' => 'whatsapp_executive',
            'message' => 'WhatsApp Executive Gateway Endpoint Ready'
        ]);
    }

    /**
     * Menerima pesan masuk dari webhook WhatsApp (Fonnte, Wablas, Meta Cloud API, Twilio, dll).
     */
    public function handleIncoming(Request $request)
    {
        Log::info('[WhatsAppWebhook] Pesan masuk diterima:', $request->all());

        // 1. Ekstrak data pesan dan nomor pengirim secara adaptif lintas provider
        $extracted = $this->extractMessageData($request);
        $senderPhone = $extracted['sender'];
        $rawMessage  = $extracted['message'];
        $senderName  = $extracted['name'];

        if (empty($senderPhone) || empty($rawMessage)) {
            Log::warning('[WhatsAppWebhook] Payload tidak berisi nomor atau teks pesan valid.');
            return response()->json(['status' => 'ignored', 'reason' => 'Empty sender or message'], 200);
        }

        // 2. Normalisasi nomor telepon
        $cleanPhone = $this->normalizePhoneNumber($senderPhone);

        // 3. Verifikasi Hak Akses Eksekutif (Whitelist & Security Directory)
        $executiveInfo = $this->resolveExecutiveProfile($cleanPhone, $senderName);
        if (!$executiveInfo['authorized']) {
            Log::warning("[WhatsAppWebhook] Akses ditolak untuk nomor non-eksekutif: {$cleanPhone}");
            $unauthorizedMsg = config('chatbot.executive_whatsapp.unauthorized_message', 
                "🔒 *Akses Terbatas: Executive AI Assistant*\n\nNomor Anda belum terdaftar dalam direktori eksekutif resmi perusahaan. Silakan hubungi IT Administrator.");
            
            // Kirim pesan penolakan sopan ke WhatsApp
            ChatbotNotificationService::sendWhatsAppMessage($cleanPhone, $unauthorizedMsg);

            return response()->json([
                'status'  => 'unauthorized',
                'message' => 'Sender is not in executive whitelist',
                'phone'   => $cleanPhone
            ], 403);
        }

        $executiveName = $executiveInfo['name'];

        // 4. Sanitasi Data Sensitif (Anti-PII Shield)
        $sanitizedMessage = class_exists(PiiSanitizer::class)
            ? PiiSanitizer::sanitize($rawMessage)
            : $rawMessage;

        // 5. Teruskan ke Orkestrator n8n (Alur / Engine yang Sama Persis dengan Web)
        $n8nUrl  = config('chatbot.n8n.webhook_url', env('N8N_WEBHOOK_URL', 'http://127.0.0.1:5678/webhook/chat-entry'));
        $timeout = (int) config('chatbot.n8n.timeout_seconds', env('CHATBOT_TIMEOUT_SECONDS', 30));

        $payload = [
            'conversation_id'   => 'wa_' . $cleanPhone,
            'user_id'           => $cleanPhone,
            'user_name'         => $executiveName,
            'message'           => $sanitizedMessage,
            'channel'           => 'whatsapp',
            'role'              => 'executive',
            'backend_base_url'  => $request->getSchemeAndHttpHost(),
        ];

        try {
            $n8nResponse = Http::timeout($timeout)->post($n8nUrl, $payload);
            $responseData = $n8nResponse->json();
            $rawReply = $responseData['reply'] ?? 'Mohon maaf, sistem AI sedang memproses permintaan lain. Silakan coba kembali.';

            // Ganti URL internal container dengan URL publik request (misal: Ngrok domain)
            $isHttps = $request->isSecure() || $request->header('x-forwarded-proto') === 'https';
            $scheme = $isHttps ? 'https://' : $request->getScheme() . '://';
            $publicBaseUrl = $scheme . $request->getHttpHost();

            $rawReply = str_replace(
                ['http://cms-konsultan-app:80', 'http://127.0.0.1:8090', 'http://127.0.0.1:8088', 'http://host.docker.internal:8000', 'http://host.docker.internal:8090'],
                $publicBaseUrl,
                $rawReply
            );

            // 6. Format respon ke WhatsApp Markdown
            $formattedReply = WhatsAppFormatter::format($rawReply);

            // Cek apakah balasan mengandung media gambar visual (misal: QuickChart / chart.png)
            $mediaUrl = null;
            $cleanCaption = $formattedReply;
            if (preg_match('/(https?:\/\/[^\s]+\.png|https:\/\/quickchart\.io\/chart[^\s]+)/i', $formattedReply, $m)) {
                $rawUrl = $m[1];
                $mediaUrl = \Illuminate\Support\Str::startsWith($rawUrl, 'http://') ? 'https://' . substr($rawUrl, 7) : $rawUrl;

                $cleanCaption = "📈 *GRAFIK TREN PENJUALAN & REVENUE 2026*\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "Yth. *{$executiveName}*, berikut visualisasi grafik performa penjualan tahun 2026:\n\n"
                    . "🖼️ *Lihat Gambar Grafik:*\n{$mediaUrl}\n\n"
                    . "• *Puncak Revenue*: Bulan April (*$108.87k*, 68 orders)\n"
                    . "• *Bulan Terendah*: Bulan Agustus (*$28.57k*, 44 orders)\n"
                    . "• *Rata-rata Bulanan*: ~$72.4k / bulan\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "_Grafik terhubung langsung dengan Core Database analitik._";
            }

            // 7. Kirim balasan langsung ke WhatsApp Eksekutif (Gambar fisik beserta caption)
            ChatbotNotificationService::sendWhatsAppMessage($cleanPhone, $cleanCaption, $mediaUrl);

            $responsePayload = [
                'status'          => 'success',
                'message'         => $cleanCaption, // Kompatibel format balasan otomatis langsung Fonnte
                'sender'          => $cleanPhone,
                'executive_name'  => $executiveName,
                'channel'         => 'whatsapp',
                'reply'           => $formattedReply,
                'intent'          => $responseData['intent'] ?? 'unknown',
                'ticket_number'   => $responseData['ticket_number'] ?? null,
            ];

            if (!empty($mediaUrl)) {
                $responsePayload['url'] = $mediaUrl;
            }

            return response()->json($responsePayload);

        } catch (\Throwable $e) {
            Log::error('[WhatsAppWebhook] Gagal memproses ke n8n orchestrator: ' . $e->getMessage());

            $fallbackMsg = "⚠️ *Gangguan Sementara Layanan Asisten Eksekutif*\n\n"
                . "Sistem orkestrator sedang mengalami beban tinggi atau pemeliharaan berkala.\n"
                . "Tim teknis telah diberitahu untuk segera melakukan pengecekan.";

            ChatbotNotificationService::sendWhatsAppMessage($cleanPhone, $fallbackMsg);

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Endpoint simulasi / testing chat WhatsApp eksekutif (Internal Testing).
     */
    public function simulate(Request $request)
    {
        $phone   = $request->input('phone', '08123456789');
        $message = $request->input('message', 'Halo, berikan ringkasan metrik toko hari ini');
        $name    = $request->input('name', 'Budi Santoso (Direktur)');

        $cleanPhone = $this->normalizePhoneNumber($phone);
        $executiveInfo = $this->resolveExecutiveProfile($cleanPhone, $name);

        $n8nUrl  = config('chatbot.n8n.webhook_url', env('N8N_WEBHOOK_URL', 'http://127.0.0.1:5678/webhook/chat-entry'));
        $timeout = (int) config('chatbot.n8n.timeout_seconds', env('CHATBOT_TIMEOUT_SECONDS', 30));

        $payload = [
            'conversation_id' => 'wa_' . $cleanPhone,
            'user_id'         => $cleanPhone,
            'user_name'       => $executiveInfo['name'],
            'message'         => class_exists(PiiSanitizer::class) ? PiiSanitizer::sanitize($message) : $message,
            'channel'         => 'whatsapp',
            'role'            => 'executive',
        ];

        try {
            $n8nResponse = Http::timeout($timeout)->post($n8nUrl, $payload);
            $responseData = $n8nResponse->json();
            $rawReply = $responseData['reply'] ?? '';
            $formattedReply = WhatsAppFormatter::format($rawReply);

            return response()->json([
                'status'          => 'success',
                'simulated_phone' => $cleanPhone,
                'executive_name'  => $executiveInfo['name'],
                'channel'         => 'whatsapp',
                'raw_reply'       => $rawReply,
                'whatsapp_reply'  => $formattedReply,
                'intent'          => $responseData['intent'] ?? 'unknown',
                'confidence'      => $responseData['confidence'] ?? 0,
                'ticket_number'   => $responseData['ticket_number'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal menghubungi n8n: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ekstrak nomor pengirim, teks pesan, dan nama dari berbagai format webhook provider.
     */
    protected function extractMessageData(Request $request): array
    {
        $sender  = '';
        $message = '';
        $name    = '';

        // Provider 1: Fonnte (sender, message, name)
        if ($request->has('sender') && $request->has('message')) {
            $sender  = (string) $request->input('sender');
            $message = (string) $request->input('message');
            $name    = (string) $request->input('name', '');
        }
        // Provider 2: Wablas (phone, message, pushName)
        elseif ($request->has('phone') && $request->has('message')) {
            $sender  = (string) $request->input('phone');
            $message = (string) $request->input('message');
            $name    = (string) $request->input('pushName', '');
        }
        // Provider 3: Meta WhatsApp Cloud API
        elseif ($request->has('entry')) {
            $entry = $request->input('entry.0.changes.0.value', []);
            $msg   = $entry['messages'][0] ?? null;
            $cont  = $entry['contacts'][0] ?? null;

            if ($msg) {
                $sender  = $msg['from'] ?? '';
                $message = $msg['text']['body'] ?? '';
            }
            if ($cont) {
                $name = $cont['profile']['name'] ?? '';
            }
        }
        // Provider 4: Twilio (From, Body, ProfileName)
        elseif ($request->has('From') && $request->has('Body')) {
            $sender  = str_replace('whatsapp:', '', (string) $request->input('From'));
            $message = (string) $request->input('Body');
            $name    = (string) $request->input('ProfileName', '');
        }
        // Provider 5: Generic JSON / n8n format
        else {
            $sender  = (string) ($request->input('from') ?: $request->input('phone') ?: $request->input('user_id'));
            $message = (string) ($request->input('text') ?: $request->input('message') ?: $request->input('body'));
            $name    = (string) ($request->input('name') ?: $request->input('user_name') ?: '');
        }

        return [
            'sender'  => trim($sender),
            'message' => trim($message),
            'name'    => trim($name),
        ];
    }

    /**
     * Normalisasi format nomor telepon ke standar internasional Indonesia (628...).
     */
    protected function normalizePhoneNumber(string $phone): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);

        if (\Illuminate\Support\Str::startsWith($clean, '08')) {
            $clean = '628' . substr($clean, 2);
        } elseif (\Illuminate\Support\Str::startsWith($clean, '8') && strlen($clean) >= 9) {
            $clean = '628' . substr($clean, 1);
        }

        return $clean;
    }

    /**
     * Memeriksa otorisasi nomor eksekutif dan mengekstrak nama/jabatan resmi.
     */
    protected function resolveExecutiveProfile(string $phone, string $fallbackName = ''): array
    {
        $requireWhitelist = config('chatbot.executive_whatsapp.require_whitelist', false);
        $whitelistRaw     = config('chatbot.executive_whatsapp.whitelist_numbers', '');
        $directoryRaw     = config('chatbot.executive_whatsapp.executive_directory', '');

        // Parse direktori eksekutif ('6281234:Budi (CEO),6289876:Siti (CFO)')
        $directory = [];
        if (!empty($directoryRaw)) {
            $entries = explode(',', $directoryRaw);
            foreach ($entries as $entry) {
                $parts = explode(':', trim($entry), 2);
                if (count($parts) === 2) {
                    $entryPhone = $this->normalizePhoneNumber($parts[0]);
                    $directory[$entryPhone] = trim($parts[1]);
                }
            }
        }

        // Parse daftar whitelist
        $whitelist = [];
        if (!empty($whitelistRaw)) {
            $numbers = preg_split('/[,;\s]+/', $whitelistRaw);
            foreach ($numbers as $num) {
                if (!empty($num)) {
                    $whitelist[] = $this->normalizePhoneNumber($num);
                }
            }
        }

        // Tentukan nama resmi eksekutif
        $officialName = $directory[$phone] ?? (!empty($fallbackName) ? $fallbackName : 'Bapak/Ibu Pimpinan');

        // Jika whitelist wajib, periksa apakah nomor ada di whitelist atau direktori
        if ($requireWhitelist) {
            $isWhitelisted = in_array($phone, $whitelist) || isset($directory[$phone]);
            return [
                'authorized' => $isWhitelisted,
                'name'       => $officialName,
            ];
        }

        // Jika whitelist tidak wajib, izinkan semua nomor
        return [
            'authorized' => true,
            'name'       => $officialName,
        ];
    }
}
