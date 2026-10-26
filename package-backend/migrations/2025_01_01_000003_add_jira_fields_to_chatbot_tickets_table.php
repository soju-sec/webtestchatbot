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
                if (!$schema->hasColumn($ticketsTable, 'jira_key')) {
                    $table->string('jira_key')->nullable()->index()->after('channel');
                }
                if (!$schema->hasColumn($ticketsTable, 'jira_url')) {
                    $table->string('jira_url')->nullable()->after('jira_key');
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
                $columns = [];
                if ($schema->hasColumn($ticketsTable, 'jira_url')) {
                    $columns[] = 'jira_url';
                }
                if ($schema->hasColumn($ticketsTable, 'jira_key')) {
                    $columns[] = 'jira_key';
                }
                if (!empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
