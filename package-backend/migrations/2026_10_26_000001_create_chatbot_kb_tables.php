<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mendapatkan koneksi database chatbot (terisolasi jika diset di .env).
     */
    protected function getConnectionName()
    {
        return config('chatbot.database_connection', env('DB_CHATBOT_CONNECTION', null));
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = $this->getConnectionName();
        $schema = Schema::connection($connection);

        // 1. Kategori Template SOP & Solusi
        if (!$schema->hasTable('chatbot_kb_categories')) {
            $schema->create('chatbot_kb_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('icon')->default('ri-folder-line');
                $table->text('description')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // 2. Template Kasus & Solusi (Case & Solution)
        if (!$schema->hasTable('chatbot_kb_templates')) {
            $schema->create('chatbot_kb_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('category_id')->nullable()->index();
                $table->string('case_title')->index();
                $table->string('slug')->unique();
                $table->json('trigger_keywords'); // array of trigger keywords/phrases
                $table->text('solution_steps');   // panduan solusi langkah demi langkah
                $table->text('escalation_action')->nullable(); // pesan jika solusi belum menyelesaikan masalah
                $table->string('jira_issue_type')->default('Service Request'); // e.g. Service Request, Bug, Task
                $table->string('jira_priority')->default('Medium');            // Low, Medium, High, Critical
                $table->string('jira_component')->nullable();
                $table->json('quick_chips')->nullable();                       // shortcut buttons for chatbot widget
                $table->string('status')->default('published')->index();       // published, draft, archived
                $table->integer('version')->default(1);
                $table->unsignedBigInteger('usage_count')->default(0);
                $table->unsignedBigInteger('resolved_count')->default(0);
                $table->string('created_by')->default('Project Manager');
                $table->timestamps();
            });
        }

        // 3. Log Sinkronisasi & Audit Perubahan
        if (!$schema->hasTable('chatbot_kb_sync_logs')) {
            $schema->create('chatbot_kb_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('template_id')->nullable()->index();
                $table->string('action'); // created, updated, published, archived, deleted
                $table->string('performed_by')->default('Project Manager');
                $table->json('details')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = $this->getConnectionName();
        $schema = Schema::connection($connection);

        $schema->dropIfExists('chatbot_kb_sync_logs');
        $schema->dropIfExists('chatbot_kb_templates');
        $schema->dropIfExists('chatbot_kb_categories');
    }
};
