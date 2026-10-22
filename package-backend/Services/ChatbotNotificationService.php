<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotNotificationService
{
    /**
     * Mengirim notifikasi tiket gangguan ke Telegram Tim Internal.
     * Menggunakan Telegram Bot API sendMessage.
     *
     * @param array $ticket Data tiket lengkap
     * @return array Status pengiriman ['success' => bool, 'message' => string]
     */
    public static function sendTelegramTicketAlert(array $ticket): array
    {
        $botToken = config('chatbot.notifications.telegram.bot_token', env('TELEGRAM_BOT_TOKEN', ''));
        $chatId   = config('chatbot.notifications.telegram.chat_id', env('TELEGRAM_CHAT_ID', ''));
        $enabled  = config('chatbot.notifications.telegram.enabled', env('TELEGRAM_NOTIFICATIONS_ENABLED', true));

        if (!$enabled) {
            return ['success' => false, 'message' => 'Notifikasi Telegram dinonaktifkan'];
        }

        if (empty($botToken) || empty($chatId)) {
            Log::info('[ChatbotNotificationService] Telegram bot_token atau chat_id belum diisi di .env. Notifikasi di-skip.');
            return ['success' => false, 'message' => 'Telegram credentials tidak disetel'];
        }

        $ticketNumber = $ticket['ticket_number'] ?? 'TCK-' . strtoupper(substr(uniqid(), -6));
        $userId       = $ticket['user_id'] ?? 'guest';
        $userName     = !empty($ticket['user_name']) ? " ({$ticket['user_name']})" : "";
        $category     = self::formatCategory($ticket['category'] ?? 'fitur_tidak_dapat_diakses');
        $featureName  = $ticket['feature_name'] ?? 'Fitur Sistem';
        $description  = $ticket['description'] ?? 'Tidak ada detail';
        $urgency      = strtoupper($ticket['urgency'] ?? 'TINGGI');
        $conversation = $ticket['conversation_id'] ?? '-';
        $createdAt    = date('Y-m-d H:i:s') . ' WIB';

        // Emoji urgensi
        $urgencyEmoji = '⚠️';
        if ($urgency === 'DARURAT' || $urgency === 'CRITICAL') {
            $urgencyEmoji = '🔥';
        } elseif ($urgency === 'TINGGI' || $urgency === 'HIGH') {
            $urgencyEmoji = '🚨';
        } elseif ($urgency === 'SEDANG' || $urgency === 'MEDIUM') {
            $urgencyEmoji = '⚡';
        }

        $message = "🚨 *TIKET GANGGUAN BARU DARI PELANGGAN*\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "🎫 *Nomor Tiket*: `{$ticketNumber}`\n";
        $message .= "👤 *Pelapor*: `{$userId}`{$userName}\n";
        $message .= "📋 *Kategori*: {$category}\n";
        $message .= "🛠 *Fitur Terkendala*: *{$featureName}*\n";
        $message .= "{$urgencyEmoji} *Tingkat Urgensi*: *{$urgency}*\n\n";
        $message .= "📝 *Deskripsi Kendala*:\n\"{$description}\"\n\n";
        $message .= "💬 *ID Percakapan*: `{$conversation}`\n";
        $message .= "🕒 *Waktu Laporan*: {$createdAt}\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "👉 _Mohon tim internal segera memeriksa dan menindaklanjuti kendala ini._";

        try {
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
            $response = Http::timeout(10)->post($url, [
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'Markdown',
            ]);

            if ($response->successful()) {
                Log::info("[ChatbotNotificationService] Berhasil kirim notifikasi Telegram untuk tiket {$ticketNumber}");
                return ['success' => true, 'message' => 'Notifikasi Telegram berhasil dikirim'];
            }

            Log::warning("[ChatbotNotificationService] Gagal kirim Telegram: " . $response->body());
            return ['success' => false, 'message' => 'Telegram API response error: ' . $response->status()];
        } catch (\Throwable $e) {
            Log::warning("[ChatbotNotificationService] Exception saat kirim Telegram: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Mengirim notifikasi tiket gangguan ke WhatsApp Tim Internal via Webhook/API Gateway.
     *
     * @param array $ticket Data tiket lengkap
     * @return array Status pengiriman ['success' => bool, 'message' => string]
     */
    public static function sendWhatsAppTicketAlert(array $ticket): array
    {
        $apiUrl   = config('chatbot.notifications.whatsapp.api_url', env('WHATSAPP_API_URL', ''));
        $apiKey   = config('chatbot.notifications.whatsapp.api_key', env('WHATSAPP_API_KEY', ''));
        $target   = config('chatbot.notifications.whatsapp.target_number', env('WHATSAPP_TARGET_NUMBER', ''));
        $enabled  = config('chatbot.notifications.whatsapp.enabled', env('WHATSAPP_NOTIFICATIONS_ENABLED', false));

        if (!$enabled) {
            return ['success' => false, 'message' => 'Notifikasi WhatsApp dinonaktifkan'];
        }

        if (empty($apiUrl)) {
            Log::info('[ChatbotNotificationService] WhatsApp api_url belum disetel di .env. Notifikasi di-skip.');
            return ['success' => false, 'message' => 'WhatsApp API URL tidak disetel'];
        }

        $ticketNumber = $ticket['ticket_number'] ?? 'TCK-' . strtoupper(substr(uniqid(), -6));
        $userId       = $ticket['user_id'] ?? 'guest';
        $userName     = !empty($ticket['user_name']) ? " ({$ticket['user_name']})" : "";
        $category     = self::formatCategory($ticket['category'] ?? 'fitur_tidak_dapat_diakses');
        $featureName  = $ticket['feature_name'] ?? 'Fitur Sistem';
        $description  = $ticket['description'] ?? 'Tidak ada detail';
        $urgency      = strtoupper($ticket['urgency'] ?? 'TINGGI');
        $createdAt    = date('Y-m-d H:i:s') . ' WIB';

        $textMessage = "*[TIKET GANGGUAN SISTEM BARU]*\n\n"
            . "*No. Tiket*: {$ticketNumber}\n"
            . "*User*: {$userId}{$userName}\n"
            . "*Kategori*: {$category}\n"
            . "*Fitur Terkendala*: {$featureName}\n"
            . "*Urgensi*: {$urgency}\n\n"
            . "*Detail Masalah*:\n{$description}\n\n"
            . "*Waktu*: {$createdAt}\n"
            . "_Segera tindak lanjuti keluhan pelanggan ini._";

        try {
            $headers = ['Content-Type' => 'application/json'];
            if (!empty($apiKey)) {
                $headers['Authorization'] = "Bearer {$apiKey}";
                $headers['X-Api-Key']     = $apiKey;
            }

            $payload = [
                'target'        => $target,
                'phone'         => $target,
                'message'       => $textMessage,
                'ticket_number' => $ticketNumber,
                'ticket_data'   => $ticket,
            ];

            $response = Http::withHeaders($headers)->timeout(10)->post($apiUrl, $payload);

            if ($response->successful()) {
                Log::info("[ChatbotNotificationService] Berhasil kirim notifikasi WhatsApp untuk tiket {$ticketNumber}");
                return ['success' => true, 'message' => 'Notifikasi WhatsApp berhasil dikirim'];
            }

            Log::warning("[ChatbotNotificationService] Gagal kirim WhatsApp: " . $response->body());
            return ['success' => false, 'message' => 'WhatsApp API error: ' . $response->status()];
        } catch (\Throwable $e) {
            Log::warning("[ChatbotNotificationService] Exception saat kirim WhatsApp: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Dispatch notifikasi ke semua channel internal (Telegram & WhatsApp).
     *
     * @param array $ticket Data tiket
     * @return array Status per channel
     */
    public static function notifyAll(array $ticket): array
    {
        $telegramResult = self::sendTelegramTicketAlert($ticket);
        $whatsappResult = self::sendWhatsAppTicketAlert($ticket);

        return [
            'telegram' => $telegramResult['success'] ?? false,
            'whatsapp' => $whatsappResult['success'] ?? false,
            'details'  => [
                'telegram' => $telegramResult,
                'whatsapp' => $whatsappResult,
            ]
        ];
    }

    /**
     * Format nama kategori agar mudah dibaca manusia.
     */
    protected static function formatCategory(string $category): string
    {
        return match ($category) {
            'fitur_tidak_dapat_diakses' => 'Fitur Tidak Dapat Diakses',
            'bug_error'                 => 'Bug / Kesalahan Sistem',
            'transaksi'                 => 'Kendala Transaksi / Pembayaran',
            'akun'                      => 'Kendala Akun / Login',
            'performa_lambat'           => 'Performa Sistem Lambat',
            default                     => ucwords(str_replace('_', ' ', $category)),
        };
    }
}
