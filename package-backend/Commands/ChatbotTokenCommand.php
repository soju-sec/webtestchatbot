<?php

namespace App\Console\Commands;

use App\Services\ChatbotTokenService;
use Illuminate\Console\Command;

class ChatbotTokenCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chatbot:tokens 
                            {--history : Tampilkan riwayat 10 transaksi penggunaan token terakhir}
                            {--check= : Hitung estimasi jumlah token untuk teks tertentu}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pantau penggunaan token Gemini AI dan sisa kuota bulanan (Terpisah)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>==============================================================</>');
        $this->line('<fg=cyan;options=bold>   GEMINI AI TOKEN USAGE & REMAINING QUOTA MONITOR (ISOLATED) </>');
        $this->line('<fg=cyan;options=bold>==============================================================</>');
        $this->newLine();

        // 1. Opsi Cek Token Teks
        $checkText = $this->option('check');
        if (!empty($checkText)) {
            $this->info("Menghitung estimasi token untuk teks:");
            $this->line("<comment>\"{$checkText}\"</comment>");
            $countResult = ChatbotTokenService::countTokens($checkText);
            
            $this->table(
                ['Parameter', 'Nilai'],
                [
                    ['Total Token', $countResult['total_tokens'] ?? '-'],
                    ['Jumlah Karakter', $countResult['char_count'] ?? '-'],
                    ['Model Target', $countResult['model'] ?? '-'],
                    ['Metode Perhitungan', $countResult['method'] ?? '-'],
                ]
            );
            return 0;
        }

        // 2. Ambil Ringkasan Token Terpakai & Sisa Kuota
        $summary = ChatbotTokenService::getSummary();
        $quota = $summary['quota'];
        $usage = $summary['usage'];
        $limits = $summary['model_limits'];

        $statusColor = match ($quota['status']) {
            'safe'      => 'green',
            'warning'   => 'yellow',
            'exceeded'  => 'red',
            default     => 'blue',
        };

        $statusEmoji = match ($quota['status']) {
            'safe'      => '🟢',
            'warning'   => '🟡',
            'exceeded'  => '🔴',
            default     => 'ℹ️',
        };

        $keyPool = $summary['key_pool'] ?? [];
        $keyCount = $keyPool['total_keys'] ?? 1;
        $keyStrategy = $keyPool['rotation_strategy'] ?? 'Single Key';
        $keysList = array_map(fn($k) => $k['masked'], $keyPool['keys'] ?? []);
        $keysString = !empty($keysList) ? implode(', ', $keysList) : '-';

        $this->line("🤖 <fg=white;options=bold>Model:</> {$summary['model']}  |  <fg=white;options=bold>Database:</> {$summary['database']['connection']} ({$summary['database']['table']})");
        $this->line("🔑 <fg=white;options=bold>API Key Pool:</> <fg=cyan;options=bold>{$keyCount} Key Aktif</> ({$keyStrategy})");
        $this->line("   <fg=gray>Daftar Key: {$keysString}</>");
        $this->line("   <fg=gray>Kapasitas Gabungan: {$limits['combined_rpm']} | {$limits['combined_rpd']} | {$limits['combined_tpm']}</>");
        $this->line("{$statusEmoji} <fg=white;options=bold>Status Kuota:</> <fg={$statusColor};options=bold>{$quota['status_label']}</> ({$quota['used_percentage']}% terpakai)");
        $this->newLine();

        // Ringkasan Kuota & Sisa Token
        $this->table(
            ['Metrik Token Bulanan (Gabungan Pool)', 'Jumlah Token'],
            [
                ['Batas Kuota Bulanan (Total Pool)', number_format($quota['monthly_limit']) . ' token (' . $keyCount . ' key x ' . number_format($quota['base_limit_per_key'] ?? 1000000) . ')'],
                ['Total Token Terpakai (Bulan Ini)', number_format($quota['used_this_month']) . ' token'],
                ['Estimasi SISA TOKEN', '<fg=' . $statusColor . ';options=bold>' . number_format($quota['remaining_tokens'] ?? 0) . ' token</>'],
                ['Total Pemanggilan (Requests)', number_format($usage['this_month']['requests']) . ' panggilan'],
                ['Rata-rata Token per Request', number_format($usage['avg_tokens_per_request'], 1) . ' token'],
            ]
        );

        $this->newLine();
        $this->line("<fg=yellow;options=bold>Breakdown Pemakaian Token:</>");
        $this->table(
            ['Periode', 'Total Token', 'Input (Prompt)', 'Output (Completion)', 'Requests'],
            [
                [
                    'Hari Ini',
                    number_format($usage['today']['total']),
                    number_format($usage['today']['prompt']),
                    number_format($usage['today']['completion']),
                    number_format($usage['today']['requests']),
                ],
                [
                    'Bulan Ini',
                    number_format($usage['this_month']['total']),
                    number_format($usage['this_month']['prompt']),
                    number_format($usage['this_month']['completion']),
                    number_format($usage['this_month']['requests']),
                ],
                [
                    'Sepanjang Waktu (All Time)',
                    number_format($usage['all_time']['total']),
                    number_format($usage['all_time']['prompt']),
                    number_format($usage['all_time']['completion']),
                    number_format($usage['all_time']['requests']),
                ],
            ]
        );

        // 3. Opsi Riwayat Transaksi
        if ($this->option('history')) {
            $this->newLine();
            $this->line("<fg=yellow;options=bold>10 Riwayat Percakapan Terakhir:</>");
            $history = ChatbotTokenService::getHistory(10, 0);

            if (empty($history['data']) || count($history['data']) === 0) {
                $this->line("Belum ada riwayat penggunaan token yang tercatat.");
            } else {
                $rows = [];
                foreach ($history['data'] as $item) {
                    $rows[] = [
                        $item->id,
                        $item->conversation_id ?? '-',
                        $item->user_id ?? '-',
                        number_format($item->prompt_tokens),
                        number_format($item->completion_tokens),
                        number_format($item->total_tokens),
                        $item->created_at,
                    ];
                }
                $this->table(['ID', 'Conversation ID', 'User ID', 'Prompt', 'Completion', 'Total', 'Waktu'], $rows);
            }
        } else {
            $this->line("<fg=gray>Gunakan opsi --history untuk melihat riwayat log per transaksi.</>");
            $this->line("<fg=gray>Gunakan opsi --check=\"teks\" untuk menghitung perkiraan token.</>");
        }

        $this->newLine();
        return 0;
    }
}
