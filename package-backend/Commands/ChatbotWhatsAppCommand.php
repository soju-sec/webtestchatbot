<?php

namespace App\Console\Commands;

use App\Services\ChatbotNotificationService;
use App\Services\WhatsAppFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ChatbotWhatsAppCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chatbot:whatsapp
                            {--test : Uji status konfigurasi WhatsApp Gateway dan Whitelist Eksekutif}
                            {--phone= : Nomor WhatsApp eksekutif yang digunakan untuk pengujian}
                            {--name= : Nama atau jabatan eksekutif (default: Direktur Eksekutif)}
                            {--message= : Teks pesan yang ingin diuji (misal: "Bagaimana performa toko bulan ini?")}
                            {--send-test= : Kirim pesan uji coba langsung ke nomor WhatsApp tujuan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kelola, uji, dan simulasikan alur WhatsApp Chatbot Khusus Eksekutif';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->newLine();
        $this->line('<fg=green;options=bold>==============================================================</>');
        $this->line('<fg=green;options=bold>   WHATSAPP CHATBOT KHUSUS EKSEKUTIF (EXECUTIVE ASSISTANT)    </>');
        $this->line('<fg=green;options=bold>==============================================================</>');
        $this->newLine();

        $apiUrl    = config('chatbot.executive_whatsapp.api_url', config('chatbot.notifications.whatsapp.api_url', env('WHATSAPP_API_URL', '')));
        $apiKey    = config('chatbot.executive_whatsapp.api_key', config('chatbot.notifications.whatsapp.api_key', env('WHATSAPP_API_KEY', '')));
        $provider  = config('chatbot.executive_whatsapp.provider', env('WHATSAPP_PROVIDER', 'fonnte'));
        $enabled   = config('chatbot.executive_whatsapp.enabled', env('WHATSAPP_EXECUTIVE_ENABLED', true));
        $reqWhite  = config('chatbot.executive_whatsapp.require_whitelist', env('WHATSAPP_EXECUTIVE_REQUIRE_WHITELIST', true));
        $whitelist = config('chatbot.executive_whatsapp.whitelist_numbers', env('WHATSAPP_EXECUTIVE_WHITELIST', ''));
        $directory = config('chatbot.executive_whatsapp.executive_directory', env('WHATSAPP_EXECUTIVE_DIRECTORY', ''));

        $this->table(
            ['Parameter Konfigurasi', 'Nilai / Status'],
            [
                ['Status Kanal Eksekutif', $enabled ? '<fg=green;options=bold>AKTIF</>' : '<fg=red>NONAKTIF</>'],
                ['WhatsApp Provider', "<options=bold>{$provider}</>"],
                ['WhatsApp API URL', !empty($apiUrl) ? $apiUrl : '<fg=yellow>(Belum disetel di .env)</>'],
                ['API Key / Token', !empty($apiKey) ? '<fg=green>TERPASANG (Masked)</>' : '<fg=yellow>(Belum diisi di .env)</>'],
                ['Wajib Whitelist (Security)', $reqWhite ? '<fg=cyan;options=bold>YA (Hanya Nomor Terdaftar)</>' : '<fg=yellow>TIDAK (Semua Nomor)</>'],
                ['Daftar Nomor Whitelist', !empty($whitelist) ? $whitelist : '<fg=yellow>(Kosong - Semua diblokir jika whitelist wajib)</>'],
                ['Direktori Jabatan Eksekutif', !empty($directory) ? $directory : '<fg=gray>(Default generic)</>'],
                ['Endpoint Ingress Webhook', url('/webhook/whatsapp-entry')],
            ]
        );

        // 1. Pengujian Pengiriman Pesan Langsung
        $sendTarget = $this->option('send-test');
        if (!empty($sendTarget)) {
            $this->newLine();
            $this->info("Mengirim pesan pengujian ke WhatsApp: {$sendTarget}...");
            $testMsg = WhatsAppFormatter::wrapExecutiveResponse(
                'Bapak/Ibu Pimpinan',
                "Ini adalah pesan pengujian konektivitas *WhatsApp Executive AI Assistant*.\nSistem terhubung aktif ke core orkestrator n8n dan database analitik."
            );

            $result = ChatbotNotificationService::sendWhatsAppMessage($sendTarget, $testMsg);
            if ($result['success']) {
                $this->info("✓ Pesan berhasil dikirim ke {$sendTarget}!");
            } else {
                $this->error("✗ Gagal mengirim pesan: " . ($result['message'] ?? 'Unknown error'));
            }
            return 0;
        }

        // 2. Simulasi Alur Chat WhatsApp Eksekutif (End-to-End melalui Flow yang Sama)
        $message = $this->option('message');
        if (!empty($message)) {
            $phone = $this->option('phone') ?: '628123456789';
            $name  = $this->option('name') ?: 'Budi Santoso (Direktur Operasional)';

            $this->newLine();
            $this->line("<fg=yellow;options=bold>--- SIMULASI CHAT WHATSAPP EKSEKUTIF ---</>");
            $this->line("📱 Nomor WA Pengirim : <options=bold>{$phone}</>");
            $this->line("👤 Profil Eksekutif   : <options=bold>{$name}</>");
            $this->line("💬 Pesan Masuk        : \"{$message}\"");
            $this->newLine();

            $this->info("Memproses melalui alur yang sama (Anti-PII -> Memory -> Manifest -> Gemini -> BI Fulfillment)...");

            $n8nUrl  = config('chatbot.n8n.webhook_url', env('N8N_WEBHOOK_URL', 'http://127.0.0.1:5678/webhook/chat-entry'));
            $timeout = (int) config('chatbot.n8n.timeout_seconds', env('CHATBOT_TIMEOUT_SECONDS', 30));

            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

            $payload = [
                'conversation_id' => 'wa_' . $cleanPhone,
                'user_id'         => $cleanPhone,
                'user_name'       => $name,
                'message'         => $message,
                'channel'         => 'whatsapp',
                'role'            => 'executive',
            ];

            try {
                $startTime = microtime(true);
                $response = Http::timeout($timeout)->post($n8nUrl, $payload);
                $latency = round((microtime(true) - $startTime) * 1000, 2);

                if ($response->successful()) {
                    $data = $response->json();
                    $rawReply = $data['reply'] ?? '';
                    $waReply  = WhatsAppFormatter::format($rawReply);

                    $this->newLine();
                    $this->line("<fg=green;options=bold>✓ RESPON DITERIMA DARI AI ({$latency}ms):</>");
                    $this->line("<fg=cyan>Intent Terdeteksi</> : " . ($data['intent'] ?? '-'));
                    if (!empty($data['ticket_number'])) {
                        $this->line("<fg=yellow>Nomor Tiket (VIP)</>  : #" . $data['ticket_number']);
                    }
                    $this->newLine();
                    $this->line("<fg=white;options=bold>--- TAMPILAN PADA WHATSAPP EKSEKUTIF ---</>");
                    $this->line($waReply);
                    $this->line("<fg=white;options=bold>-----------------------------------------</>");
                } else {
                    $this->error("✗ HTTP Error dari orkestrator n8n: " . $response->status());
                    $this->line($response->body());
                }
            } catch (\Throwable $e) {
                $this->error("✗ Gagal menghubungi orkestrator n8n: " . $e->getMessage());
                $this->line("<fg=yellow>Pastikan container n8n aktif di http://localhost:5678</>");
            }

            return 0;
        }

        $this->newLine();
        $this->comment('Gunakan opsi:');
        $this->line('  --message="Teks pertanyaan" --phone="6281234..."  : Uji simulasi respon chat');
        $this->line('  --send-test="6281234..."                          : Uji kirim pesan fisik via gateway');
        $this->newLine();

        return 0;
    }
}
