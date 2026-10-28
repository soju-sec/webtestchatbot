<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mendapatkan koneksi database yang terisolasi.
     */
    public function getConnection()
    {
        return config('chatbot.database_connection', env('DB_CHATBOT_CONNECTION', null));
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = $this->getConnection();
        $schema = Schema::connection($connection);
        $ticketsTable = config('chatbot.tables.tickets', env('CHATBOT_TABLE_TICKETS', 'chatbot_tickets'));

        if ($schema->hasTable($ticketsTable)) {
            $schema->table($ticketsTable, function (Blueprint $table) use ($schema, $ticketsTable) {
                // Kolom Pelapor & WhatsApp
                if (!$schema->hasColumn($ticketsTable, 'reporter_phone')) {
                    $table->string('reporter_phone', 30)->nullable()->index()->after('user_name');
                }

                // Kolom Spesifik Format Pelaporan Siskeudes TNT
                if (!$schema->hasColumn($ticketsTable, 'kabupaten')) {
                    $table->string('kabupaten', 100)->nullable()->index()->after('reporter_phone');
                }
                if (!$schema->hasColumn($ticketsTable, 'nama_desa')) {
                    $table->string('nama_desa', 100)->nullable()->index()->after('kabupaten');
                }
                if (!$schema->hasColumn($ticketsTable, 'tahun_anggaran')) {
                    $table->string('tahun_anggaran', 10)->nullable()->after('nama_desa');
                }
                if (!$schema->hasColumn($ticketsTable, 'no_spp')) {
                    $table->string('no_spp', 100)->nullable()->after('tahun_anggaran');
                }
                if (!$schema->hasColumn($ticketsTable, 'no_kwitansi')) {
                    $table->string('no_kwitansi', 100)->nullable()->after('no_spp');
                }
                if (!$schema->hasColumn($ticketsTable, 'kode_cms_siskeudes')) {
                    $table->string('kode_cms_siskeudes', 100)->nullable()->after('no_kwitansi');
                }
                if (!$schema->hasColumn($ticketsTable, 'kode_cms_bank')) {
                    $table->string('kode_cms_bank', 100)->nullable()->after('kode_cms_siskeudes');
                }

                // Kolom Magic Tracking Link & Penyelesaian 2-Arah
                if (!$schema->hasColumn($ticketsTable, 'tracking_token')) {
                    $table->string('tracking_token', 64)->nullable()->index()->after('channel');
                }
                if (!$schema->hasColumn($ticketsTable, 'resolution_notes')) {
                    $table->text('resolution_notes')->nullable()->after('status');
                }
                if (!$schema->hasColumn($ticketsTable, 'resolved_notified_at')) {
                    $table->timestamp('resolved_notified_at')->nullable()->after('resolution_notes');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = $this->getConnection();
        $schema = Schema::connection($connection);
        $ticketsTable = config('chatbot.tables.tickets', env('CHATBOT_TABLE_TICKETS', 'chatbot_tickets'));

        if ($schema->hasTable($ticketsTable)) {
            $schema->table($ticketsTable, function (Blueprint $table) use ($schema, $ticketsTable) {
                $columns = [
                    'reporter_phone',
                    'kabupaten',
                    'nama_desa',
                    'tahun_anggaran',
                    'no_spp',
                    'no_kwitansi',
                    'kode_cms_siskeudes',
                    'kode_cms_bank',
                    'tracking_token',
                    'resolution_notes',
                    'resolved_notified_at',
                ];

                $dropList = [];
                foreach ($columns as $col) {
                    if ($schema->hasColumn($ticketsTable, $col)) {
                        $dropList[] = $col;
                    }
                }

                if (!empty($dropList)) {
                    $table->dropColumn($dropList);
                }
            });
        }
    }
};
