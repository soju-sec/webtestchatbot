<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menggunakan koneksi terisolasi jika DB_CHATBOT_CONNECTION dikonfigurasi.
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

        $messagesTable = config('chatbot.tables.messages', env('CHATBOT_TABLE_MESSAGES', 'chatbot_conversation_messages'));
        $tokensTable   = config('chatbot.tables.tokens', env('CHATBOT_TABLE_TOKENS', 'chatbot_token_usages'));

        if (!$schema->hasTable($messagesTable)) {
            $schema->create($messagesTable, function (Blueprint $table) {
                $table->id();
                $table->string('conversation_id')->index();
                $table->string('user_id')->index();
                $table->string('role'); // user, assistant
                $table->text('message');
                $table->string('channel')->default('web');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable($tokensTable)) {
            $schema->create($tokensTable, function (Blueprint $table) {
                $table->id();
                $table->string('conversation_id')->nullable()->index();
                $table->string('user_id')->nullable()->index();
                $table->string('model')->default('gemini-2.5-flash');
                $table->unsignedInteger('prompt_tokens')->default(0);
                $table->unsignedInteger('completion_tokens')->default(0);
                $table->unsignedInteger('total_tokens')->default(0);
                $table->string('channel')->default('web');
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
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

        $messagesTable = config('chatbot.tables.messages', env('CHATBOT_TABLE_MESSAGES', 'chatbot_conversation_messages'));
        $tokensTable   = config('chatbot.tables.tokens', env('CHATBOT_TABLE_TOKENS', 'chatbot_token_usages'));

        $schema->dropIfExists($tokensTable);
        $schema->dropIfExists($messagesTable);
    }
};
