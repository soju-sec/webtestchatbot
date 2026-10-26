<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mendapatkan koneksi database yang terisolasi.
     * Jika DB_CHATBOT_CONNECTION diset, migrasi HANYA berjalan di koneksi tersebut,
     * sehingga 100% TIDAK MENGGANGGU database produksi utama.
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

        // Tabel Tiket Laporan Masalah / Kendala Pelanggan (Isolated & Safe)
        if (!$schema->hasTable($ticketsTable)) {
            $schema->create($ticketsTable, function (Blueprint $table) {
                $table->id();
                $table->string('ticket_number')->unique()->index();
                $table->string('conversation_id')->nullable()->index();
                $table->string('user_id')->index();
                $table->string('user_name')->nullable();
                $table->string('category')->default('fitur_tidak_dapat_diakses')->index();
                $table->string('feature_name')->nullable()->index();
                $table->text('description');
                $table->string('urgency')->default('tinggi'); // rendah, sedang, tinggi, darurat
                $table->string('status')->default('open')->index(); // open, investigating, resolved, closed
                $table->string('channel')->default('web');
                $table->string('jira_key')->nullable()->index(); // Contoh: SUP-12
                $table->string('jira_url')->nullable();          // Contoh: https://imtekservice.atlassian.net/browse/SUP-12
                $table->json('notified_channels')->nullable(); // e.g. {"telegram": true, "whatsapp": false}
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     * Hanya menghapus tabel tiket milik chatbot, dijamin tidak menyentuh tabel production lain.
     */
    public function down(): void
    {
        $connection = $this->getConnection();
        $schema = Schema::connection($connection);

        $ticketsTable = config('chatbot.tables.tickets', env('CHATBOT_TABLE_TICKETS', 'chatbot_tickets'));
        $schema->dropIfExists($ticketsTable);
    }
};
