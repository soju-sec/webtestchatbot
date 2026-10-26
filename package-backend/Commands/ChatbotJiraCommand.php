<?php

namespace App\Console\Commands;

use App\Services\JiraService;
use Illuminate\Console\Command;

class ChatbotJiraCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chatbot:jira
                            {--test : Uji koneksi ke Jira Cloud instance (imtekservice.atlassian.net)}
                            {--create-test-issue : Buat satu issue uji coba di project SUP}
                            {--issue= : Tampilkan detail issue Jira tertentu (misal: SUP-1)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kelola dan uji integrasi Jira Service Management (Proyek SUP)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>==============================================================</>');
        $this->line('<fg=cyan;options=bold>   JIRA SERVICE MANAGEMENT INTEGRATION - PROJECT SUP          </>');
        $this->line('<fg=cyan;options=bold>==============================================================</>');
        $this->newLine();

        $baseUrl    = JiraService::getBaseUrl();
        $projectKey = JiraService::getProjectKey();
        $userEmail  = JiraService::getUserEmail();
        $isConfigured = JiraService::isConfigured();

        $this->table(
            ['Parameter', 'Nilai / Status'],
            [
                ['Jira Instance URL', $baseUrl],
                ['Target Project Key', "<options=bold>{$projectKey}</>"],
                ['Atlassian User Email', !empty($userEmail) ? $userEmail : '<fg=yellow>(Belum diisi di .env)</>'],
                ['Jira API Token', !empty(JiraService::getApiToken()) ? '<fg=green>TERPASANG (Masked)</>' : '<fg=red>BELUM TERISI</>'],
                ['Status Konfigurasi', $isConfigured ? '<fg=green;options=bold>AKTIF & SIAP</>' : '<fg=yellow>KREDENSIAL BELUM LENGKAP</>'],
            ]
        );

        // 1. Cek Issue Tertentu
        $issueKey = $this->option('issue');
        if (!empty($issueKey)) {
            $this->info("Mengambil data tiket Jira: {$issueKey}...");
            $issue = JiraService::getIssue($issueKey);
            if ($issue['success']) {
                $this->table(
                    ['Field', 'Nilai'],
                    [
                        ['Issue Key', $issue['key']],
                        ['Summary', $issue['summary']],
                        ['Status', "<options=bold>{$issue['status']}</>"],
                        ['Priority', $issue['priority']],
                        ['Issue Type', $issue['issuetype']],
                        ['Assignee', $issue['assignee']],
                        ['Browse URL', $issue['browse_url']],
                    ]
                );
            } else {
                $this->error("Gagal: " . ($issue['message'] ?? 'Tidak ditemukan'));
            }
            return 0;
        }

        // 2. Buat Issue Uji Coba
        if ($this->option('create-test-issue')) {
            $this->info("Mencoba membuat tiket uji coba di Jira ({$baseUrl}/projects/{$projectKey})...");
            $testTicket = [
                'ticket_number'   => 'TCK-' . date('Ymd') . '-TEST',
                'user_id'         => 'TEST-ADMIN',
                'user_name'       => 'System Verification',
                'feature_name'    => 'Verifikasi Integrasi Jira Service Desk',
                'category'        => 'bug_error',
                'urgency'         => 'sedang',
                'description'     => 'Ini adalah pengujian otomatis pembuatan tiket dari Universal Chatbot ke Jira Service Management.',
                'conversation_id' => 'conv-test-' . substr(md5((string) time()), 0, 8),
                'channel'         => 'cli',
            ];

            $result = JiraService::createIssue($testTicket);
            if ($result['success']) {
                $this->info("✓ BERHASIL MEMBUAT ISSUE JIRA!");
                $this->line("  Key : <fg=green;options=bold>{$result['key']}</>");
                $this->line("  URL : <fg=cyan>{$result['url']}</>");
            } else {
                $this->error("✗ GAGAL MEMBUAT ISSUE JIRA:");
                $this->line("  " . ($result['message'] ?? 'Unknown error'));
            }
            return 0;
        }

        // 3. Test Koneksi (Default jika opsi --test atau jika dijalankan langsung)
        $this->info("Menguji koneksi ke Atlassian Jira Cloud REST API...");
        $test = JiraService::testConnection();

        if ($test['success']) {
            $this->info("✓ KONEKSI KE JIRA SERVICE MANAGEMENT BERHASIL!");
            $this->line("  Instance     : <fg=green>{$test['instance']}</>");
            $this->line("  Nama Proyek  : <options=bold>{$test['project_name']} ({$test['project_key']})</>");
            $this->line("  Tipe Proyek  : {$test['project_type']}");
            $this->line("  Akun User    : {$test['user']['displayName']} ({$test['user']['emailAddress']})");

            $this->newLine();
            $this->line("<comment>Tipe Issue yang Tersedia di Project {$projectKey}:</comment>");
            $typesTable = [];
            foreach ($test['available_issue_types'] as $it) {
                $typesTable[] = [$it['name'], $it['description'] ?: '-', $it['subtask'] ? 'Subtask' : 'Standard'];
            }
            $this->table(['Nama Issue Type', 'Deskripsi', 'Tipe'], $typesTable);
        } else {
            $this->error("✗ KONEKSI JIRA GAGAL:");
            $this->line("  Pesan: " . ($test['message'] ?? 'Error tidak diketahui'));
            if (!empty($test['help'])) {
                $this->line("  Bantuan: <fg=yellow>{$test['help']}</>");
            }
        }

        $this->newLine();
        return 0;
    }
}
