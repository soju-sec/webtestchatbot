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

        $jiraKey      = $ticket['jira_key'] ?? null;
        $jiraUrl      = $ticket['jira_url'] ?? null;

        $message = "🚨 *TIKET GANGGUAN BARU DARI PELANGGAN*\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "🎫 *Nomor Tiket*: `{$ticketNumber}`\n";
        if (!empty($jiraKey) && !empty($jiraUrl)) {
            $message .= "🌐 *Jira Service Desk*: [{$jiraKey}]({$jiraUrl})\n";
        }
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

        $jiraKey      = $ticket['jira_key'] ?? null;
        $jiraUrl      = $ticket['jira_url'] ?? null;

        $textMessage = "*[TIKET GANGGUAN SISTEM BARU]*\n\n"
            . "*No. Tiket*: {$ticketNumber}\n";
        if (!empty($jiraKey) && !empty($jiraUrl)) {
            $textMessage .= "*Jira Ticket*: {$jiraKey} ({$jiraUrl})\n";
        }
        $textMessage .= "*User*: {$userId}{$userName}\n"
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
     * Mengirim notifikasi tiket gangguan ke Slack Channel Tim Internal.
     * Menggunakan Slack Incoming Webhook dengan layout Block Kit yang sangat rapi dan tertata.
     *
     * @param array $ticket Data tiket lengkap
     * @return array Status pengiriman ['success' => bool, 'message' => string]
     */
    public static function sendSlackTicketAlert(array $ticket): array
    {
        $webhookUrl = config('chatbot.notifications.slack.webhook_url', env('SLACK_WEBHOOK_URL', ''));
        $channel    = config('chatbot.notifications.slack.channel', env('SLACK_CHANNEL', '#customer-tickets'));
        $enabled    = config('chatbot.notifications.slack.enabled', env('SLACK_NOTIFICATIONS_ENABLED', true));

        if (!$enabled) {
            return ['success' => false, 'message' => 'Notifikasi Slack dinonaktifkan'];
        }

        if (empty($webhookUrl)) {
            Log::info('[ChatbotNotificationService] Slack webhook_url belum disetel di .env. Notifikasi di-skip.');
            return ['success' => false, 'message' => 'Slack webhook URL tidak disetel'];
        }

        $ticketNumber = $ticket['ticket_number'] ?? 'TCK-' . strtoupper(substr(uniqid(), -6));
        $userId       = $ticket['user_id'] ?? 'guest';
        $userName     = !empty($ticket['user_name']) ? " ({$ticket['user_name']})" : "";
        $category     = self::formatCategory($ticket['category'] ?? 'fitur_tidak_dapat_diakses');
        $featureName  = $ticket['feature_name'] ?? 'Fitur Sistem';
        $description  = $ticket['description'] ?? 'Tidak ada detail';
        $urgency      = strtoupper($ticket['urgency'] ?? 'TINGGI');
        $conversation = $ticket['conversation_id'] ?? '-';
        $channelSrc   = $ticket['channel'] ?? 'web';
        $createdAt    = date('Y-m-d H:i:s') . ' WIB';

        // Penentuan warna border bar & emoji urgensi Slack
        $urgencyEmoji = '⚠️';
        $barColor     = '#ea580c'; // default orange (tinggi)
        if ($urgency === 'DARURAT' || $urgency === 'CRITICAL') {
            $urgencyEmoji = '🔥';
            $barColor     = '#e11d48'; // red
        } elseif ($urgency === 'TINGGI' || $urgency === 'HIGH') {
            $urgencyEmoji = '🚨';
            $barColor     = '#ea580c'; // orange
        } elseif ($urgency === 'SEDANG' || $urgency === 'MEDIUM') {
            $urgencyEmoji = '⚡';
            $barColor     = '#eab308'; // yellow
        } else {
            $barColor     = '#2563eb'; // blue
        }

        $jiraKey      = $ticket['jira_key'] ?? null;
        $jiraUrl      = $ticket['jira_url'] ?? null;

        $ticketField = "*🎫 No. Tiket:*\n`#{$ticketNumber}`";
        if (!empty($jiraKey) && !empty($jiraUrl)) {
            $ticketField = "*🎫 No. Tiket:*\n`#{$ticketNumber}`\n*🌐 Jira:* <{$jiraUrl}|{$jiraKey}>";
        }

        // Susun payload Block Kit Slack yang rapi & terstruktur
        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type'  => 'plain_text',
                    'text'  => '🚨 Tiket Gangguan Baru dari Pelanggan',
                    'emoji' => true
                ]
            ],
            [
                'type'   => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => $ticketField
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*👤 Pelapor:*\n`{$userId}`{$userName}"
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*📋 Kategori:*\n{$category}"
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*🛠 Fitur Terkendala:*\n*{$featureName}*"
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*{$urgencyEmoji} Urgensi:*\n*{$urgency}*"
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*🕒 Waktu Laporan:*\n{$createdAt}"
                    ]
                ]
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*📝 Detail Kendala:*\n>{$description}"
                ]
            ]
        ];

        // Jika tiket terdaftar di Jira Service Desk, tambahkan section khusus dengan tombol direct ke Jira
        if (!empty($jiraKey) && !empty($jiraUrl)) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*🎯 Jira Service Management (SUP):*\nTiket berhasil diteruskan ke Jira Service Desk: *<{$jiraUrl}|{$jiraKey}>*"
                ],
                'accessory' => [
                    'type' => 'button',
                    'text' => [
                        'type'  => 'plain_text',
                        'text'  => 'Buka di Jira ↗',
                        'emoji' => true
                    ],
                    'url'   => $jiraUrl,
                    'action_id' => 'btn_open_jira'
                ]
            ];
        }

        $blocks[] = [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => "💬 *ID Percakapan:* `{$conversation}` | *Kanal:* `{$channelSrc}` | *Status:* `OPEN` | _Tindak lanjuti kendala ini pada thread chat._"
                ]
            ]
        ];

        $payload = [
            'channel' => $channel,
            'text'    => "🚨 [TIKET GANGGUAN BARU] #{$ticketNumber} - Fitur: {$featureName} ({$urgency})" . (!empty($jiraKey) ? " [Jira: {$jiraKey}]" : ''),
            'attachments' => [
                [
                    'color'  => $barColor,
                    'blocks' => $blocks
                ]
            ]
        ];

        try {
            $response = Http::timeout(10)->post($webhookUrl, $payload);

            if ($response->successful()) {
                Log::info("[ChatbotNotificationService] Berhasil kirim notifikasi Slack untuk tiket {$ticketNumber}");
                return ['success' => true, 'message' => 'Notifikasi Slack berhasil dikirim'];
            }

            Log::warning("[ChatbotNotificationService] Gagal kirim Slack: " . $response->body());
            return ['success' => false, 'message' => 'Slack Webhook error: ' . $response->status()];
        } catch (\Throwable $e) {
            Log::warning("[ChatbotNotificationService] Exception saat kirim Slack: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Dispatch notifikasi ke semua channel internal yang aktif (Slack, Telegram, WhatsApp).
     *
     * @param array $ticket Data tiket
     * @return array Status per channel
     */
    public static function notifyAll(array $ticket): array
    {
        $slackResult    = self::sendSlackTicketAlert($ticket);
        $telegramResult = self::sendTelegramTicketAlert($ticket);
        $whatsappResult = self::sendWhatsAppTicketAlert($ticket);

        return [
            'slack'    => $slackResult['success'] ?? false,
            'telegram' => $telegramResult['success'] ?? false,
            'whatsapp' => $whatsappResult['success'] ?? false,
            'details'  => [
                'slack'    => $slackResult,
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
