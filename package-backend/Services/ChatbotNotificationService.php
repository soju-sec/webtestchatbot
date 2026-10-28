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
     * Mengirim pesan teks umum ke nomor WhatsApp tujuan (misal: balasan pesan eksekutif).
     *
     * @param string $target Nomor WhatsApp tujuan (format 08... atau 628...)
     * @param string $message Teks pesan yang sudah diformat (WhatsApp Markdown)
     * @return array Status pengiriman ['success' => bool, 'message' => string]
     */
    public static function sendWhatsAppMessage(string $target, string $message, ?string $mediaUrl = null): array
    {
        $apiUrl = config('chatbot.executive_whatsapp.api_url', config('chatbot.notifications.whatsapp.api_url', env('WHATSAPP_API_URL', '')));
        $apiKey = config('chatbot.executive_whatsapp.api_key', config('chatbot.notifications.whatsapp.api_key', env('WHATSAPP_API_KEY', '')));

        if (empty($apiUrl)) {
            Log::info('[ChatbotNotificationService] WhatsApp API URL belum disetel di .env.');
            return ['success' => false, 'message' => 'WhatsApp API URL tidak disetel'];
        }

        // Auto-detect URL media gambar (QuickChart atau file image) dari pesan jika belum dispesifikasikan
        if (empty($mediaUrl)) {
            if (preg_match('/(https:\/\/quickchart\.io\/chart[^\s]+|https?:\/\/[^\s]+\.(?:png|jpg|jpeg|webp|gif))/i', $message, $m)) {
                $mediaUrl = $m[1];
                // Bersihkan teks link yang panjang agar pesan WhatsApp menjadi caption yang rapi
                $message = str_replace(
                    [
                        $mediaUrl,
                        "📊 *Pratinjau Grafik Visual:*\n",
                        "📊 *Pratinjau Grafik Visual:*",
                        "📈 *Pratinjau Grafik Visual:*\n",
                        "📈 *Pratinjau Grafik Visual:*"
                    ],
                    '',
                    $message
                );
                $message = preg_replace("/\n{3,}/", "\n\n", trim($message));
            }
        }

        // Normalisasi nomor target (hilangkan tanda +, -, spasi)
        $cleanTarget = preg_replace('/[^0-9]/', '', $target);

        try {
            $headers = ['Content-Type' => 'application/json'];
            $provider = config('chatbot.executive_whatsapp.provider', env('WHATSAPP_PROVIDER', 'fonnte'));
            if (!empty($apiKey)) {
                $headers['Authorization'] = ($provider === 'meta' && !\Illuminate\Support\Str::startsWith($apiKey, 'Bearer '))
                    ? "Bearer {$apiKey}"
                    : $apiKey;
                $headers['X-Api-Key']     = $apiKey;
            }

            // Payload multi-provider: kompatibel Fonnte, Wablas, dan generic gateway
            $payload = [
                'target'  => $cleanTarget,
                'phone'   => $cleanTarget,
                'to'      => $cleanTarget,
                'message' => $message,
            ];

            // Lampirkan URL gambar fisik jika tersedia
            if (!empty($mediaUrl)) {
                $payload['url'] = $mediaUrl;
            }

            $response = Http::withHeaders($headers)->timeout(15)->post($apiUrl, $payload);

            if ($response->successful()) {
                Log::info("[ChatbotNotificationService] Berhasil kirim pesan WhatsApp ke {$cleanTarget}");
                return ['success' => true, 'message' => 'Pesan WhatsApp berhasil dikirim', 'response' => $response->json()];
            }

            Log::warning("[ChatbotNotificationService] Gagal kirim pesan WhatsApp ke {$cleanTarget}: " . $response->body());
            return ['success' => false, 'message' => 'WhatsApp API error: ' . $response->status()];
        } catch (\Throwable $e) {
            Log::warning("[ChatbotNotificationService] Exception saat kirim pesan WhatsApp: " . $e->getMessage());
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
     * Mengirimkan Pesan WhatsApp #1: Tanda Terima Pelaporan & Magic Tracking Link ke Pelapor.
     * Hanya dikirim 1x saat tiket berhasil dibuat.
     *
     * @param array $ticket Data tiket lengkap
     * @param string $magicLink URL Magic Tracking Link pelapor
     * @return array Status pengiriman
     */
    public static function sendTicketReceiptToReporter(array $ticket, string $magicLink): array
    {
        $target = $ticket['reporter_phone'] ?? null;
        if (empty($target)) {
            return ['success' => false, 'message' => 'Nomor WhatsApp pelapor tidak tersedia'];
        }

        $ticketNumber = $ticket['ticket_number'] ?? 'TCK-BARU';
        $kabupaten    = $ticket['kabupaten'] ?? '-';
        $desa         = $ticket['nama_desa'] ?? '-';
        $tahun        = $ticket['tahun_anggaran'] ?? date('Y');
        $userName     = !empty($ticket['user_name']) && $ticket['user_name'] !== 'pelapor-web'
            ? $ticket['user_name']
            : ($desa !== '-' ? "Bpk/Ibu Kaur Keuangan Desa {$desa}" : "Bpk/Ibu Operator Siskeudes");
        $featureName  = $ticket['feature_name'] ?? 'Siskeudes-Link TNT / CMS Bank';
        $categoryName = self::formatCategory($ticket['category'] ?? 'siskeudes_tnt');
        $createdAt    = date('d M Y, H:i') . ' WIB';

        $msg = "🎫 *[TANDA TERIMA LAPORAN KENDALA SISKEUDES-LINK]*\n\n"
            . "Yth. *{$userName}*,\n"
            . "Laporan kendala transaksi non-tunai Anda telah berhasil dicatat ke dalam antrean penanganan teknis tim kami.\n\n"
            . "📋 *Rincian Laporan:*\n"
            . "• *No. Tiket*: `{$ticketNumber}`\n"
            . "• *Modul/Fitur*: {$featureName}\n"
            . "• *Kategori*: {$categoryName}\n";

        if ($kabupaten !== '-' || $desa !== '-') {
            $msg .= "• *Instansi*: Desa {$desa}, Kab. {$kabupaten} (TA {$tahun})\n";
        }
        if (!empty($ticket['no_spp'])) {
            $msg .= "• *No. SPP*: `{$ticket['no_spp']}`\n";
        }
        if (!empty($ticket['no_kwitansi'])) {
            $msg .= "• *No. Kwitansi*: `{$ticket['no_kwitansi']}`\n";
        }
        if (!empty($ticket['kode_cms_siskeudes'])) {
            $msg .= "• *Kode CMS SKD*: `{$ticket['kode_cms_siskeudes']}`\n";
        }

        $msg .= "• *Status*: *SEDANG DIANTREKAN (OPEN)*\n"
            . "• *Waktu*: {$createdAt}\n\n"
            . "🔍 *Pantau Progres Penanganan (Bebas Kuota WA):*\n"
            . "Untuk melihat timeline investigasi teknisi tanpa menunggu balasan chat, silakan buka tautan mandiri berikut:\n"
            . "👉 {$magicLink}\n\n"
            . "_(Atau Anda dapat mengeceknya kapan saja di live chat Siskeudes-Link dengan menyebutkan No. Tiket)_{$ticketNumber}_\n\n"
            . "ℹ️ *Pemberitahuan hasil penyelesaian akan dikirimkan otomatis ke WhatsApp ini begitu kendala terselesaikan oleh teknisi di Jira.*";

        return self::sendWhatsAppMessage($target, $msg);
    }

    /**
     * Mengirimkan Pesan WhatsApp #2: Hasil Penanganan Tiket Selesai (Solved / Resolved) ke Pelapor.
     * Dipicu otomatis oleh Webhook Jira saat issue diselesaikan (Status Done / Resolved).
     *
     * @param array $ticket Data tiket lengkap beserta resolution_notes
     * @return array Status pengiriman
     */
    public static function sendTicketResolvedToReporter(array $ticket): array
    {
        $target = $ticket['reporter_phone'] ?? null;
        if (empty($target)) {
            return ['success' => false, 'message' => 'Nomor WhatsApp pelapor tidak tersedia'];
        }

        $ticketNumber    = $ticket['ticket_number'] ?? 'TCK-BARU';
        $kabupaten       = $ticket['kabupaten'] ?? '-';
        $desa            = $ticket['nama_desa'] ?? '-';
        $tahun           = $ticket['tahun_anggaran'] ?? date('Y');
        $userName        = !empty($ticket['user_name']) && $ticket['user_name'] !== 'pelapor-web'
            ? $ticket['user_name']
            : ($desa !== '-' ? "Bpk/Ibu Kaur Keuangan Desa {$desa}" : "Bpk/Ibu Operator Siskeudes");
        $resolutionNotes = !empty($ticket['resolution_notes'])
            ? trim($ticket['resolution_notes'])
            : 'Kendala verifikasi / transaksi pada Siskeudes-Link TNT telah disesuaikan dan diverifikasi tuntas oleh tim teknis.';
        $resolvedAt      = date('d M Y, H:i') . ' WIB';

        $msg = "✅ *[HASIL PENANGANAN KENDALA SISKEUDES-LINK SELESAI]*\n\n"
            . "Yth. *{$userName}*,\n"
            . "Kabar baik! Laporan kendala transaksi Anda dengan nomor tiket *`{$ticketNumber}`* telah selesai ditindaklanjuti oleh tim teknisi kami.\n\n";

        if ($kabupaten !== '-' || $desa !== '-') {
            $msg .= "🏛️ *Desa / Wilayah*: Desa {$desa}, Kab. {$kabupaten} (TA {$tahun})\n";
        }

        $msg .= "📌 *Status Akhir*: *RESOLVED / SELESAI*\n"
            . "🕒 *Waktu Selesai*: {$resolvedAt}\n\n"
            . "🛠️ *Rincian Solusi & Tindak Lanjut:*\n"
            . "\"_{$resolutionNotes}_\"\n\n"
            . "Silakan lakukan verifikasi atau proses kembali transaksi pada aplikasi Siskeudes-Link / CMS Bank Desa Anda.\n"
            . "Terima kasih atas kerja sama dan laporannya! 🙏";

        return self::sendWhatsAppMessage($target, $msg);
    }

    /**
     * Format nama kategori agar mudah dibaca manusia.
     */
    protected static function formatCategory(string $category): string
    {
        $map = [
            'verifikasi_spp_upload'     => 'Verifikasi SPP & Upload Berkas SPTJB/SPP2',
            'spp_akun_merah'           => 'Status Rekening Akun Merah saat Verifikasi',
            'sinkronisasi_spp_online'   => 'Sinkronisasi SPP Final Siskeudes Online',
            'pajak_koreksi_id_billing' => 'Koreksi ID Billing Pajak & NTPN',
            'pembayaran_gagal_bank'     => 'Pembayaran Gagal / Reject CMS Bank',
            'inkonsistensi_kode_cms'    => 'Selisih Kode CMS Siskeudes TNT vs CMS Bank',
            'siskeudes_tnt'             => 'Kendala Siskeudes-Link TNT & CMS Bank',
            'fitur_tidak_dapat_diakses' => 'Fitur Tidak Dapat Diakses',
            'bug_error'                 => 'Bug / Kesalahan Sistem',
            'transaksi'                 => 'Kendala Transaksi / Pembayaran',
            'akun'                      => 'Kendala Akun / Login',
            'performa_lambat'           => 'Performa Sistem Lambat',
        ];

        return $map[$category] ?? ucwords(str_replace('_', ' ', $category));
    }
}

