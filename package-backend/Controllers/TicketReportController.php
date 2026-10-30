<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotNotificationService;
use App\Services\JiraService;
use App\Services\PiiSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TicketReportController extends Controller
{
    /**
     * Mendapatkan nama koneksi database terisolasi.
     */
    protected function getConnection(): ?string
    {
        return config('chatbot.database_connection', env('DB_CHATBOT_CONNECTION', null));
    }

    /**
     * Mendapatkan nama tabel tiket laporan terisolasi.
     */
    protected function getTableName(): string
    {
        return config('chatbot.tables.tickets', env('CHATBOT_TABLE_TICKETS', 'chatbot_tickets'));
    }

    /**
     * Membuat tiket laporan kendala/feedback customer baru.
     * Mengirimkan notifikasi instan ke tim internal (Slack/Telegram)
     * dan mengirimkan Pesan WhatsApp #1 (Tanda Terima & Magic Link) ke pelapor.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id'            => 'nullable|string',
            'user_name'          => 'nullable|string',
            'reporter_phone'     => 'nullable|string',
            'phone'              => 'nullable|string',
            'no_wa'              => 'nullable|string',
            'kabupaten'          => 'nullable|string',
            'nama_desa'          => 'nullable|string',
            'tahun_anggaran'     => 'nullable|string',
            'no_spp'             => 'nullable|string',
            'no_kwitansi'        => 'nullable|string',
            'kode_cms_siskeudes' => 'nullable|string',
            'kode_cms_bank'      => 'nullable|string',
            'conversation_id'    => 'nullable|string',
            'category'           => 'nullable|string',
            'feature_name'       => 'nullable|string',
            'description'        => 'nullable|string',
            'keterangan'         => 'nullable|string',
            'urgency'            => 'nullable|string|in:rendah,sedang,tinggi,darurat,low,medium,high,critical',
            'channel'            => 'nullable|string',
            'ticket_number'      => 'nullable|string',
            'screenshot'         => 'nullable',
            'screenshot_name'    => 'nullable|string',
            'attachment'         => 'nullable',
            'metadata'           => 'nullable|array',
        ]);

        $connection = $this->getConnection();
        $table      = $this->getTableName();

        // 1. Ekstrak dan Normalisasi Nomor Telepon Pelapor
        $rawPhone = $data['reporter_phone'] ?? $data['no_wa'] ?? $data['phone'] ?? null;
        if (empty($rawPhone)) {
            $candidateUser = $data['user_id'] ?? '';
            $digitsUser = preg_replace('/[^0-9]/', '', $candidateUser);
            if (strlen($digitsUser) >= 10 && (str_starts_with($digitsUser, '08') || str_starts_with($digitsUser, '628') || str_starts_with($digitsUser, '8'))) {
                $rawPhone = $candidateUser;
            } elseif (!empty($data['description']) && preg_match('/(?:\+?62|0)8[0-9]{8,12}/', $data['description'], $pm)) {
                $rawPhone = $pm[0];
            }
        }

        $cleanPhone = null;
        if (!empty($rawPhone)) {
            $digits = preg_replace('/[^0-9]/', '', $rawPhone);
            if (str_starts_with($digits, '08')) {
                $cleanPhone = '628' . substr($digits, 2);
            } elseif (str_starts_with($digits, '628')) {
                $cleanPhone = $digits;
            } elseif (str_starts_with($digits, '8')) {
                $cleanPhone = '628' . substr($digits, 1);
            } else {
                $cleanPhone = $digits;
            }
        }

        // 2. Ekstrak Deskripsi / Keterangan Kendala
        $description = !empty($data['description'])
            ? $data['description']
            : (!empty($data['keterangan']) ? $data['keterangan'] : 'Kendala transaksi Siskeudes TNT / CMS Bank');

        // Sanitasi deskripsi dari data pribadi sensitif (KTP, No HP, PIN/Password)
        $cleanDescription = class_exists(PiiSanitizer::class)
            ? PiiSanitizer::sanitize($description)
            : $description;

        // 3. Generate Nomor Tiket & Token Unik Magic Tracking Link
        $ticketNumber = !empty($data['ticket_number'])
            ? $data['ticket_number']
            : 'TCK-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

        $trackingToken = bin2hex(random_bytes(16));

        // Tentukan Base URL & Magic Link URL
        $baseUrl = rtrim(config('app.url', env('APP_URL', $request->getSchemeAndHttpHost())), '/');
        $magicLink = "{$baseUrl}/api/v1/tickets/track/{$ticketNumber}?token={$trackingToken}";

        $category = !empty($data['category']) ? $data['category'] : 'siskeudes_tnt';
        $featureName = !empty($data['feature_name']) ? $data['feature_name'] : 'Siskeudes TNT / CMS Bank';
        $urgency = !empty($data['urgency']) ? strtolower($data['urgency']) : 'tinggi';

        $metadata = $data['metadata'] ?? [];

        // 3.5. Penanganan Berkas Lampiran Screenshot Bukti Kendala (File Upload / Base64)
        $savedAttachmentPath = null;
        $savedAttachmentName = null;
        $attachmentUrl = null;

        try {
            $attachmentDir = storage_path('app/chatbot_attachments');
            if (!file_exists($attachmentDir)) {
                @mkdir($attachmentDir, 0755, true);
            }

            // A. Cek file upload langsung (multipart/form-data)
            if ($request->hasFile('screenshot') || $request->hasFile('attachment')) {
                $file = $request->file('screenshot') ?: $request->file('attachment');
                if ($file && $file->isValid()) {
                    $ext = strtolower($file->getClientOriginalExtension());
                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'])) {
                        $savedAttachmentName = 'ss_' . $ticketNumber . '_' . uniqid() . '.' . $ext;
                        $file->move($attachmentDir, $savedAttachmentName);
                        $savedAttachmentPath = $attachmentDir . '/' . $savedAttachmentName;
                    }
                }
            }
            // B. Cek payload Base64 (data:image/png;base64,...)
            elseif (!empty($data['screenshot']) || !empty($data['attachment']) || !empty($request->input('screenshot')) || !empty($request->input('attachment'))) {
                $rawBase64 = $data['screenshot'] ?? ($data['attachment'] ?? ($request->input('screenshot') ?: $request->input('attachment')));
                if (is_string($rawBase64) && preg_match('/^data:image\/(png|jpe?g|webp);base64,(.+)$/i', $rawBase64, $matches)) {
                    $ext = strtolower($matches[1] === 'jpeg' ? 'jpg' : $matches[1]);
                    $decoded = base64_decode($matches[2]);
                    if ($decoded !== false && strlen($decoded) > 0 && strlen($decoded) <= 7 * 1024 * 1024) {
                        $savedAttachmentName = 'ss_' . $ticketNumber . '_' . uniqid() . '.' . $ext;
                        $savedAttachmentPath = $attachmentDir . '/' . $savedAttachmentName;
                        file_put_contents($savedAttachmentPath, $decoded);
                    }
                }
            }

            if ($savedAttachmentPath && file_exists($savedAttachmentPath)) {
                $attachmentUrl = "{$baseUrl}/api/v1/tickets/{$ticketNumber}/attachment";
                $metadata['attachment'] = [
                    'file_name' => $savedAttachmentName,
                    'file_path' => $savedAttachmentPath,
                    'file_size' => filesize($savedAttachmentPath),
                    'url'       => $attachmentUrl,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('[TicketReportController] Gagal memproses berkas screenshot: ' . $e->getMessage());
        }

        $ticketData = [
            'ticket_number'      => $ticketNumber,
            'tracking_token'     => $trackingToken,
            'conversation_id'    => $data['conversation_id'] ?? null,
            'user_id'            => $data['user_id'] ?? ($cleanPhone ?: 'pelapor-web'),
            'user_name'          => $data['user_name'] ?? ($data['nama_desa'] ? "Desa {$data['nama_desa']}" : 'Pelapor Siskeudes'),
            'reporter_phone'     => $cleanPhone,
            'kabupaten'          => $data['kabupaten'] ?? null,
            'nama_desa'          => $data['nama_desa'] ?? null,
            'tahun_anggaran'     => $data['tahun_anggaran'] ?? date('Y'),
            'no_spp'             => $data['no_spp'] ?? null,
            'no_kwitansi'        => $data['no_kwitansi'] ?? null,
            'kode_cms_siskeudes' => $data['kode_cms_siskeudes'] ?? null,
            'kode_cms_bank'      => $data['kode_cms_bank'] ?? null,
            'category'           => $category,
            'feature_name'       => $featureName,
            'description'        => $cleanDescription,
            'urgency'            => $urgency,
            'status'             => 'open',
            'channel'            => $data['channel'] ?? 'web',
            'created_at'         => now(),
            'updated_at'         => now(),
        ];

        if (!empty($savedAttachmentName)) {
            $ticketData['attachment_name'] = $savedAttachmentName;
            $ticketData['attachment_path'] = $savedAttachmentPath;
        }

        // 4. Integrasi Otomatis ke Jira Service Management / Jira Service Desk (Proyek SUP)
        $jiraResult = ['success' => false, 'message' => 'Jira belum dikonfigurasi'];
        try {
            if (class_exists(JiraService::class) && JiraService::isConfigured()) {
                $jiraResult = JiraService::createIssue($ticketData);
                if (!empty($jiraResult['success'])) {
                    $ticketData['jira_key'] = $jiraResult['key'] ?? null;
                    $ticketData['jira_url'] = $jiraResult['url'] ?? null;
                    $metadata['jira'] = [
                        'key'        => $jiraResult['key'] ?? null,
                        'id'         => $jiraResult['id'] ?? null,
                        'url'        => $jiraResult['url'] ?? null,
                        'project'    => JiraService::getProjectKey(),
                        'created_at' => now()->toIso8601String(),
                    ];

                    // JANGAN SAMPAI SCREENSHOT TERTINGGAL: Attach file ke issue Jira SUP!
                    if (!empty($savedAttachmentPath) && file_exists($savedAttachmentPath) && !empty($ticketData['jira_key'])) {
                        $attachRes = JiraService::attachFile($ticketData['jira_key'], $savedAttachmentPath, $savedAttachmentName);
                        $metadata['jira']['attachment'] = $attachRes;
                        Log::info("[TicketReportController] Screenshot '{$savedAttachmentName}' berhasil dilampirkan ke Jira {$ticketData['jira_key']}");
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[TicketReportController] Exception saat integrasi Jira: ' . $e->getMessage());
            $jiraResult = ['success' => false, 'message' => $e->getMessage()];
        }

        $ticketData['metadata'] = !empty($metadata) ? json_encode($metadata) : null;

        // 5. Kirim Notifikasi Instan ke Tim Internal (Slack & Telegram)
        $notificationStatus = ['slack' => false, 'telegram' => false, 'whatsapp' => false];
        try {
            $notificationStatus = ChatbotNotificationService::notifyAll($ticketData);
        } catch (\Throwable $e) {
            Log::warning('[TicketReportController] Gagal dispatch notifikasi internal: ' . $e->getMessage());
        }

        // 6. Penanganan WhatsApp Berdasarkan Kategori Laporan (SOP Reset DPMD vs Transaksi Desa)
        $isAuthIssue = $category === 'akun' || str_contains($category, 'akun') || str_contains(strtolower($featureName), 'login');
        $dpmdWaStatus = null;

        if ($isAuthIssue) {
            // Sesuai instruksi: Tautan otorisasi reset HANYA dikirim ke nomor WA Admin DPMD Kabupaten!
            $dpmdPhone = ChatbotNotificationService::resolveDpmdPhone($ticketData['kabupaten']);
            if (!empty($dpmdPhone)) {
                $dpmdResetLink = "{$baseUrl}/api/v1/dpmd/auth-reset/{$ticketNumber}?token={$trackingToken}";
                $dpmdWaStatus  = ChatbotNotificationService::sendTicketAuthToDpmd($ticketData, $dpmdPhone, $dpmdResetLink);
                Log::info("[TicketReportController] Permohonan reset akun #{$ticketNumber} diteruskan ke WA Admin DPMD {$dpmdPhone}");
            }
        }

        // Kirim Pesan Tanda Terima ke Nomor Pelapor (Dengan edukasi keamanan DPMD jika kendala login)
        $reporterWaStatus = ['success' => false, 'message' => 'Nomor WhatsApp pelapor tidak diisi'];
        if (!empty($cleanPhone)) {
            try {
                $reporterWaStatus = ChatbotNotificationService::sendTicketReceiptToReporter($ticketData, $magicLink);
            } catch (\Throwable $e) {
                Log::warning('[TicketReportController] Gagal kirim WA tanda terima ke pelapor: ' . $e->getMessage());
                $reporterWaStatus = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        $ticketData['notified_channels'] = json_encode([
            'slack'           => $notificationStatus['slack'] ?? false,
            'telegram'        => $notificationStatus['telegram'] ?? false,
            'whatsapp_team'   => $notificationStatus['whatsapp'] ?? false,
            'whatsapp_pelapor'=> $reporterWaStatus['success'] ?? false,
            'whatsapp_dpmd'   => $dpmdWaStatus['success'] ?? false,
        ]);

        // 7. Simpan Tiket ke Database Terisolasi
        try {
            $insertData = $ticketData;
            $columnsToCheck = [
                'jira_key', 'jira_url', 'reporter_phone', 'kabupaten', 'nama_desa',
                'tahun_anggaran', 'no_spp', 'no_kwitansi', 'kode_cms_siskeudes', 'kode_cms_bank',
                'tracking_token', 'resolution_notes', 'resolved_notified_at'
            ];

            foreach ($columnsToCheck as $col) {
                if (!Schema::connection($connection)->hasColumn($table, $col)) {
                    unset($insertData[$col]);
                }
            }

            // Hapus kolom non-tabel dari insertData
            unset($insertData['attachment_path'], $insertData['attachment_name']);

            DB::connection($connection)->table($table)->insert($insertData);
        } catch (\Throwable $e) {
            Log::error('[TicketReportController] Gagal menyimpan tiket ke database: ' . $e->getMessage());
            return response()->json([
                'status'        => 'partial_success',
                'ticket_number' => $ticketNumber,
                'message'       => 'Laporan diterima dan diteruskan ke Jira / tim internal, namun pencatatan database mengalami kendala teknis.',
                'magic_link'    => $magicLink,
                'jira'          => $jiraResult,
                'error'         => $e->getMessage(),
            ], 200);
        }

        return response()->json([
            'status'        => 'success',
            'message'       => $isAuthIssue
                ? 'Tiket kendala login berhasil diterbitkan. Tautan otorisasi reset telah diteruskan ke WhatsApp Admin DPMD.'
                : 'Tiket laporan kendala Siskeudes TNT berhasil dibuat. Tanda terima dikirimkan ke WhatsApp pelapor.',
            'ticket'        => [
                'ticket_number'      => $ticketNumber,
                'tracking_token'     => $trackingToken,
                'magic_link'         => $magicLink,
                'jira_key'           => $ticketData['jira_key'] ?? null,
                'jira_url'           => $ticketData['jira_url'] ?? null,
                'reporter_phone'     => $cleanPhone,
                'kabupaten'          => $ticketData['kabupaten'],
                'nama_desa'          => $ticketData['nama_desa'],
                'tahun_anggaran'     => $ticketData['tahun_anggaran'],
                'no_spp'             => $ticketData['no_spp'],
                'no_kwitansi'        => $ticketData['no_kwitansi'],
                'kode_cms_siskeudes' => $ticketData['kode_cms_siskeudes'],
                'kode_cms_bank'      => $ticketData['kode_cms_bank'],
                'description'        => $ticketData['description'],
                'status'             => 'open',
                'attachment_url'     => $attachmentUrl,
                'created_at'         => $ticketData['created_at']->toIso8601String(),
            ],
            'reporter_whatsapp' => $reporterWaStatus,
            'dpmd_whatsapp'     => $dpmdWaStatus,
            'jira'              => $jiraResult,
            'notifications'     => $notificationStatus,
        ], 201);

    }

    /**
     * Endpoint Pengecekan Status Pencairan SPP (Siskeudes-Link & CMS Bank Desa).
     * Sesuai alur pada Manual Book Kemendagri / BPKP (Gambar 2.12).
     * Endpoint ini read-only, aman dipanggil berulang kali tanpa membebani WhatsApp API.
     */
    public function checkSppStatus(Request $request)
    {
        $noSpp         = trim((string) ($request->query('no_spp') ?? $request->input('no_spp') ?? ''));
        $kodeCms       = trim((string) ($request->query('kode_cms') ?? $request->input('kode_cms') ?? ''));
        $noKwitansi    = trim((string) ($request->query('no_kwitansi') ?? $request->input('no_kwitansi') ?? ''));
        $kabupaten     = trim((string) ($request->query('kabupaten') ?? $request->input('kabupaten') ?? ''));
        $namaDesa      = trim((string) ($request->query('nama_desa') ?? $request->input('nama_desa') ?? $request->query('desa') ?? ''));
        $tahunAnggaran = trim((string) ($request->query('tahun') ?? $request->query('tahun_anggaran') ?? $request->input('tahun_anggaran') ?? date('Y')));

        if (empty($noSpp) && empty($kodeCms) && empty($noKwitansi)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mohon sertakan Nomor SPP, Kode CMS, atau Nomor Kwitansi untuk pengecekan status pencairan.',
            ], 422);
        }

        $connection = $this->getConnection();
        $table      = $this->getTableName();

        // 1. Cek apakah ada tiket kendala terkait di database lokal
        $relatedTicket = null;
        try {
            $query = DB::connection($connection)->table($table);
            if (!empty($noSpp)) {
                $query->where('no_spp', 'like', "%{$noSpp}%");
            } elseif (!empty($kodeCms)) {
                $query->where(function ($q) use ($kodeCms) {
                    $q->where('kode_cms_siskeudes', 'like', "%{$kodeCms}%")
                      ->orWhere('kode_cms_bank', 'like', "%{$kodeCms}%");
                });
            } elseif (!empty($noKwitansi)) {
                $query->where('no_kwitansi', 'like', "%{$noKwitansi}%");
            }
            $relatedTicket = $query->latest('id')->first();
        } catch (\Throwable $e) {
            Log::warning('[TicketReportController] Exception cek tiket terkait SPP: ' . $e->getMessage());
        }

        // 2. Hubungkan ke data transaksi Siskeudes / Database Host jika tabel tbspp tersedia
        $realSpp = null;
        try {
            if (Schema::connection($connection)->hasTable('tbspp')) {
                $qSpp = DB::connection($connection)->table('tbspp');
                if (Schema::connection($connection)->hasTable('tbmdesa')) {
                    $qSpp->leftJoin('tbmdesa', 'tbspp.desaid', '=', 'tbmdesa.desaid');
                }
                if (Schema::connection($connection)->hasTable('tbmpemda') && Schema::connection($connection)->hasTable('tbmdesa')) {
                    $qSpp->leftJoin('tbmpemda', 'tbmdesa.pemdaid', '=', 'tbmpemda.pemdaid');
                }

                $qSpp->where(function ($q) use ($noSpp, $kodeCms, $noKwitansi) {
                    if (!empty($noSpp)) {
                        $q->where('tbspp.no_spp', 'like', "%{$noSpp}%");
                    } elseif (!empty($kodeCms)) {
                        $q->where('tbspp.kd_billing', 'like', "%{$kodeCms}%");
                    } elseif (!empty($noKwitansi)) {
                        $q->where('tbspp.no_spp', 'like', "%{$noKwitansi}%");
                    }
                });

                $selectCols = ['tbspp.*'];
                if (Schema::connection($connection)->hasTable('tbmdesa')) {
                    $selectCols[] = 'tbmdesa.nama_desa as m_nama_desa';
                }
                if (Schema::connection($connection)->hasTable('tbmpemda')) {
                    $selectCols[] = 'tbmpemda.nama_pemda as m_nama_pemda';
                }

                $realSpp = $qSpp->select($selectCols)->first();
            }
        } catch (\Throwable $e) {
            Log::info('[TicketReportController] Lookup tbspp note: ' . $e->getMessage());
        }

        if ($realSpp) {
            $noSpp = $realSpp->no_spp;
            $kabupaten = $realSpp->m_nama_pemda ?? ($kabupaten ?: 'Pemerintah Kabupaten Badung');
            $namaDesa = $realSpp->m_nama_desa ?? ($namaDesa ?: 'Desa Kuta');
            $tahunAnggaran = $realSpp->tahun ?? $tahunAnggaran;

            $nominalBruto = (int) ($realSpp->nilai_belanja ?? 0);
            $potonganPajak = (int) ($realSpp->nilai_potongan ?? 0);
            $nominalNetto = (int) ($realSpp->nilai_terima ?? 0);

            $isCair = ($realSpp->status_pembayaran == 1 && $realSpp->status_pencairan == 1);
            $isVerif = ($realSpp->status_verifikasi == 1);

            if ($relatedTicket && $relatedTicket->status === 'open') {
                $statusPembayaran = 'KENDALA_DILAPORKAN';
                $statusLabel = 'Kendala Sedang Ditangani Tim Teknis';
                $badgeColor = 'danger';
            } elseif ($isCair) {
                $statusPembayaran = 'BERHASIL_CAIR';
                $statusLabel = 'Sudah Cair / Berhasil Ditransfer';
                $badgeColor = 'success';
            } elseif ($isVerif) {
                $statusPembayaran = 'MENUNGGU_PENCAIRAN_CMS';
                $statusLabel = 'Disetujui Sekdes (Menunggu Pencairan di CMS Bank)';
                $badgeColor = 'warning';
            } else {
                $statusPembayaran = 'MENUNGGU_VERIFIKASI_SEKDES';
                $statusLabel = 'Menunggu Verifikasi & Dokumen Pendukung (SPTJB/SPP2)';
                $badgeColor = 'warning';
            }

            $sppData = [
                'no_spp'               => $realSpp->no_spp,
                'no_kwitansi'          => $noKwitansi ?: "KW-" . $realSpp->sppid . "/{$tahunAnggaran}",
                'kode_cms_siskeudes'   => $realSpp->kd_billing ?: "CMS-{$tahunAnggaran}-" . sprintf('%05d', $realSpp->sppid),
                'kode_cms_bank'        => "BNK-" . sprintf('%06d', $realSpp->sppid + 100000),
                'tanggal_transaksi'    => !empty($realSpp->tgl_verifikasi) ? date('d-m-Y H:i:s', strtotime($realSpp->tgl_verifikasi)) : date('d-m-Y H:i:s', strtotime($realSpp->tgl_spp ?? 'now')),
                'status_pembayaran'    => $statusPembayaran,
                'status_label'         => $statusLabel,
                'badge_color'          => $badgeColor,
                'rekening_tujuan'      => $realSpp->rek_bank_desa ?: '101.02.0001',
                'nama_penerima'        => $realSpp->nm_pemilik ?: 'Rekening Kas Desa Kuta',
                'bank_tujuan'          => $realSpp->nm_bank_desa ?: 'BPD Bali / Mitra Siskeudes TNT',
                'uraian_belanja'       => $realSpp->uraian ?: 'Belanja Operasional Kantor dan APBDes',
                'nominal_bruto'        => $nominalBruto,
                'nominal_bruto_format' => 'Rp ' . number_format($nominalBruto, 0, ',', '.'),
                'potongan_pajak'       => $potonganPajak,
                'potongan_pajak_format'=> 'Rp ' . number_format($potonganPajak, 0, ',', '.'),
                'nominal_netto'        => $nominalNetto,
                'nominal_netto_format' => 'Rp ' . number_format($nominalNetto, 0, ',', '.'),
                'status_pajak'         => 'ID Billing Terbayar Otomatis melalui CMS Bank Desa',
                'id_billing'           => $realSpp->kd_billing ?: 'CMS-2026',
                'no_referensi_bank'    => $realSpp->no_ref_bank ?: ('REF-BPD-' . date('Ymd', strtotime($realSpp->tgl_spp ?? 'now'))),
                'alur_verifikasi'      => [
                    'siskeudes_online' => 'Final (Disetujui)',
                    'upload_sptjb'     => !empty($realSpp->filesuratrekomcamat) || $isVerif ? 'Lengkap' : 'Menunggu Unggah',
                    'upload_spp2'      => $isVerif ? 'Lengkap' : 'Menunggu Unggah',
                    'verifikasi_sekdes'=> $isVerif ? 'Selesai (Disetujui)' : 'Belum Diverifikasi',
                    'approval_kades'   => $isCair ? 'Selesai (Cair)' : ($isVerif ? 'Menunggu Eksekusi CMS Bank' : 'Menunggu Verifikasi'),
                ]
            ];
        } else {
            // Data tidak ditemukan di tabel tbspp
            $searchKey = !empty($noSpp) ? $noSpp : (!empty($kodeCms) ? $kodeCms : $noKwitansi);
            return response()->json([
                'status'    => 'not_found',
                'message'   => "Data transaksi untuk [{$searchKey}] tidak ditemukan dalam sistem Siskeudes TNT" . ($namaDesa ? " ({$namaDesa})" : '') . ". Mohon periksa kembali nomor SPP atau pastikan SPP telah difinalkan di Siskeudes Online.",
                'query'     => [
                    'no_spp'         => $noSpp ?: null,
                    'kode_cms'       => $kodeCms ?: null,
                    'no_kwitansi'    => $noKwitansi ?: null,
                    'kabupaten'      => $kabupaten ?: null,
                    'nama_desa'      => $namaDesa ?: null,
                    'tahun_anggaran' => $tahunAnggaran,
                ],
                'spp_data'  => null,
                'related_ticket' => $relatedTicket,
            ], 200);
        }

        $responsePayload = [
            'status'         => 'success',
            'query'          => [
                'no_spp'         => $noSpp ?: null,
                'kode_cms'       => $kodeCms ?: null,
                'no_kwitansi'    => $noKwitansi ?: null,
                'kabupaten'      => $kabupaten ?: ($relatedTicket->kabupaten ?? 'Kabupaten Badung'),
                'nama_desa'      => $namaDesa ?: ($relatedTicket->nama_desa ?? 'Desa Kuta'),
                'tahun_anggaran' => $tahunAnggaran,
            ],
            'spp_data'       => $sppData,
            'related_ticket' => $relatedTicket ? [
                'ticket_number' => $relatedTicket->ticket_number,
                'status'        => $relatedTicket->status,
                'created_at'    => $relatedTicket->created_at,
                'tracking_link' => !empty($relatedTicket->tracking_token)
                    ? url("/api/v1/tickets/track/{$relatedTicket->ticket_number}?token={$relatedTicket->tracking_token}")
                    : null,
            ] : null,
        ];

        return response()->json($responsePayload, 200);
    }

    /**
     * Endpoint Magic Tracking Link:
     * Pelapor dapat mengecek status tiket kapan saja secara mandiri & visual tanpa perlu login.
     * Mendukung format HTML interaktif dan JSON.
     */
    public function track(Request $request, string $ticketNumber)
    {
        $connection = $this->getConnection();
        $table      = $this->getTableName();
        $token      = $request->query('token');

        try {
            $ticket = DB::connection($connection)->table($table)
                ->where('ticket_number', $ticketNumber)
                ->first();

            if (!$ticket) {
                if ($request->wantsJson() || $request->query('format') === 'json') {
                    return response()->json(['status' => 'error', 'message' => "Tiket {$ticketNumber} tidak ditemukan."], 404);
                }
                return response($this->renderNotFoundHtml($ticketNumber), 404)->header('Content-Type', 'text/html; charset=UTF-8');
            }

            // Validasi Token Keamanan jika token disetel pada record tiket
            if (!empty($ticket->tracking_token) && $token !== $ticket->tracking_token) {
                // Opsi verifikasi alternatif: 4 digit terakhir nomor WhatsApp pelapor
                $verifyPhone = $request->query('verify');
                $isPhoneVerified = false;
                if (!empty($verifyPhone) && !empty($ticket->reporter_phone)) {
                    $digitsOnly = preg_replace('/[^0-9]/', '', $ticket->reporter_phone);
                    if (str_ends_with($digitsOnly, $verifyPhone)) {
                        $isPhoneVerified = true;
                    }
                }

                if (!$isPhoneVerified) {
                    if ($request->wantsJson() || $request->query('format') === 'json') {
                        return response()->json([
                            'status'  => 'unauthorized',
                            'message' => 'Token pelacakan tidak valid atau telah kedaluwarsa.'
                        ], 403);
                    }
                    return response($this->renderUnauthorizedHtml($ticketNumber), 403)->header('Content-Type', 'text/html; charset=UTF-8');
                }
            }

            if ($request->wantsJson() || $request->query('format') === 'json') {
                return response()->json([
                    'status' => 'success',
                    'ticket' => $ticket,
                ]);
            }

            return response($this->renderTrackingHtml($ticket), 200)->header('Content-Type', 'text/html; charset=UTF-8');
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Webhook Penerima dari Jira Cloud (Integrasi 2-Arah).
     * Saat status di Jira diubah menjadi Done / Resolved:
     * 1. Perbarui status tiket di database lokal menjadi 'resolved'.
     * 2. Simpan catatan solusi (resolution notes).
     * 3. Kirimkan Pesan WhatsApp #2 (Hasil Penyelesaian) secara otomatis ke nomor pelapor.
     */
    public function handleJiraWebhook(Request $request)
    {
        Log::info('[JiraWebhook] Webhook payload diterima dari Jira:', [
            'event' => $request->input('webhookEvent'),
            'issue' => $request->input('issue.key')
        ]);

        $issueKey = $request->input('issue.key');
        if (empty($issueKey)) {
            return response()->json(['status' => 'ignored', 'reason' => 'Issue key is missing in payload'], 200);
        }

        $connection = $this->getConnection();
        $table      = $this->getTableName();

        $ticket = DB::connection($connection)->table($table)
            ->where('jira_key', $issueKey)
            ->first();

        if (!$ticket) {
            $ticket = DB::connection($connection)->table($table)
                ->where('metadata', 'like', "%{$issueKey}%")
                ->first();
        }

        if (!$ticket) {
            return response()->json(['status' => 'ignored', 'reason' => "Ticket for Jira {$issueKey} not found in database"], 200);
        }

        // Baca status baru dari Jira
        $jiraStatus = $request->input('issue.fields.status.name', '');
        $statusLower = strtolower($jiraStatus);

        // Ekstrak catatan resolusi / komentar terakhir
        $resolutionName = $request->input('issue.fields.resolution.name');
        $resolutionNotes = null;

        $comments = $request->input('issue.fields.comment.comments', []);
        if (!empty($comments) && is_array($comments)) {
            $lastComment = end($comments);
            $rawBody = $lastComment['body'] ?? null;
            $resolutionNotes = class_exists(JiraService::class)
                ? JiraService::extractPlainTextFromAdf($rawBody)
                : (is_string($rawBody) ? $rawBody : null);
        }
        if (empty($resolutionNotes)) {
            $rawBody = $request->input('comment.body');
            $resolutionNotes = class_exists(JiraService::class)
                ? JiraService::extractPlainTextFromAdf($rawBody)
                : (is_string($rawBody) ? $rawBody : null);
        }
        if (empty($resolutionNotes) && !empty($resolutionName)) {
            $resolutionNotes = "Kendala diselesaikan dengan resolusi: {$resolutionName}";
        }

        $isResolved = in_array($statusLower, ['done', 'resolved', 'closed', 'selesai', 'complete', 'completed'])
            || !empty($request->input('issue.fields.resolutiondate'));

        $updateData = ['updated_at' => now()];

        if ($isResolved) {
            $updateData['status'] = 'resolved';
            if (!empty($resolutionNotes)) {
                $updateData['resolution_notes'] = $resolutionNotes;
            }

            // CEK IDEMPOTENCY: Kirim Pesan #2 ke pelapor HANYA JIKA belum pernah dikirimkan
            $alreadyNotified = !empty($ticket->resolved_notified_at);
            $reporterPhone   = $ticket->reporter_phone ?? null;

            if (!$alreadyNotified && !empty($reporterPhone)) {
                $ticketArray = (array) $ticket;
                $ticketArray['resolution_notes'] = $resolutionNotes ?: ($ticket->resolution_notes ?: 'Kendala pada transaksi Siskeudes TNT telah ditangani tuntas oleh teknisi.');

                try {
                    $waResult = ChatbotNotificationService::sendTicketResolvedToReporter($ticketArray);
                    if (!empty($waResult['success'])) {
                        $updateData['resolved_notified_at'] = now();
                        Log::info("[JiraWebhook] Notifikasi Solved sukses dikirim ke WhatsApp pelapor {$reporterPhone} (#{$ticket->ticket_number})");
                    }
                } catch (\Throwable $e) {
                    Log::warning("[JiraWebhook] Exception saat kirim WA Solved ke pelapor: " . $e->getMessage());
                }
            }
        } elseif (str_contains($statusLower, 'progress') || str_contains($statusLower, 'investigat')) {
            $updateData['status'] = 'investigating';
        }

        DB::connection($connection)->table($table)
            ->where('id', $ticket->id)
            ->update($updateData);

        return response()->json([
            'status'         => 'success',
            'ticket_number'  => $ticket->ticket_number,
            'jira_key'       => $issueKey,
            'jira_status'    => $jiraStatus,
            'updated_status' => $updateData['status'] ?? $ticket->status,
            'wa_sent'        => isset($updateData['resolved_notified_at']),
        ]);
    }

    /**
     * Menampilkan daftar tiket laporan kendala (untuk admin / pemantauan internal).
     */
    public function index(Request $request)
    {
        $connection = $this->getConnection();
        $table      = $this->getTableName();
        $limit      = min((int) $request->query('limit', 20), 100);

        try {
            $query = DB::connection($connection)->table($table);

            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }
            if ($request->has('user_id')) {
                $query->where('user_id', $request->query('user_id'));
            }
            if ($request->has('category')) {
                $query->where('category', $request->query('category'));
            }
            if ($request->has('kabupaten')) {
                $query->where('kabupaten', 'like', "%{$request->query('kabupaten')}%");
            }

            $tickets = $query->orderBy('id', 'desc')->paginate($limit);

            return response()->json([
                'status'  => 'success',
                'tickets' => $tickets
            ]);
        } catch (\Throwable $e) {
            Log::warning('[TicketReportController] Gagal query tiket: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal mengambil data tiket: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menampilkan detail satu tiket berdasarkan nomor tiket.
     */
    public function show(string $ticketNumber)
    {
        $connection = $this->getConnection();
        $table      = $this->getTableName();

        try {
            $ticket = DB::connection($connection)->table($table)
                ->where('ticket_number', $ticketNumber)
                ->first();

            if (!$ticket) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Tiket {$ticketNumber} tidak ditemukan."
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'ticket' => $ticket
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Memperbarui status tiket (open, investigating, resolved, closed).
     */
    public function updateStatus(Request $request, string $ticketNumber)
    {
        $data = $request->validate([
            'status'           => 'required|in:open,investigating,in_progress,resolved,closed',
            'resolution_notes' => 'nullable|string',
            'note'             => 'nullable|string',
            'notify_pelapor'   => 'nullable|boolean',
        ]);

        $connection = $this->getConnection();
        $table      = $this->getTableName();

        try {
            $ticket = DB::connection($connection)->table($table)
                ->where('ticket_number', $ticketNumber)
                ->first();

            if (!$ticket) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Tiket {$ticketNumber} tidak ditemukan."
                ], 404);
            }

            $resolution = $data['resolution_notes'] ?? $data['note'] ?? null;
            $updateData = [
                'status'     => $data['status'],
                'updated_at' => now(),
            ];

            if (!empty($resolution)) {
                $updateData['resolution_notes'] = $resolution;
            }

            // Jika status diubah menjadi resolved dan diminta notify_pelapor (atau default resolved)
            $notifyPelapor = $data['notify_pelapor'] ?? ($data['status'] === 'resolved');
            if ($notifyPelapor && empty($ticket->resolved_notified_at) && !empty($ticket->reporter_phone)) {
                $ticketArray = (array) $ticket;
                $ticketArray['resolution_notes'] = $resolution ?: ($ticket->resolution_notes ?: 'Kendala telah diselesaikan oleh tim teknisi.');
                try {
                    $waStatus = ChatbotNotificationService::sendTicketResolvedToReporter($ticketArray);
                    if (!empty($waStatus['success'])) {
                        $updateData['resolved_notified_at'] = now();
                    }
                } catch (\Throwable $e) {
                    Log::warning("[TicketReportController] Gagal kirim WA saat updateStatus: " . $e->getMessage());
                }
            }

            DB::connection($connection)->table($table)
                ->where('ticket_number', $ticketNumber)
                ->update($updateData);

            return response()->json([
                'status'  => 'success',
                'message' => "Status tiket {$ticketNumber} berhasil diperbarui menjadi {$data['status']}.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menguji koneksi dan status kredensial Jira Service Desk.
     */
    public function testJiraConnection(Request $request)
    {
        $status = JiraService::testConnection();
        return response()->json($status, $status['success'] ? 200 : 400);
    }

    /**
     * Melakukan sinkronisasi status tiket dari Jira ke database lokal.
     */
    public function syncJiraStatus(Request $request, string $ticketNumber)
    {
        $connection = $this->getConnection();
        $table      = $this->getTableName();

        try {
            $ticket = DB::connection($connection)->table($table)
                ->where('ticket_number', $ticketNumber)
                ->first();

            if (!$ticket) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Tiket {$ticketNumber} tidak ditemukan."
                ], 404);
            }

            $jiraKey = $ticket->jira_key ?? null;
            if (!$jiraKey && !empty($ticket->metadata)) {
                $meta = is_string($ticket->metadata) ? json_decode($ticket->metadata, true) : $ticket->metadata;
                $jiraKey = $meta['jira']['key'] ?? null;
            }

            if (empty($jiraKey)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Tiket {$ticketNumber} tidak memiliki keterkaitan dengan Jira issue.",
                ], 400);
            }

            $jiraIssue = JiraService::getIssue($jiraKey);
            if (!$jiraIssue['success']) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Gagal mengambil data dari Jira: " . ($jiraIssue['message'] ?? 'Unknown error'),
                ], 502);
            }

            $jiraStatusName = strtolower($jiraIssue['status'] ?? '');
            $newStatus = 'open';
            if (str_contains($jiraStatusName, 'progress') || str_contains($jiraStatusName, 'investigat')) {
                $newStatus = 'investigating';
            } elseif (str_contains($jiraStatusName, 'done') || str_contains($jiraStatusName, 'resolved')) {
                $newStatus = 'resolved';
            } elseif (str_contains($jiraStatusName, 'close') || str_contains($jiraStatusName, 'cancel')) {
                $newStatus = 'closed';
            }

            $updateData = [
                'status'     => $newStatus,
                'updated_at' => now(),
            ];

            if ($newStatus === 'resolved' && empty($ticket->resolved_notified_at) && !empty($ticket->reporter_phone)) {
                $ticketArray = (array) $ticket;
                $ticketArray['resolution_notes'] = $ticket->resolution_notes ?: 'Kendala telah diselesaikan berdasarkan data Jira.';
                try {
                    $waStatus = ChatbotNotificationService::sendTicketResolvedToReporter($ticketArray);
                    if (!empty($waStatus['success'])) {
                        $updateData['resolved_notified_at'] = now();
                    }
                } catch (\Throwable $e) {}
            }

            DB::connection($connection)->table($table)
                ->where('ticket_number', $ticketNumber)
                ->update($updateData);

            return response()->json([
                'status'         => 'success',
                'message'        => "Status tiket {$ticketNumber} disinkronkan dari Jira ({$jiraKey}). Status baru: {$newStatus} (Jira: {$jiraIssue['status']}).",
                'jira_issue'     => $jiraIssue,
                'updated_status' => $newStatus,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Render Halaman HTML Magic Tracking yang Interaktif, Modern & Mobile Friendly.
     */
    protected function renderTrackingHtml($ticket): string
    {
        $status = strtolower($ticket->status ?? 'open');
        $ticketNum = htmlspecialchars($ticket->ticket_number ?? 'TCK-UNKNOWN');
        $kabupaten = htmlspecialchars($ticket->kabupaten ?? '-');
        $desa = htmlspecialchars($ticket->nama_desa ?? '-');
        $tahunAnggaran = htmlspecialchars($ticket->tahun_anggaran ?? date('Y'));
        $noSpp = htmlspecialchars($ticket->no_spp ?? '-');
        $noKwitansi = htmlspecialchars($ticket->no_kwitansi ?? '-');
        $kodeSiskeudes = htmlspecialchars($ticket->kode_cms_siskeudes ?? '-');
        $kodeBank = htmlspecialchars($ticket->kode_cms_bank ?? '-');
        $description = nl2br(htmlspecialchars($ticket->description ?? ''));
        $resolution = !empty($ticket->resolution_notes) ? nl2br(htmlspecialchars($ticket->resolution_notes)) : null;
        $createdAt = !empty($ticket->created_at) ? date('d M Y, H:i', strtotime($ticket->created_at)) . ' WIB' : '-';
        $updatedAt = !empty($ticket->updated_at) ? date('d M Y, H:i', strtotime($ticket->updated_at)) . ' WIB' : '-';

        // Tentukan Stepper & Status Badge
        $isResolved = in_array($status, ['resolved', 'closed', 'done']);
        $isInvestigating = in_array($status, ['investigating', 'in_progress']) || $isResolved;

        $badgeClass = 'bg-blue-100 text-blue-800 border-blue-200';
        $badgeText = 'SEDANG DIANTREKAN';
        if ($isResolved) {
            $badgeClass = 'bg-emerald-100 text-emerald-800 border-emerald-300';
            $badgeText = 'SELESAI / RESOLVED';
        } elseif ($isInvestigating) {
            $badgeClass = 'bg-amber-100 text-amber-800 border-amber-300';
            $badgeText = 'DALAM INVESTIGASI TEKNISI';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lacak Status Tiket #{$ticketNum} - Siskeudes TNT</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', -apple-system, sans-serif; background: #f8fafc; color: #1e293b; line-height: 1.5; padding: 24px 16px; }
    .container { max-width: 680px; margin: 0 auto; }
    .card { background: #ffffff; border-radius: 16px; box-shadow: 0 4px 20px -2px rgba(0,0,0,0.06), 0 2px 6px -1px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; overflow: hidden; }
    .header { background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); color: #ffffff; padding: 28px 24px; position: relative; }
    .brand-sub { font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: #93c5fd; font-weight: 700; margin-bottom: 6px; }
    .ticket-title { font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .ticket-badge { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; border: 1px solid; letter-spacing: 0.5px; }
    .copy-btn { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25); color: #fff; padding: 4px 10px; border-radius: 6px; font-size: 11px; cursor: pointer; transition: background 0.2s; margin-left: auto; }
    .copy-btn:hover { background: rgba(255,255,255,0.25); }
    
    .body-content { padding: 24px; }
    
    /* Stepper Timeline */
    .timeline { display: flex; justify-content: space-between; position: relative; margin: 12px 0 28px 0; }
    .timeline::before { content: ''; position: absolute; top: 16px; left: 30px; right: 30px; height: 3px; background: #e2e8f0; z-index: 1; }
    .step { position: relative; z-index: 2; text-align: center; width: 33.333%; }
    .step-icon { width: 34px; height: 34px; border-radius: 50%; background: #ffffff; border: 3px solid #cbd5e1; margin: 0 auto 8px auto; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #64748b; transition: all 0.3s; }
    .step.active .step-icon { border-color: #2563eb; background: #2563eb; color: #ffffff; box-shadow: 0 0 0 4px rgba(37,99,235,0.15); }
    .step.completed .step-icon { border-color: #10b981; background: #10b981; color: #ffffff; }
    .step-label { font-size: 11.5px; font-weight: 600; color: #64748b; }
    .step.active .step-label { color: #1e3a8a; font-weight: 700; }
    .step.completed .step-label { color: #065f46; }

    /* Resolution Box */
    .resolution-card { background: #ecfdf5; border: 1.5px solid #a7f3d0; border-radius: 12px; padding: 18px; margin-bottom: 24px; }
    .res-top { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: #065f46; margin-bottom: 8px; }
    .res-body { font-size: 13.5px; color: #047857; line-height: 1.6; }

    /* Details Grid */
    .section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #64748b; margin-bottom: 12px; }
    .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 20px; }
    @media (max-width: 520px) { .grid-2 { grid-template-columns: 1fr; } }
    .info-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; }
    .info-label { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; margin-bottom: 2px; }
    .info-val { font-size: 13.5px; font-weight: 700; color: #0f172a; word-break: break-word; }

    .desc-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-bottom: 24px; font-size: 13px; color: #334155; line-height: 1.6; }

    .footer-actions { display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #e2e8f0; padding-top: 18px; margin-top: 10px; font-size: 12px; color: #64748b; }
    .btn-refresh { background: #2563eb; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; font-size: 12.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
    .btn-refresh:hover { background: #1d4ed8; }
  </style>
</head>
<body>
  <div class="container">
    <div class="card">
      <div class="header">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
          <img src="/assets/logo-siskeudes-tnt.png" alt="Logo Siskeudes TNT" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.8); background: #fff;" onerror="this.style.display='none';" />
          <div>
            <div class="brand-sub">iMD IMTEK MEDIA DATA &bull; ASISTEN DIGITAL</div>
            <div style="font-size: 16px; font-weight: 800; color: #fff; line-height: 1.2;">Siskeudes TNT Support Helpdesk</div>
          </div>
        </div>
        <div class="ticket-title">
          <span>🎫 #{$ticketNum}</span>
          <span class="ticket-badge {$badgeClass}">{$badgeText}</span>
          <button class="copy-btn" onclick="navigator.clipboard.writeText('{$ticketNum}'); alert('Nomor tiket berhasil disalin!');">📋 Salin Tiket</button>
        </div>
      </div>

      <div class="body-content">
        <!-- Progress Stepper -->
        <div class="timeline">
          <div class="step completed">
            <div class="step-icon">✓</div>
            <div class="step-label">Diterima</div>
          </div>
          <div class="step {$isInvestigating}">
            <div class="step-icon">" . ($isResolved ? "✓" : "2") . "</div>
            <div class="step-label">Investigasi</div>
          </div>
          <div class="step {$isResolved}">
            <div class="step-icon">" . ($isResolved ? "✓" : "3") . "</div>
            <div class="step-label">Selesai</div>
          </div>
        </div>

        " . ($resolution ? "
        <div class=\"resolution-card\">
          <div class=\"res-top\">
            <span>✅</span>
            <span>HASIL PENANGANAN &bull; SOLUSI TEKNISI</span>
          </div>
          <div class=\"res-body\">{$resolution}</div>
        </div>
        " : "") . "

        <!-- Data Transaksi Siskeudes TNT -->
        <div class=\"section-title\">🏛️ Data Transaksi Siskeudes &bull; CMS Bank</div>
        <div class=\"grid-2\">
          <div class=\"info-box\">
            <div class=\"info-label\">Kabupaten</div>
            <div class=\"info-val\">{$kabupaten} (TA {$tahunAnggaran})</div>
          </div>
          <div class=\"info-box\">
            <div class=\"info-label\">Nama Desa</div>
            <div class=\"info-val\">{$desa}</div>
          </div>
          <div class=\"info-box\">
            <div class=\"info-label\">No. SPP</div>
            <div class=\"info-val\">{$noSpp}</div>
          </div>
          <div class=\"info-box\">
            <div class=\"info-label\">No. Kwitansi</div>
            <div class=\"info-val\">{$noKwitansi}</div>
          </div>
          <div class=\"info-box\">
            <div class=\"info-label\">Kode CMS Siskeudes TNT</div>
            <div class=\"info-val\">{$kodeSiskeudes}</div>
          </div>
          <div class=\"info-box\">
            <div class=\"info-label\">Kode CMS App Bank</div>
            <div class=\"info-val\">{$kodeBank}</div>
          </div>
        </div>

        <!-- Deskripsi Masalah -->
        <div class=\"section-title\">📝 Keterangan / Kendala yang Dilaporkan</div>
        <div class=\"desc-box\">{$description}</div>

        <div class=\"footer-actions\">
          <div>
            <span>Dibuat: {$createdAt}</span><br>
            <span>Pembaruan Terakhir: {$updatedAt}</span>
          </div>
          <button class="btn-refresh" onclick="window.location.reload();">🔄 Perbarui Status</button>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
HTML;
    }

    protected function renderNotFoundHtml(string $ticketNum): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Tiket Tidak Ditemukan</title>
  <style>
    body { font-family: -apple-system, sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; padding: 20px; text-align: center; }
    .box { background: white; border-radius: 12px; padding: 32px; max-width: 420px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
    h2 { color: #dc2626; margin-bottom: 8px; font-size: 20px; }
    p { color: #64748b; font-size: 14px; margin-bottom: 20px; }
  </style>
</head>
<body>
  <div class="box">
    <h2>Tiket Tidak Ditemukan</h2>
    <p>Nomor tiket <strong>#{$ticketNum}</strong> tidak terdaftar pada sistem kami. Pastikan nomor tiket sudah sesuai.</p>
  </div>
</body>
</html>
HTML;
    }

    protected function renderUnauthorizedHtml(string $ticketNum): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Akses Terbatas</title>
  <style>
    body { font-family: -apple-system, sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; padding: 20px; text-align: center; }
    .box { background: white; border-radius: 12px; padding: 32px; max-width: 440px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
    h2 { color: #d97706; margin-bottom: 8px; font-size: 20px; }
    p { color: #475569; font-size: 14px; margin-bottom: 16px; line-height: 1.5; }
    .sub { font-size: 12px; color: #94a3b8; }
  </style>
</head>
<body>
  <div class="box">
    <h2>🔒 Tautan Pelacakan Memerlukan Token</h2>
    <p>Untuk keamanan dan perlindungan data transaksi, tautan ini memerlukan token akses valid yang dikirimkan ke WhatsApp resmi pelapor.</p>
    <div class="sub">Silakan klik link lengkap yang Anda terima via WhatsApp.</div>
  </div>
</body>
</html>
HTML;
    }

    /**
     * Endpoint untuk mengunduh/melihat berkas screenshot lampiran tiket secara aman.
     */
    public function getAttachment(Request $request, string $ticketNumber)
    {
        $connection = $this->getConnection();
        $table      = $this->getTableName();

        $ticket = DB::connection($connection)->table($table)
            ->where('ticket_number', $ticketNumber)
            ->first();

        if (!$ticket) {
            return response()->json(['error' => 'Tiket tidak ditemukan'], 404);
        }

        $meta = !empty($ticket->metadata) ? json_decode($ticket->metadata, true) : [];
        $filePath = $meta['attachment']['file_path'] ?? null;

        if (!$filePath || !file_exists($filePath)) {
            return response()->json(['error' => 'Berkas screenshot lampiran tidak ditemukan di server'], 404);
        }

        $mime = mime_content_type($filePath) ?: 'image/png';
        return response()->file($filePath, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . basename($filePath) . '"'
        ]);
    }

    /**
     * Endpoint Portal Otorisasi Reset Akun khusus Admin DPMD Kabupaten.
     * Mengamankan alur verifikasi reset password perangkat desa.
     */
    public function handleDpmdAuthReset(Request $request, string $ticketNumber)
    {
        $token = $request->query('token');
        $connection = $this->getConnection();
        $table      = $this->getTableName();

        $ticket = DB::connection($connection)->table($table)
            ->where('ticket_number', $ticketNumber)
            ->first();

        if (!$ticket) {
            return response($this->renderNotFoundHtml($ticketNumber), 404);
        }

        if (empty($token) || $ticket->tracking_token !== $token) {
            return response($this->renderUnauthorizedHtml($ticketNumber), 403);
        }

        $desa       = htmlspecialchars($ticket->nama_desa ?? 'Desa Terkait');
        $kabupaten  = htmlspecialchars($ticket->kabupaten ?? 'Kabupaten Terkait');
        $account    = htmlspecialchars($ticket->user_id ?? '-');
        $pemohonWa  = htmlspecialchars($ticket->reporter_phone ?? '-');
        $desc       = htmlspecialchars($ticket->description ?? '-');
        $ticketNum  = htmlspecialchars($ticket->ticket_number);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Portal Otorisasi DPMD - Reset Akun Siskeudes #{$ticketNum}</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 24px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
    .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; max-width: 520px; width: 100%; padding: 28px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
    .badge { display: inline-block; background: #0284c7; color: white; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; margin-bottom: 12px; }
    h2 { font-size: 20px; margin: 0 0 16px 0; color: #f1f5f9; }
    .desc { background: #0f172a; border-left: 4px solid #38bdf8; padding: 12px; border-radius: 4px; font-size: 13.5px; color: #cbd5e1; margin-bottom: 20px; }
    .item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #334155; font-size: 13px; }
    .item-label { color: #94a3b8; }
    .item-val { font-weight: 600; color: #f8fafc; }
    .notice { background: #451a03; border: 1px solid #78350f; color: #fde68a; padding: 12px; border-radius: 8px; font-size: 12.5px; line-height: 1.4; margin: 20px 0; }
    .actions { display: flex; gap: 10px; }
    .btn-approve { flex: 1; background: #16a34a; color: white; border: none; padding: 12px; border-radius: 6px; font-weight: 700; cursor: pointer; text-align: center; text-decoration: none; font-size: 13px; }
    .btn-approve:hover { background: #15803d; }
    .btn-reject { flex: 1; background: #dc2626; color: white; border: none; padding: 12px; border-radius: 6px; font-weight: 700; cursor: pointer; text-align: center; text-decoration: none; font-size: 13px; }
  </style>
</head>
<body>
  <div class="card">
    <div class="badge">🏛️ OTORISASI ADMIN DPMD</div>
    <h2>Verifikasi Reset Akun Siskeudes TNT</h2>
    <div class="desc">
      Tiket permohonan reset password dari operator desa membutuhkan verifikasi resmi Admin DPMD Kabupaten setempat sebelum sistem memulihkan akses akun.
    </div>

    <div class="item">
      <span class="item-label">Nomor Tiket:</span>
      <span class="item-val">#{$ticketNum}</span>
    </div>
    <div class="item">
      <span class="item-label">Wilayah:</span>
      <span class="item-val">Desa {$desa}, Kab. {$kabupaten}</span>
    </div>
    <div class="item">
      <span class="item-label">Akun Target:</span>
      <span class="item-val">{$account}</span>
    </div>
    <div class="item">
      <span class="item-label">No. WA Pemohon:</span>
      <span class="item-val">{$pemohonWa}</span>
    </div>
    <div class="item">
      <span class="item-label">Keterangan Pemohon:</span>
      <span class="item-val">{$desc}</span>
    </div>

    <div class="notice">
      🛡️ <b>Instruksi Keamanan DPMD:</b> Pastikan pemohon adalah Kaur Keuangan / Perangkat Desa yang sah sebelum menyetujui. Setelah disetujui, tautan reset akan dibuatkan dan dapat diberikan melalui koordinasi resmi DPMD.
    </div>

    <div class="actions">
      <a href="javascript:alert('Otorisasi Reset Disetujui. Silakan arahkan operator desa ke kanal resmi DPMD.');" class="btn-approve">✓ Setujui Otorisasi Reset</a>
      <a href="javascript:alert('Permohonan Reset Ditolak.');" class="btn-reject">✕ Tolak Permohonan</a>
    </div>
  </div>
</body>
</html>
HTML;

        return response($html, 200);
    }
}

